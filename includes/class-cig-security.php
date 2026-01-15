<?php
/**
 * Security helpers for nonce/capability/XSS hardening
 *
 * @package CIG
 * @since 3.0.0
 * @updated 5.0.0 - Added granular capability system
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Security {

    /**
     * Custom CIG capabilities
     * @var array
     */
    private static $cig_caps = [
        'cig_view_invoices',      // View invoice list and details
        'cig_create_invoices',    // Create new invoices
        'cig_edit_invoices',      // Edit existing invoices
        'cig_delete_invoices',    // Delete invoices
        'cig_edit_completed',     // Edit completed/locked invoices
        'cig_view_statistics',    // View statistics dashboard
        'cig_manage_migration',   // Access migration tools
        'cig_manage_settings',    // Manage plugin settings
    ];

    /**
     * Initialize capabilities (run on plugin activation)
     */
    public static function init_capabilities() {
        $admin = get_role('administrator');
        $shop_manager = get_role('shop_manager');

        if ($admin) {
            foreach (self::$cig_caps as $cap) {
                $admin->add_cap($cap, true);
            }
        }

        if ($shop_manager) {
            // Shop managers get most capabilities except migration and settings
            $shop_manager_caps = [
                'cig_view_invoices',
                'cig_create_invoices',
                'cig_edit_invoices',
                'cig_view_statistics',
            ];
            foreach ($shop_manager_caps as $cap) {
                $shop_manager->add_cap($cap, true);
            }
        }
    }

    /**
     * Check if user has CIG capability with fallback to WooCommerce capabilities
     *
     * @param string $cig_cap CIG-specific capability
     * @param string $fallback_cap Fallback capability (default: manage_woocommerce)
     * @return bool
     */
    public function user_can($cig_cap, $fallback_cap = 'manage_woocommerce') {
        // Check CIG-specific capability first
        if (current_user_can($cig_cap)) {
            return true;
        }
        
        // Fallback to WooCommerce capability
        if (current_user_can($fallback_cap)) {
            return true;
        }

        // Administrators always have access
        if (current_user_can('administrator')) {
            return true;
        }

        return false;
    }

    /**
     * Verify AJAX nonce and capability
     *
     * @param string $action Nonce action key
     * @param string $nonce_field Field name in request (default 'nonce')
     * @param string $cap Capability required (default 'manage_woocommerce')
     */
    public function verify_ajax_request($action, $nonce_field = 'nonce', $cap = 'manage_woocommerce') {
        $nonce = isset($_REQUEST[$nonce_field]) ? sanitize_text_field(wp_unslash($_REQUEST[$nonce_field])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 400);
        }
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }
        return true;
    }

    /**
     * Verify AJAX request with CIG-specific capability and fallback
     *
     * @param string $action Nonce action key
     * @param string $cig_cap CIG-specific capability
     * @param string $nonce_field Field name in request
     * @param string $fallback_cap Fallback capability
     */
    public function verify_ajax_with_cap($action, $cig_cap, $nonce_field = 'nonce', $fallback_cap = 'manage_woocommerce') {
        $nonce = isset($_REQUEST[$nonce_field]) ? sanitize_text_field(wp_unslash($_REQUEST[$nonce_field])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 400);
        }
        if (!$this->user_can($cig_cap, $fallback_cap)) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }
        return true;
    }

    /**
     * Check a capability, else wp_die
     */
    public function require_cap($cap = 'manage_woocommerce') {
        if (!current_user_can($cap)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'cig'), 403);
        }
        return true;
    }

    /**
     * Check if user can edit a specific invoice
     *
     * @param int $invoice_id Invoice ID or Post ID
     * @return bool
     */
    public function can_edit_invoice($invoice_id) {
        // Administrators can always edit
        if (current_user_can('administrator')) {
            return true;
        }

        // Check if user has general edit capability
        if (!$this->user_can('cig_edit_invoices')) {
            return false;
        }

        // Check if invoice is completed/locked
        $lifecycle_status = get_post_meta($invoice_id, '_cig_lifecycle_status', true);
        if ($lifecycle_status === 'completed') {
            // Only users with cig_edit_completed can edit completed invoices
            return $this->user_can('cig_edit_completed', 'administrator');
        }

        return true;
    }

    /**
     * Check if user is owner of the invoice
     *
     * @param int $invoice_id Invoice ID or Post ID
     * @return bool
     */
    public function is_invoice_owner($invoice_id) {
        $post = get_post($invoice_id);
        if (!$post) {
            return false;
        }
        return (int)$post->post_author === get_current_user_id();
    }

    /**
     * Deep esc_html for output safety
     */
    public function esc_html_deep($data) {
        if (is_array($data)) {
            return array_map([$this, 'esc_html_deep'], $data);
        }
        if (is_object($data)) {
            foreach ($data as $k => $v) {
                $data->$k = $this->esc_html_deep($v);
            }
            return $data;
        }
        return is_scalar($data) ? esc_html($data) : $data;
    }

    /**
     * Deep sanitize text for input
     */
    public function sanitize_text_deep($data) {
        if (is_array($data)) {
            return array_map('sanitize_text_field', $data);
        }
        return is_scalar($data) ? sanitize_text_field($data) : $data;
    }
}