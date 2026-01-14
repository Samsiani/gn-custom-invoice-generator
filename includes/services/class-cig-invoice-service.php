<?php
/**
 * Invoice Service - Business Logic Layer
 * Handles all invoice business operations
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_Service {

    /**
     * @var CIG_Invoice_Repository
     */
    private $invoice_repo;

    /**
     * @var CIG_Invoice_Items_Repository
     */
    private $items_repo;

    /**
     * @var CIG_Payment_Repository
     */
    private $payment_repo;

    /**
     * @var CIG_Stock_Manager
     */
    private $stock_manager;

    /**
     * @var CIG_Validator
     */
    private $validator;

    /**
     * @var CIG_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct($invoice_repo, $items_repo, $payment_repo, $stock_manager = null, $validator = null, $logger = null) {
        $this->invoice_repo = $invoice_repo;
        $this->items_repo = $items_repo;
        $this->payment_repo = $payment_repo;
        $this->stock_manager = $stock_manager;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    /**
     * Create new invoice
     *
     * @param array $data Invoice data
     * @return int|false Invoice ID or false on failure
     */
    public function create_invoice($data) {
        // Extract buyer data
        $buyer = $data['buyer'] ?? [];
        $items = $data['items'] ?? [];
        $payment_history = $data['payment']['history'] ?? [];
        $general_note = $data['general_note'] ?? '';

        // Calculate totals
        $totals = $this->calculate_totals($items);
        
        // Calculate paid amount from payment history
        $paid_amount = 0;
        foreach ($payment_history as $payment) {
            $paid_amount += (float)($payment['amount'] ?? 0);
        }

        // Determine invoice status based on payment
        $invoice_status = $paid_amount > 0 ? 'standard' : 'fictive';
        
        // Create post first
        $post_id = wp_insert_post([
            'post_title' => sprintf(__('Invoice %s', 'cig'), $data['invoice_number'] ?? ''),
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ]);

        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }

        // Sync customer data
        $customer_id = $this->sync_customer_data($buyer);

        // Create invoice DTO
        $invoice_dto = CIG_Invoice_DTO::from_array([
            'post_id' => $post_id,
            'invoice_number' => $data['invoice_number'] ?? '',
            'buyer_name' => $buyer['name'] ?? '',
            'buyer_tax_id' => $buyer['tax_id'] ?? '',
            'buyer_address' => $buyer['address'] ?? '',
            'buyer_phone' => $buyer['phone'] ?? '',
            'buyer_email' => $buyer['email'] ?? '',
            'customer_id' => $customer_id,
            'invoice_status' => $invoice_status,
            'lifecycle_status' => 'unfinished',
            'rs_uploaded' => false,
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax'],
            'discount_amount' => $totals['discount'],
            'total_amount' => $totals['total'],
            'paid_amount' => $paid_amount,
            'balance' => $totals['total'] - $paid_amount,
            'general_note' => $general_note,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        // Insert into custom table
        $invoice_id = $this->invoice_repo->create($invoice_dto);
        
        if (!$invoice_id) {
            // Rollback: delete post
            wp_delete_post($post_id, true);
            return false;
        }

        // Insert items
        if (!empty($items)) {
            foreach ($items as &$item) {
                $item['invoice_id'] = $invoice_id;
            }
            $this->items_repo->bulk_insert($invoice_id, $items);
        }

        // Insert payments
        if (!empty($payment_history)) {
            foreach ($payment_history as $payment) {
                $payment_dto = CIG_Payment_DTO::from_array([
                    'invoice_id' => $invoice_id,
                    'payment_date' => $payment['date'] ?? current_time('mysql'),
                    'payment_method' => $payment['method'] ?? '',
                    'amount' => $payment['amount'] ?? 0,
                    'note' => $payment['comment'] ?? '',
                    'created_by' => $payment['user_id'] ?? get_current_user_id(),
                ]);
                $this->payment_repo->add_payment($payment_dto);
            }
        }

        // Also save to postmeta for backward compatibility
        $this->save_to_postmeta($post_id, $data, $invoice_status);

        return $invoice_id;
    }

    /**
     * Update existing invoice
     *
     * @param int $invoice_id Invoice ID
     * @param array $data Invoice data
     * @return bool Success status
     */
    public function update_invoice($invoice_id, $data) {
        // Get existing invoice
        $existing = $this->invoice_repo->find_by_id($invoice_id);
        if (!$existing) {
            return false;
        }

        // Security check for completed invoices
        if ($existing->lifecycle_status === 'completed' && !current_user_can('administrator')) {
            return false;
        }

        // Extract and process data
        $buyer = $data['buyer'] ?? [];
        $items = $data['items'] ?? [];
        $payment_history = $data['payment']['history'] ?? [];
        $general_note = $data['general_note'] ?? '';

        // Calculate totals
        $totals = $this->calculate_totals($items);
        
        // Calculate paid amount
        $paid_amount = 0;
        foreach ($payment_history as $payment) {
            $paid_amount += (float)($payment['amount'] ?? 0);
        }

        // Determine invoice status
        $invoice_status = $paid_amount > 0 ? 'standard' : 'fictive';
        
        // Sync customer
        $customer_id = $this->sync_customer_data($buyer);

        // Update invoice DTO
        $invoice_dto = CIG_Invoice_DTO::from_array([
            'id' => $invoice_id,
            'post_id' => $existing->post_id,
            'invoice_number' => $data['invoice_number'] ?? $existing->invoice_number,
            'buyer_name' => $buyer['name'] ?? '',
            'buyer_tax_id' => $buyer['tax_id'] ?? '',
            'buyer_address' => $buyer['address'] ?? '',
            'buyer_phone' => $buyer['phone'] ?? '',
            'buyer_email' => $buyer['email'] ?? '',
            'customer_id' => $customer_id,
            'invoice_status' => $invoice_status,
            'lifecycle_status' => $existing->lifecycle_status,
            'rs_uploaded' => $existing->rs_uploaded,
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax'],
            'discount_amount' => $totals['discount'],
            'total_amount' => $totals['total'],
            'paid_amount' => $paid_amount,
            'balance' => $totals['total'] - $paid_amount,
            'general_note' => $general_note,
            'created_by' => $existing->created_by,
            'created_at' => $existing->created_at,
            'updated_at' => current_time('mysql'),
        ]);

        // Update invoice
        $result = $this->invoice_repo->update($invoice_id, $invoice_dto);
        
        if (!$result) {
            return false;
        }

        // Update items - delete old and insert new
        $this->items_repo->delete_by_invoice($invoice_id);
        if (!empty($items)) {
            foreach ($items as &$item) {
                $item['invoice_id'] = $invoice_id;
            }
            $this->items_repo->bulk_insert($invoice_id, $items);
        }

        // Also update postmeta for backward compatibility
        $this->save_to_postmeta($existing->post_id, $data, $invoice_status);

        return true;
    }

    /**
     * Delete invoice
     *
     * @param int $invoice_id Invoice ID
     * @return bool Success status
     */
    public function delete_invoice($invoice_id) {
        $invoice = $this->invoice_repo->find_by_id($invoice_id);
        if (!$invoice) {
            return false;
        }

        // Delete items
        $this->items_repo->delete_by_invoice($invoice_id);

        // Delete invoice
        $result = $this->invoice_repo->delete($invoice_id);

        // Also delete post
        if ($result && $invoice->post_id) {
            wp_delete_post($invoice->post_id, true);
        }

        return $result;
    }

    /**
     * Get invoice with all related data
     *
     * @param int $invoice_id Invoice ID
     * @return array|null Invoice data with items and payments
     */
    public function get_invoice($invoice_id) {
        $invoice = $this->invoice_repo->find_by_id($invoice_id);
        if (!$invoice) {
            return null;
        }

        $items = $this->items_repo->get_items_by_invoice($invoice_id);
        $payments = $this->payment_repo->get_payments_by_invoice($invoice_id);

        return [
            'invoice' => $invoice,
            'items' => $items,
            'payments' => $payments,
        ];
    }

    /**
     * Toggle invoice status
     *
     * @param int $invoice_id Invoice ID
     * @param string $new_status New status
     * @return bool Success status
     */
    public function toggle_status($invoice_id, $new_status) {
        $invoice = $this->invoice_repo->find_by_id($invoice_id);
        if (!$invoice) {
            return false;
        }

        $invoice->invoice_status = $new_status;
        return $this->invoice_repo->update($invoice_id, $invoice);
    }

    /**
     * Mark invoice as sold
     *
     * @param int $invoice_id Invoice ID
     * @return bool Success status
     */
    public function mark_as_sold($invoice_id) {
        $invoice = $this->invoice_repo->find_by_id($invoice_id);
        if (!$invoice) {
            return false;
        }

        $invoice->lifecycle_status = 'completed';
        return $this->invoice_repo->update($invoice_id, $invoice);
    }

    /**
     * Generate next invoice number
     *
     * @return string Invoice number
     */
    public function generate_invoice_number() {
        if (!$this->invoice_repo) {
            // Fallback if repository not available
            $last_num = get_option('cig_last_invoice_number', CIG_INVOICE_NUMBER_BASE);
            $new_num = $last_num + 1;
            update_option('cig_last_invoice_number', $new_num);
            return CIG_INVOICE_NUMBER_PREFIX . $new_num;
        }
        
        // Use repository to get max number
        global $wpdb;
        $table = $wpdb->prefix . 'cig_invoices';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            // Fallback to option
            $last_num = get_option('cig_last_invoice_number', CIG_INVOICE_NUMBER_BASE);
            $new_num = $last_num + 1;
            update_option('cig_last_invoice_number', $new_num);
            return CIG_INVOICE_NUMBER_PREFIX . $new_num;
        }
        
        $max_number = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(invoice_number, %d) AS UNSIGNED)) FROM `{$table}`",
            strlen(CIG_INVOICE_NUMBER_PREFIX) + 1
        ));
        
        if (!$max_number) {
            $max_number = CIG_INVOICE_NUMBER_BASE;
        }

        return CIG_INVOICE_NUMBER_PREFIX . ($max_number + 1);
    }

    /**
     * Calculate invoice totals
     *
     * @param array $items Invoice items
     * @return array Totals array
     */
    private function calculate_totals($items) {
        $subtotal = 0;
        
        foreach ($items as $item) {
            // Support both field name formats for backward compatibility
            $qty = (float)($item['quantity'] ?? $item['qty'] ?? 1);
            $price = (float)($item['unit_price'] ?? $item['price'] ?? 0);
            $subtotal += $qty * $price;
        }

        return [
            'subtotal' => $subtotal,
            'tax' => 0, // Tax not implemented in v3.6.0
            'discount' => 0, // Discount not implemented in v3.6.0
            'total' => $subtotal,
        ];
    }

    /**
     * Sync customer data (create or update customer)
     *
     * @param array $buyer_data Buyer information
     * @return int|null Customer ID
     */
    private function sync_customer_data($buyer_data) {
        if (empty($buyer_data['tax_id'])) {
            return null;
        }

        // In the existing system, customers are managed separately
        // This is a placeholder for customer sync logic
        return null;
    }

    /**
     * Save invoice data to postmeta for backward compatibility
     *
     * @param int $post_id Post ID
     * @param array $data Invoice data
     * @param string $status Invoice status
     */
    private function save_to_postmeta($post_id, $data, $status) {
        $buyer = $data['buyer'] ?? [];
        $items = $data['items'] ?? [];
        $payment = $data['payment'] ?? [];

        update_post_meta($post_id, '_cig_invoice_number', $data['invoice_number'] ?? '');
        update_post_meta($post_id, '_cig_buyer_name', $buyer['name'] ?? '');
        update_post_meta($post_id, '_cig_buyer_tax_id', $buyer['tax_id'] ?? '');
        update_post_meta($post_id, '_cig_buyer_address', $buyer['address'] ?? '');
        update_post_meta($post_id, '_cig_buyer_phone', $buyer['phone'] ?? '');
        update_post_meta($post_id, '_cig_buyer_email', $buyer['email'] ?? '');
        update_post_meta($post_id, '_cig_invoice_status', $status);
        update_post_meta($post_id, '_cig_general_note', $data['general_note'] ?? '');
        update_post_meta($post_id, '_cig_invoice_items', $items);
        update_post_meta($post_id, '_cig_payment_history', $payment['history'] ?? []);
    }
}
