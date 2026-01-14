<?php
/**
 * Invoice Repository
 * Handles all invoice data access with automatic postmeta fallback
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_Repository extends Abstract_CIG_Repository {

    /**
     * Find invoice by ID
     *
     * @param int $id Invoice ID
     * @return CIG_Invoice_DTO|null
     */
    public function find_by_id($id) {
        if (!$this->tables_ready()) {
            return null;
        }

        $cache_key = 'cig_invoice_' . $id;
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            return CIG_Invoice_DTO::from_array($cached);
        }

        $table = $this->get_table('invoices');
        $query = $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d LIMIT 1", $id);
        $row = $this->wpdb->get_row($query, ARRAY_A);

        if (!$row) {
            return null;
        }

        $dto = CIG_Invoice_DTO::from_array($row);
        $this->set_cache($cache_key, $row, 900);

        return $dto;
    }

    /**
     * Find invoice by invoice number
     *
     * @param string $invoice_number Invoice number
     * @return CIG_Invoice_DTO|null
     */
    public function find_by_invoice_number($invoice_number) {
        if (!$this->tables_ready()) {
            // Fallback to postmeta
            return $this->find_by_invoice_number_postmeta($invoice_number);
        }

        $cache_key = 'cig_invoice_num_' . md5($invoice_number);
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            return CIG_Invoice_DTO::from_array($cached);
        }

        $table = $this->get_table('invoices');
        $query = $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE `invoice_number` = %s LIMIT 1", $invoice_number);
        $row = $this->wpdb->get_row($query, ARRAY_A);

        if (!$row) {
            return null;
        }

        $dto = CIG_Invoice_DTO::from_array($row);
        $this->set_cache($cache_key, $row, 900);

        return $dto;
    }

    /**
     * Find invoice by post ID
     *
     * @param int $post_id Post ID
     * @return CIG_Invoice_DTO|null
     */
    public function find_by_post_id($post_id) {
        if (!$this->tables_ready()) {
            // Fallback to postmeta
            return CIG_Invoice_DTO::from_postmeta($post_id);
        }

        $cache_key = 'cig_invoice_post_' . $post_id;
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            return CIG_Invoice_DTO::from_array($cached);
        }

        $table = $this->get_table('invoices');
        $query = $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE `post_id` = %d LIMIT 1", $post_id);
        $row = $this->wpdb->get_row($query, ARRAY_A);

        if (!$row) {
            // Try fallback to postmeta
            return CIG_Invoice_DTO::from_postmeta($post_id);
        }

        $dto = CIG_Invoice_DTO::from_array($row);
        $this->set_cache($cache_key, $row, 900);

        return $dto;
    }

    /**
     * Get invoices with filters and pagination
     *
     * @param array $filters Filter conditions
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array Array of CIG_Invoice_DTO objects
     */
    public function get_invoices_by_filters($filters = [], $page = 1, $per_page = 30) {
        if (!$this->tables_ready() || !$this->database->tables_have_data()) {
            // Fallback to postmeta
            return $this->get_invoices_postmeta($filters, $page, $per_page);
        }

        $table = $this->get_table('invoices');
        $values = [];
        
        $where_clause = $this->build_where_clause($filters, $values);
        $order_by = $filters['order_by'] ?? 'created_at';
        $order = $filters['order'] ?? 'DESC';
        $order_clause = $this->build_order_clause($order_by, $order);
        
        $offset = ($page - 1) * $per_page;
        $limit_clause = $this->build_limit_clause($per_page, $offset);

        $sql = "SELECT * FROM `{$table}` {$where_clause} {$order_clause} {$limit_clause}";
        
        if (!empty($values)) {
            $query = $this->wpdb->prepare($sql, $values);
        } else {
            $query = $sql;
        }

        $rows = $this->wpdb->get_results($query, ARRAY_A);

        $invoices = [];
        foreach ($rows as $row) {
            $invoices[] = CIG_Invoice_DTO::from_array($row);
        }

        return $invoices;
    }

    /**
     * Get customer invoices
     *
     * @param int $customer_id Customer ID
     * @return array Array of CIG_Invoice_DTO objects
     */
    public function get_customer_invoices($customer_id) {
        return $this->get_invoices_by_filters(['customer_id' => $customer_id]);
    }

    /**
     * Create new invoice
     *
     * @param CIG_Invoice_DTO $dto Invoice data
     * @return int|false Invoice ID or false on failure
     */
    public function create($dto) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot create invoice: custom tables not ready');
            return false;
        }

        // Validate
        $errors = $dto->validate();
        if (!empty($errors)) {
            $this->log_error('Invoice validation failed', ['errors' => $errors]);
            return false;
        }

        $table = $this->get_table('invoices');
        $data = $dto->to_array(false);

        $result = $this->wpdb->insert($table, $data);

        if ($result === false) {
            $this->log_error('Failed to insert invoice', ['error' => $this->get_last_error()]);
            return false;
        }

        $invoice_id = $this->get_insert_id();
        
        // Clear cache
        $this->delete_cache('cig_invoice_' . $invoice_id);
        $this->delete_cache('cig_invoice_num_' . md5($dto->invoice_number));
        $this->delete_cache('cig_invoice_post_' . $dto->post_id);

        $this->log_info('Invoice created', ['invoice_id' => $invoice_id, 'invoice_number' => $dto->invoice_number]);

        return $invoice_id;
    }

    /**
     * Update existing invoice
     *
     * @param int $id Invoice ID
     * @param CIG_Invoice_DTO $dto Invoice data
     * @return bool Success status
     */
    public function update($id, $dto) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot update invoice: custom tables not ready');
            return false;
        }

        // Validate
        $errors = $dto->validate();
        if (!empty($errors)) {
            $this->log_error('Invoice validation failed', ['errors' => $errors]);
            return false;
        }

        $table = $this->get_table('invoices');
        $data = $dto->to_array(false);
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update(
            $table,
            $data,
            ['id' => $id],
            null,
            ['%d']
        );

        if ($result === false) {
            $this->log_error('Failed to update invoice', ['id' => $id, 'error' => $this->get_last_error()]);
            return false;
        }

        // Clear cache
        $this->delete_cache('cig_invoice_' . $id);
        $this->delete_cache('cig_invoice_num_' . md5($dto->invoice_number));
        $this->delete_cache('cig_invoice_post_' . $dto->post_id);

        $this->log_info('Invoice updated', ['invoice_id' => $id]);

        return true;
    }

    /**
     * Delete invoice
     *
     * @param int $id Invoice ID
     * @return bool Success status
     */
    public function delete($id) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot delete invoice: custom tables not ready');
            return false;
        }

        // Get invoice data for cache clearing
        $invoice = $this->find_by_id($id);
        
        $table = $this->get_table('invoices');
        $result = $this->wpdb->delete($table, ['id' => $id], ['%d']);

        if ($result === false) {
            $this->log_error('Failed to delete invoice', ['id' => $id, 'error' => $this->get_last_error()]);
            return false;
        }

        // Clear cache
        if ($invoice) {
            $this->delete_cache('cig_invoice_' . $id);
            $this->delete_cache('cig_invoice_num_' . md5($invoice->invoice_number));
            $this->delete_cache('cig_invoice_post_' . $invoice->post_id);
        }

        $this->log_info('Invoice deleted', ['invoice_id' => $id]);

        return true;
    }

    /**
     * Get statistics
     *
     * @param array $filters Filter conditions
     * @return array Statistics data
     */
    public function get_statistics($filters = []) {
        if (!$this->tables_ready() || !$this->database->tables_have_data()) {
            return $this->get_statistics_postmeta($filters);
        }

        $table = $this->get_table('invoices');
        $values = [];
        
        $where_clause = $this->build_where_clause($filters, $values);

        $sql = "SELECT 
            COUNT(*) as total_invoices,
            SUM(total_amount) as total_revenue,
            SUM(paid_amount) as total_paid,
            SUM(balance) as total_balance,
            AVG(total_amount) as avg_invoice_amount
        FROM `{$table}` {$where_clause}";

        if (!empty($values)) {
            $query = $this->wpdb->prepare($sql, $values);
        } else {
            $query = $sql;
        }

        return $this->wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Fallback: Find invoice by invoice number using postmeta
     *
     * @param string $invoice_number Invoice number
     * @return CIG_Invoice_DTO|null
     */
    private function find_by_invoice_number_postmeta($invoice_number) {
        $args = [
            'post_type' => 'invoice',
            'meta_query' => [
                [
                    'key' => '_cig_invoice_number',
                    'value' => $invoice_number,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1,
        ];

        $posts = get_posts($args);
        
        if (empty($posts)) {
            return null;
        }

        return CIG_Invoice_DTO::from_postmeta($posts[0]->ID);
    }

    /**
     * Fallback: Get invoices using postmeta
     *
     * @param array $filters Filter conditions
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array Array of CIG_Invoice_DTO objects
     */
    private function get_invoices_postmeta($filters = [], $page = 1, $per_page = 30) {
        $args = [
            'post_type' => 'invoice',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Apply filters to meta_query
        if (!empty($filters)) {
            $meta_query = [];
            
            if (isset($filters['invoice_status'])) {
                $meta_query[] = [
                    'key' => '_cig_invoice_status',
                    'value' => $filters['invoice_status'],
                ];
            }
            
            if (isset($filters['lifecycle_status'])) {
                $meta_query[] = [
                    'key' => '_cig_lifecycle_status',
                    'value' => $filters['lifecycle_status'],
                ];
            }

            if (!empty($meta_query)) {
                $args['meta_query'] = $meta_query;
            }
        }

        $posts = get_posts($args);
        
        $invoices = [];
        foreach ($posts as $post) {
            $dto = CIG_Invoice_DTO::from_postmeta($post->ID);
            if ($dto) {
                $invoices[] = $dto;
            }
        }

        return $invoices;
    }

    /**
     * Fallback: Get statistics using postmeta
     *
     * @param array $filters Filter conditions
     * @return array Statistics data
     */
    private function get_statistics_postmeta($filters = []) {
        $args = [
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $post_ids = get_posts($args);
        
        $stats = [
            'total_invoices' => count($post_ids),
            'total_revenue' => 0,
            'total_paid' => 0,
            'total_balance' => 0,
            'avg_invoice_amount' => 0,
        ];

        foreach ($post_ids as $post_id) {
            $total = (float)get_post_meta($post_id, '_cig_total_amount', true);
            $paid = (float)get_post_meta($post_id, '_cig_paid_amount', true);
            $balance = (float)get_post_meta($post_id, '_cig_balance', true);

            $stats['total_revenue'] += $total;
            $stats['total_paid'] += $paid;
            $stats['total_balance'] += $balance;
        }

        if ($stats['total_invoices'] > 0) {
            $stats['avg_invoice_amount'] = $stats['total_revenue'] / $stats['total_invoices'];
        }

        return $stats;
    }
}
