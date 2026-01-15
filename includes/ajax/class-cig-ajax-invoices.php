<?php
/**
 * AJAX Handler for Invoice Operations
 * Updated: Auto-Status Logic & General Note Saving
 *
 * @package CIG
 * @since 4.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Ajax_Invoices {

    /** @var CIG_Invoice */
    private $invoice;

    /** @var CIG_Stock_Manager */
    private $stock;

    /** @var CIG_Validator */
    private $validator;

    /** @var CIG_Security */
    private $security;

    /** @var CIG_Cache */
    private $cache;

    /**
     * Constructor
     */
    public function __construct($invoice, $stock, $validator, $security, $cache = null) {
        $this->invoice   = $invoice;
        $this->stock     = $stock;
        $this->validator = $validator;
        $this->security  = $security;
        $this->cache     = $cache;

        // Invoice CRUD
        add_action('wp_ajax_cig_save_invoice',           [$this, 'save_invoice']);
        add_action('wp_ajax_cig_update_invoice',         [$this, 'update_invoice']);
        add_action('wp_ajax_cig_next_invoice_number',    [$this, 'next_invoice_number']);
        add_action('wp_ajax_cig_toggle_invoice_status',  [$this, 'toggle_invoice_status']);
        add_action('wp_ajax_cig_mark_as_sold',           [$this, 'mark_as_sold']);
    }

    /**
     * Force update invoice post_date using direct SQL
     * Combines specified payment date with current site time
     *
     * @param int    $post_id      The invoice post ID
     * @param string $payment_date Payment date in Y-m-d format (optional)
     */
    private function force_update_invoice_date($post_id, $payment_date = null) {
        global $wpdb;
        
        // Validate post_id exists and is an invoice
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'invoice') {
            return;
        }
        
        // Validate and sanitize date format (Y-m-d)
        $date_part = current_time('Y-m-d'); // Default to current site date
        if ($payment_date) {
            $payment_date = sanitize_text_field($payment_date);
            // Validate date format matches Y-m-d
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
                $parsed = date_parse($payment_date);
                if ($parsed['error_count'] === 0 && checkdate($parsed['month'], $parsed['day'], $parsed['year'])) {
                    $date_part = $payment_date;
                }
            }
        }
        
        // Get current site time (using WordPress timezone settings)
        $time_part = current_time('H:i:s');
        
        // Combine date and time
        $new_datetime = $date_part . ' ' . $time_part;
        
        // Calculate GMT datetime
        $new_datetime_gmt = get_gmt_from_date($new_datetime);
        
        // Update post_date directly via SQL
        $wpdb->update(
            $wpdb->posts,
            [
                'post_date'     => $new_datetime,
                'post_date_gmt' => $new_datetime_gmt,
            ],
            ['ID' => $post_id],
            ['%s', '%s'],
            ['%d']
        );
        
        // Clean post cache to reflect changes
        clean_post_cache($post_id);
    }

    /**
     * Purge all relevant caches including LiteSpeed Cache
     * Note: Uses aggressive cache purging as required to solve the issue where
     * LiteSpeed did not automatically detect changes made via admin-ajax.php
     *
     * @param int $post_id The invoice post ID (optional)
     */
    private function purge_cache($post_id = null) {
        // 1. Clear WordPress Object Cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // 2. Clear internal CIG caches
        if ($this->cache) {
            $this->cache->delete('statistics_summary');
            if ($post_id) {
                $author_id = get_post_field('post_author', $post_id);
                $this->cache->delete('user_invoices_' . $author_id);
            }
        }
        
        // 3. Clean post-specific cache first if post_id provided (targeted purge)
        if ($post_id) {
            $post_id = absint($post_id);
            clean_post_cache($post_id);
            
            // Specific post purge for LiteSpeed
            if (has_action('litespeed_purge_post')) {
                do_action('litespeed_purge_post', $post_id);
            }
        }
        
        // 4. LiteSpeed Cache - API method (aggressive purge for AJAX compatibility)
        if (class_exists('LiteSpeed_Cache_API')) {
            if (method_exists('LiteSpeed_Cache_API', 'purge_all')) {
                LiteSpeed_Cache_API::purge_all();
            }
        }
        
        // 5. LiteSpeed Cache - Action Hook method
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }
        
        // 6. LiteSpeed Cache - HTTP Header method (for AJAX requests)
        // Note: Aggressive purge is required as LiteSpeed doesn't auto-detect AJAX changes
        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: *');
        }
    }

    /**
     * Helper to process payment history array
     */
    private function process_payment_history($raw_history) {
        $clean_history = [];
        if (is_array($raw_history)) {
            foreach ($raw_history as $h) {
                $clean_history[] = [
                    'date'    => sanitize_text_field($h['date'] ?? ''),
                    'amount'  => floatval($h['amount'] ?? 0),
                    'method'  => sanitize_text_field($h['method'] ?? ''),
                    'comment' => sanitize_text_field($h['comment'] ?? ''),
                    'user_id' => intval($h['user_id'] ?? get_current_user_id())
                ];
            }
        }
        return $clean_history;
    }

    /**
     * Save new invoice
     */
    public function save_invoice() {
        $this->process_invoice_save(false);
    }

    /**
     * Update existing invoice
     */
    public function update_invoice() {
        $this->process_invoice_save(true);
    }

    /**
     * Main logic for saving/updating invoice
     */
    private function process_invoice_save($update) {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $raw_payload = wp_unslash($_POST['payload'] ?? '');
        $d = json_decode($raw_payload, true);

        if (!is_array($d)) {
            wp_send_json_error(['message' => 'Invalid data format']);
        }
        
        // Security Check for Completed Invoices using security helper
        if ($update) {
            $id = intval($d['invoice_id'] ?? 0);
            if (!$this->security->can_edit_invoice($id)) {
                wp_send_json_error(['message' => 'დასრულებული ინვოისის რედაქტირება აკრძალულია.'], 403);
            }
        }

        // Use centralized validation
        $validation = $this->validator->validate_invoice_request($d);
        
        if (!$validation['valid']) {
            wp_send_json_error([
                'message' => implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            ], 400);
        }
        
        // Use sanitized data from validator
        $sanitized = $validation['data'];
        $num = $sanitized['invoice_number'];
        $general_note = $sanitized['general_note'];
        
        // 1. Determine Status based on Payment
        $hist = $sanitized['payment']['history'];
        $paid = 0; 
        foreach ($hist as $h) {
            $paid += floatval($h['amount'] ?? 0);
        }

        // AUTO-STATUS LOGIC:
        $st = ($paid > 0) ? 'standard' : 'fictive';

        // 2. Process Items & Enforce Item Statuses
        $items = $sanitized['items'];

        if (empty($items)) {
            wp_send_json_error(['message' => 'დაამატეთ პროდუქტები'], 400);
        }

        $processed_items = [];
        foreach ($items as $item) {
            $current_item_status = $item['status'] ?? 'none';
            
            if ($st === 'fictive') {
                $item['status'] = 'none'; 
                $item['reservation_days'] = 0;
            } else {
                if ($current_item_status === 'none' || empty($current_item_status)) {
                    $item['status'] = 'reserved';
                }
            }
            $processed_items[] = $item;
        }
        $items = $processed_items;
        
        // Use sanitized buyer data
        $buyer = $sanitized['buyer'];
        
        $pid = 0;

        if ($update) {
            $id = intval($sanitized['invoice_id'] ?? 0);
            if ($st === 'standard') { 
                $err = $this->stock->validate_stock($items, $id); 
                if ($err) {
                    wp_send_json_error(['message' => 'Stock error', 'errors' => $err], 400);
                }
            }
            
            $new_num = CIG_Invoice::ensure_unique_number($num, $id);
            
            wp_update_post([
                'ID'            => $id, 
                'post_title'    => 'Invoice #' . $new_num, 
                'post_modified' => current_time('mysql')
            ]);
            $pid = $id;
        } else {
            if ($st === 'standard') { 
                $err = $this->stock->validate_stock($items, 0); 
                if ($err) {
                    wp_send_json_error(['message' => 'Stock error', 'errors' => $err], 400); 
                }
            }
            $new_num = CIG_Invoice::ensure_unique_number($num);
            $pid = wp_insert_post([
                'post_type'   => 'invoice',
                'post_status' => 'publish',
                'post_title'  => 'Invoice #' . $new_num, 
                'post_author' => get_current_user_id()
            ]);
        }
        
        // Capture OLD items BEFORE saving new metadata
        $old_items = [];
        if ($update) {
            $old_items = get_post_meta($pid, '_cig_items', true);
            if (!is_array($old_items)) {
                $old_items = [];
            }
        }

        update_post_meta($pid, '_wp_page_template', 'elementor_canvas');
        update_post_meta($pid, '_cig_invoice_status', $st);
        
        // NEW: Save General Note
        update_post_meta($pid, '_cig_general_note', $general_note);
        
        // Update Customer
        if (function_exists('CIG') && isset(CIG()->customers)) { 
            $cid = CIG()->customers->sync_customer($buyer); 
            if ($cid) {
                update_post_meta($pid, '_cig_customer_id', $cid); 
            }
        }
        
        // Prepare Payment Data for saving
        $payment_data = ['history' => $hist];

        // Save NEW items and metadata (postmeta for backward compatibility)
        CIG_Invoice::save_meta($pid, $new_num, $buyer, $items, $payment_data);
        
        // --- DUAL STORAGE: Also save to custom tables via invoice service ---
        if (function_exists('CIG') && isset(CIG()->invoice_service)) {
            $service_data = [
                'invoice_number' => $new_num,
                'buyer' => $buyer,
                'items' => $items,
                'payment' => $payment_data,
                'general_note' => $general_note,
            ];
            
            if ($update) {
                // Find the custom table invoice ID by post_id
                if (isset(CIG()->invoice_repo)) {
                    $existing = CIG()->invoice_repo->find_by_post_id($pid);
                    if ($existing) {
                        CIG()->invoice_service->update_invoice($existing->id, $service_data);
                    }
                }
            } else {
                // For new invoices, the service will create both WP post and custom table entry
                // Since we already created the post above, we update the existing record
                if (isset(CIG()->invoice_repo)) {
                    $existing = CIG()->invoice_repo->find_by_post_id($pid);
                    if ($existing) {
                        CIG()->invoice_service->update_invoice($existing->id, $service_data);
                    }
                }
            }
        }
        
        // Update Stock
        $items_for_stock = ($st === 'fictive') ? [] : $items;
        $this->stock->update_invoice_reservations($pid, $old_items, $items_for_stock); 
        
        // Update invoice date when invoice is active (standard) with the most recent payment date
        if ($st === 'standard' && !empty($hist)) {
            // Get the most recent payment date from history
            $latest_date = null;
            foreach ($hist as $h) {
                $date = $h['date'] ?? null;
                if ($date && (!$latest_date || $date > $latest_date)) {
                    $latest_date = $date;
                }
            }
            if ($latest_date) {
                $this->force_update_invoice_date($pid, $latest_date);
            }
        }
        
        // Purge all caches including LiteSpeed
        $this->purge_cache($pid);

        wp_send_json_success([
            'post_id'        => $pid, 
            'view_url'       => get_permalink($pid), 
            'invoice_number' => $new_num,
            'status'         => $st
        ]);
    }

    /**
     * Mark reserved items as sold (Finalize Invoice)
     */
    public function mark_as_sold() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']);
        if (!$id || get_post_type($id) !== 'invoice') {
            wp_send_json_error(['message' => 'Invalid invoice']);
        }

        // Get Status
        $invoice_status = get_post_meta($id, '_cig_invoice_status', true);
        $is_fictive = ($invoice_status === 'fictive');

        // 1. Get Current Items (DB state)
        $items = get_post_meta($id, '_cig_items', true);
        if (!is_array($items)) $items = [];

        $old_items = $items; 
        $updated_items = [];
        $has_change = false;

        // 2. Prepare Updated Items (Memory state)
        foreach ($items as $item) {
            $st = $item['status'] ?? 'none';
            // Only convert Reserved to Sold. Ignore 'none' (fictive) or 'canceled'.
            if ($st === 'reserved') {
                $item['status'] = 'sold';
                $item['reservation_days'] = 0;
                $has_change = true;
            }
            $updated_items[] = $item;
        }

        if ($has_change) {
            // 3. Save updated items to DB
            update_post_meta($id, '_cig_items', $updated_items);
            
            // 4. Sync with Stock Manager (ONLY IF STANDARD)
            if (!$is_fictive) {
                $this->stock->update_invoice_reservations($id, $old_items, $updated_items);
            }
            
            // 5. Mark invoice as completed
            update_post_meta($id, '_cig_lifecycle_status', 'completed');

            // 6. Force update invoice date with current site time
            $this->force_update_invoice_date($id);
            
            // 7. Purge all caches including LiteSpeed
            $this->purge_cache($id);

            wp_send_json_success(['message' => 'Invoice marked as sold successfully.']);
        } else {
            wp_send_json_error(['message' => 'No reserved items found to mark as sold.']);
        }
    }

    /**
     * Toggle invoice status (Standard <-> Fictive)
     */
    public function toggle_invoice_status() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']); 
        $nst = sanitize_text_field($_POST['status']);
        
        $paid = floatval(get_post_meta($id, '_cig_payment_paid_amount', true));
        if ($nst === 'fictive' && $paid > 0.001) {
            wp_send_json_error(['message' => 'გადახდილი ვერ იქნება ფიქტიური']);
        }

        $items = get_post_meta($id, '_cig_items', true) ?: [];
        if ($nst === 'standard') {
            $err = $this->stock->validate_stock($items, $id);
            if ($err) {
                wp_send_json_error(['message' => 'Stock error', 'errors' => $err]);
            }
        }

        $ost = get_post_meta($id, '_cig_invoice_status', true) ?: 'standard';
        
        // 1. Update status in DB
        update_post_meta($id, '_cig_invoice_status', $nst);

        // 2. Update Reservations / Stock
        $items_old = ($ost === 'fictive') ? [] : $items;
        $items_new = ($nst === 'fictive') ? [] : $items;

        $this->stock->update_invoice_reservations($id, $items_old, $items_new);
        
        // 3. Force update invoice date when becoming active (standard)
        if ($nst === 'standard' && $ost === 'fictive') {
            $this->force_update_invoice_date($id);
        }
        
        // 4. Force post update timestamp
        wp_update_post([
            'ID'            => $id, 
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
        
        // 5. Purge all caches including LiteSpeed
        $this->purge_cache($id);
        
        wp_send_json_success();
    }

    /**
     * Get next invoice number
     */
    public function next_invoice_number() { 
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        wp_send_json_success(['next' => CIG_Invoice::get_next_number()]); 
    }
}