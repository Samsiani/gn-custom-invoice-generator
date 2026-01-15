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
        if ($cached !== false && is_array($cached)) {
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
        if ($cached !== false && is_array($cached)) {
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
        if ($cached !== false && is_array($cached)) {
            return CIG_Invoice_DTO::from_array($cached);
        }

        $table = $this->get_table('invoices');
        $query = $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE `old_post_id` = %d LIMIT 1", $post_id);
        $row = $this->wpdb->get_row($query, ARRAY_A);

        if (!$row) {
            // Try fallback to postmeta
            $dto = CIG_Invoice_DTO::from_postmeta($post_id);
            // Return null instead of causing fatal error if postmeta also returns null
            return $dto;
        }

        $dto = CIG_Invoice_DTO::from_array($row);
        if ($dto !== null) {
            $this->set_cache($cache_key, $row, 900);
        }

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
            $dto = CIG_Invoice_DTO::from_array($row);
            if ($dto !== null) {
                $invoices[] = $dto;
            }
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
        $this->delete_cache('cig_invoice_post_' . $dto->old_post_id);

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
        
        // Remove old_post_id from update data - it's an immutable reference field
        unset($data['old_post_id']);

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
        $this->delete_cache('cig_invoice_post_' . $dto->old_post_id);

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
            $this->delete_cache('cig_invoice_post_' . $invoice->old_post_id);
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
     * Get comprehensive statistics with single SQL query (optimized)
     * Replaces N+1 query pattern in statistics endpoints
     *
     * @param array $filters Filter conditions (date_from, date_to, type, etc.)
     * @return array Complete statistics data
     */
    public function get_comprehensive_statistics($filters = []) {
        if (!$this->tables_ready() || !$this->database->tables_have_data()) {
            return $this->get_statistics_postmeta($filters);
        }

        $table_invoices = $this->get_table('invoices');
        $table_items = $this->get_table('items');
        $table_payments = $this->get_table('payments');
        $values = [];
        
        // Build invoice WHERE clause
        $where_clause = $this->build_where_clause($filters, $values);

        // Single comprehensive query for invoice stats
        $sql_invoices = "SELECT 
            COUNT(*) as total_invoices,
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(paid_amount), 0) as total_paid,
            COALESCE(SUM(balance), 0) as total_balance,
            COALESCE(AVG(total_amount), 0) as avg_invoice_amount,
            SUM(CASE WHEN type = 'standard' THEN 1 ELSE 0 END) as standard_invoices,
            SUM(CASE WHEN type = 'fictive' THEN 1 ELSE 0 END) as fictive_invoices,
            SUM(CASE WHEN balance > 0.01 THEN 1 ELSE 0 END) as outstanding_invoices
        FROM `{$table_invoices}` {$where_clause}";

        if (!empty($values)) {
            $query = $this->wpdb->prepare($sql_invoices, $values);
        } else {
            $query = $sql_invoices;
        }

        $invoice_stats = $this->wpdb->get_row($query, ARRAY_A);

        // Get payment method breakdown with single query
        $payment_sql = "SELECT 
            p.payment_method,
            COALESCE(SUM(p.amount), 0) as total_amount
        FROM `{$table_payments}` p
        INNER JOIN `{$table_invoices}` i ON p.invoice_id = i.id
        {$where_clause}
        GROUP BY p.payment_method";

        if (!empty($values)) {
            $payment_query = $this->wpdb->prepare($payment_sql, $values);
        } else {
            $payment_query = $payment_sql;
        }

        $payment_rows = $this->wpdb->get_results($payment_query, ARRAY_A);
        
        $payment_breakdown = [
            'company_transfer' => 0,
            'cash' => 0,
            'consignment' => 0,
            'credit' => 0,
            'other' => 0,
        ];
        
        foreach ($payment_rows as $row) {
            $method = $row['payment_method'] ?? 'other';
            if (isset($payment_breakdown[$method])) {
                $payment_breakdown[$method] = (float)$row['total_amount'];
            } else {
                $payment_breakdown['other'] += (float)$row['total_amount'];
            }
        }

        // Get item status breakdown with single query
        // Build WHERE clause for items join
        $items_values = [];
        $items_where = $this->build_where_clause($filters, $items_values);
        
        $items_sql = "SELECT 
            COALESCE(SUM(CASE WHEN it.warranty IS NOT NULL AND it.warranty != '' THEN it.qty ELSE 0 END), 0) as sold_count,
            COALESCE(SUM(it.qty), 0) as total_items
        FROM `{$table_items}` it
        INNER JOIN `{$table_invoices}` i ON it.invoice_id = i.id
        {$items_where}";

        if (!empty($items_values)) {
            $items_query = $this->wpdb->prepare($items_sql, $items_values);
        } else {
            $items_query = $items_sql;
        }

        $item_stats = $this->wpdb->get_row($items_query, ARRAY_A);

        return [
            'total_invoices' => (int)($invoice_stats['total_invoices'] ?? 0),
            'total_revenue' => (float)($invoice_stats['total_revenue'] ?? 0),
            'total_paid' => (float)($invoice_stats['total_paid'] ?? 0),
            'total_outstanding' => max(0, (float)($invoice_stats['total_balance'] ?? 0)),
            'avg_invoice_amount' => (float)($invoice_stats['avg_invoice_amount'] ?? 0),
            'standard_invoices' => (int)($invoice_stats['standard_invoices'] ?? 0),
            'fictive_invoices' => (int)($invoice_stats['fictive_invoices'] ?? 0),
            'outstanding_invoices' => (int)($invoice_stats['outstanding_invoices'] ?? 0),
            'total_company_transfer' => $payment_breakdown['company_transfer'],
            'total_cash' => $payment_breakdown['cash'],
            'total_consignment' => $payment_breakdown['consignment'],
            'total_credit' => $payment_breakdown['credit'],
            'total_other' => $payment_breakdown['other'],
            'total_items' => (int)($item_stats['total_items'] ?? 0),
        ];
    }

    /**
     * Get user statistics with optimized single query
     *
     * @param array $filters Filter conditions
     * @return array User statistics grouped by author
     */
    public function get_user_statistics($filters = []) {
        if (!$this->tables_ready() || !$this->database->tables_have_data()) {
            return [];
        }

        $table = $this->get_table('invoices');
        $values = [];
        
        $where_clause = $this->build_where_clause($filters, $values);

        $sql = "SELECT 
            author_id,
            COUNT(*) as invoice_count,
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(paid_amount), 0) as total_paid,
            MAX(COALESCE(activation_date, created_at)) as last_invoice_date
        FROM `{$table}` 
        {$where_clause}
        GROUP BY author_id
        ORDER BY invoice_count DESC";

        if (!empty($values)) {
            $query = $this->wpdb->prepare($sql, $values);
        } else {
            $query = $sql;
        }

        return $this->wpdb->get_results($query, ARRAY_A);
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
                    'value' => sanitize_text_field($invoice_number),
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
