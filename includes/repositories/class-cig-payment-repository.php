<?php
/**
 * Payment Repository
 * Handles payment data access
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Payment_Repository extends Abstract_CIG_Repository {

    /**
     * Get payments by invoice ID
     *
     * @param int $invoice_id Invoice ID
     * @return array Array of CIG_Payment_DTO objects
     */
    public function get_payments_by_invoice($invoice_id) {
        if (!$this->tables_ready()) {
            // Fallback to postmeta
            return $this->get_payments_postmeta($invoice_id);
        }

        $cache_key = 'cig_payments_' . $invoice_id;
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            $payments = [];
            foreach ($cached as $payment_data) {
                $payments[] = CIG_Payment_DTO::from_array($payment_data);
            }
            return $payments;
        }

        $table = $this->get_table('payments');
        $query = $this->wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `invoice_id` = %d ORDER BY `date` DESC",
            $invoice_id
        );

        $rows = $this->wpdb->get_results($query, ARRAY_A);

        $payments = [];
        $cache_data = [];
        foreach ($rows as $row) {
            $payments[] = CIG_Payment_DTO::from_array($row);
            $cache_data[] = $row;
        }

        $this->set_cache($cache_key, $cache_data, 900);

        return $payments;
    }

    /**
     * Add payment
     *
     * @param CIG_Payment_DTO $payment Payment data
     * @return int|false Payment ID or false on failure
     */
    public function add_payment($payment) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot add payment: custom tables not ready');
            return false;
        }

        // Validate
        $errors = $payment->validate();
        if (!empty($errors)) {
            $this->log_error('Payment validation failed', ['errors' => $errors]);
            return false;
        }

        $table = $this->get_table('payments');
        $data = $payment->to_array(false);

        $result = $this->wpdb->insert($table, $data);

        if ($result === false) {
            $this->log_error('Failed to insert payment', ['error' => $this->get_last_error()]);
            return false;
        }

        $payment_id = $this->get_insert_id();
        
        // Clear cache
        $this->delete_cache('cig_payments_' . $payment->invoice_id);

        $this->log_info('Payment added', ['payment_id' => $payment_id, 'invoice_id' => $payment->invoice_id]);

        return $payment_id;
    }

    /**
     * Update payment
     *
     * @param int $id Payment ID
     * @param CIG_Payment_DTO $payment Payment data
     * @return bool Success status
     */
    public function update($id, $payment) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot update payment: custom tables not ready');
            return false;
        }

        // Validate
        $errors = $payment->validate();
        if (!empty($errors)) {
            $this->log_error('Payment validation failed', ['errors' => $errors]);
            return false;
        }

        $table = $this->get_table('payments');
        $data = $payment->to_array(false);
        
        // Remove invoice_id from update data - it's an immutable foreign key reference
        unset($data['invoice_id']);

        $result = $this->wpdb->update(
            $table,
            $data,
            ['id' => $id],
            null,
            ['%d']
        );

        if ($result === false) {
            $this->log_error('Failed to update payment', ['id' => $id, 'error' => $this->get_last_error()]);
            return false;
        }

        // Clear cache
        $this->delete_cache('cig_payments_' . $payment->invoice_id);

        return true;
    }

    /**
     * Delete payment
     *
     * @param int $id Payment ID
     * @return bool Success status
     */
    public function delete_payment($id) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot delete payment: custom tables not ready');
            return false;
        }

        // Get payment data for cache clearing
        $table = $this->get_table('payments');
        $payment_data = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $id),
            ARRAY_A
        );

        $result = $this->wpdb->delete($table, ['id' => $id], ['%d']);

        if ($result !== false && $payment_data) {
            // Clear cache
            $this->delete_cache('cig_payments_' . $payment_data['invoice_id']);
            $this->log_info('Payment deleted', ['payment_id' => $id]);
        }

        return $result !== false;
    }

    /**
     * Get payment summary for filters
     *
     * @param array $filters Filter conditions
     * @return array Payment summary data
     */
    public function get_payment_summary($filters = []) {
        if (!$this->tables_ready() || !$this->database->tables_have_data()) {
            return $this->get_payment_summary_postmeta($filters);
        }

        $table = $this->get_table('payments');
        $values = [];
        
        $where_clause = $this->build_where_clause($filters, $values);

        $sql = "SELECT 
            COUNT(*) as total_payments,
            SUM(amount) as total_amount,
            AVG(amount) as avg_payment_amount
        FROM `{$table}` {$where_clause}";

        if (!empty($values)) {
            $query = $this->wpdb->prepare($sql, $values);
        } else {
            $query = $sql;
        }

        return $this->wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Get total paid amount for an invoice
     *
     * @param int $invoice_id Invoice ID
     * @return float Total paid amount
     */
    public function get_total_paid($invoice_id) {
        if (!$this->tables_ready()) {
            $payments = $this->get_payments_postmeta($invoice_id);
            $total = 0;
            foreach ($payments as $payment) {
                $total += $payment->amount;
            }
            return $total;
        }

        $table = $this->get_table('payments');
        $query = $this->wpdb->prepare(
            "SELECT SUM(amount) FROM `{$table}` WHERE `invoice_id` = %d",
            $invoice_id
        );

        return (float)$this->wpdb->get_var($query);
    }

    /**
     * Fallback: Get payments using postmeta
     *
     * @param int $post_id Invoice post ID
     * @return array Array of CIG_Payment_DTO objects
     */
    private function get_payments_postmeta($post_id) {
        // In the existing system, payment history is stored as serialized array in postmeta
        $payment_history = get_post_meta($post_id, '_cig_payment_history', true);
        
        if (!is_array($payment_history)) {
            return [];
        }

        $payments = [];
        
        foreach ($payment_history as $payment) {
            $payment_array = [
                'invoice_id' => 0, // Not stored in postmeta
                'payment_date' => $payment['date'] ?? current_time('mysql'),
                'payment_method' => $payment['method'] ?? '',
                'amount' => $payment['amount'] ?? 0,
                'transaction_ref' => '',
                'note' => $payment['comment'] ?? '',
                'created_by' => $payment['user_id'] ?? 0,
                'created_at' => current_time('mysql'),
            ];

            $payments[] = CIG_Payment_DTO::from_array($payment_array);
        }

        return $payments;
    }

    /**
     * Fallback: Get payment summary using postmeta
     *
     * @param array $filters Filter conditions
     * @return array Payment summary data
     */
    private function get_payment_summary_postmeta($filters = []) {
        $args = [
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $post_ids = get_posts($args);
        
        $total_payments = 0;
        $total_amount = 0;

        foreach ($post_ids as $post_id) {
            $payments = $this->get_payments_postmeta($post_id);
            foreach ($payments as $payment) {
                $total_payments++;
                $total_amount += $payment->amount;
            }
        }

        return [
            'total_payments' => $total_payments,
            'total_amount' => $total_amount,
            'avg_payment_amount' => $total_payments > 0 ? ($total_amount / $total_payments) : 0,
        ];
    }
}
