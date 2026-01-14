<?php
/**
 * Invoice Items Repository
 * Handles invoice line items data access
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_Items_Repository extends Abstract_CIG_Repository {

    /**
     * Get items by invoice ID
     *
     * @param int $invoice_id Invoice ID
     * @return array Array of CIG_Invoice_Item_DTO objects
     */
    public function get_items_by_invoice($invoice_id) {
        if (!$this->tables_ready()) {
            // Fallback to postmeta
            return $this->get_items_postmeta($invoice_id);
        }

        $cache_key = 'cig_invoice_items_' . $invoice_id;
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            $items = [];
            foreach ($cached as $item_data) {
                $items[] = CIG_Invoice_Item_DTO::from_array($item_data);
            }
            return $items;
        }

        $table = $this->get_table('items');
        $query = $this->wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `invoice_id` = %d ORDER BY `sort_order` ASC, `id` ASC",
            $invoice_id
        );

        $rows = $this->wpdb->get_results($query, ARRAY_A);

        $items = [];
        $cache_data = [];
        foreach ($rows as $row) {
            $items[] = CIG_Invoice_Item_DTO::from_array($row);
            $cache_data[] = $row;
        }

        $this->set_cache($cache_key, $cache_data, 900);

        return $items;
    }

    /**
     * Bulk insert items for an invoice
     *
     * @param int $invoice_id Invoice ID
     * @param array $items Array of item data arrays
     * @return bool Success status
     */
    public function bulk_insert($invoice_id, array $items) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot insert items: custom tables not ready');
            return false;
        }

        if (empty($items)) {
            return true; // Nothing to insert
        }

        $table = $this->get_table('items');
        $sort_order = 0;
        $inserted = 0;

        foreach ($items as $item_data) {
            $item_data['invoice_id'] = $invoice_id;
            $item_data['sort_order'] = $sort_order++;
            
            $dto = CIG_Invoice_Item_DTO::from_array($item_data);
            
            // Validate
            $errors = $dto->validate();
            if (!empty($errors)) {
                $this->log_error('Item validation failed', ['errors' => $errors, 'item' => $item_data]);
                continue;
            }

            $data = $dto->to_array(false);
            
            $result = $this->wpdb->insert($table, $data);

            if ($result === false) {
                $this->log_error('Failed to insert item', ['error' => $this->get_last_error()]);
            } else {
                $inserted++;
            }
        }

        // Clear cache
        $this->delete_cache('cig_invoice_items_' . $invoice_id);

        $this->log_info('Invoice items inserted', ['invoice_id' => $invoice_id, 'count' => $inserted]);

        return $inserted > 0;
    }

    /**
     * Update a single item
     *
     * @param int $id Item ID
     * @param CIG_Invoice_Item_DTO $dto Item data
     * @return bool Success status
     */
    public function update($id, $dto) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot update item: custom tables not ready');
            return false;
        }

        // Validate
        $errors = $dto->validate();
        if (!empty($errors)) {
            $this->log_error('Item validation failed', ['errors' => $errors]);
            return false;
        }

        $table = $this->get_table('items');
        $data = $dto->to_array(false);
        
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
            $this->log_error('Failed to update item', ['id' => $id, 'error' => $this->get_last_error()]);
            return false;
        }

        // Clear cache
        $this->delete_cache('cig_invoice_items_' . $dto->invoice_id);

        return true;
    }

    /**
     * Delete items by invoice ID
     *
     * @param int $invoice_id Invoice ID
     * @return bool Success status
     */
    public function delete_by_invoice($invoice_id) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot delete items: custom tables not ready');
            return false;
        }

        $table = $this->get_table('items');
        $result = $this->wpdb->delete($table, ['invoice_id' => $invoice_id], ['%d']);

        // Clear cache
        $this->delete_cache('cig_invoice_items_' . $invoice_id);

        $this->log_info('Invoice items deleted', ['invoice_id' => $invoice_id]);

        return $result !== false;
    }

    /**
     * Delete a single item
     *
     * @param int $id Item ID
     * @return bool Success status
     */
    public function delete($id) {
        if (!$this->tables_ready()) {
            $this->log_error('Cannot delete item: custom tables not ready');
            return false;
        }

        // Get item data for cache clearing
        $table = $this->get_table('items');
        $item_data = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $id),
            ARRAY_A
        );

        $result = $this->wpdb->delete($table, ['id' => $id], ['%d']);

        if ($result !== false && $item_data) {
            // Clear cache
            $this->delete_cache('cig_invoice_items_' . $item_data['invoice_id']);
        }

        return $result !== false;
    }

    /**
     * Get total items count for an invoice
     *
     * @param int $invoice_id Invoice ID
     * @return int Item count
     */
    public function get_items_count($invoice_id) {
        if (!$this->tables_ready()) {
            return count($this->get_items_postmeta($invoice_id));
        }

        $table = $this->get_table('items');
        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `invoice_id` = %d",
            $invoice_id
        );

        return (int)$this->wpdb->get_var($query);
    }

    /**
     * Fallback: Get items using postmeta
     *
     * @param int $post_id Invoice post ID
     * @return array Array of CIG_Invoice_Item_DTO objects
     */
    private function get_items_postmeta($post_id) {
        // In the existing system, items are stored as serialized array in postmeta
        $items_data = get_post_meta($post_id, '_cig_invoice_items', true);
        
        if (!is_array($items_data)) {
            return [];
        }

        $items = [];
        $sort_order = 0;
        
        foreach ($items_data as $item) {
            if (empty($item['name'])) {
                continue;
            }

            $item_array = [
                'invoice_id' => 0, // Not stored in postmeta
                'product_id' => $item['product_id'] ?? 0,
                'product_name' => $item['name'] ?? '',
                'product_sku' => $item['sku'] ?? '',
                'quantity' => $item['qty'] ?? 1,
                'unit_price' => $item['price'] ?? 0,
                'line_total' => ($item['qty'] ?? 1) * ($item['price'] ?? 0),
                'warranty' => $item['warranty'] ?? null,
                'item_note' => $item['note'] ?? '',
                'sort_order' => $sort_order++,
                'created_at' => current_time('mysql'),
            ];

            $items[] = CIG_Invoice_Item_DTO::from_array($item_array);
        }

        return $items;
    }
}
