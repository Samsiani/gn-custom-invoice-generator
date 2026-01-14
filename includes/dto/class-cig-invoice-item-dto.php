<?php
/**
 * Invoice Item Data Transfer Object
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_Item_DTO {

    public $id;
    public $invoice_id;
    public $product_id;
    public $product_name;
    public $product_sku;
    public $quantity;
    public $unit_price;
    public $line_total;
    public $warranty;
    public $item_note;
    public $sort_order;
    public $created_at;

    /**
     * Create DTO from array
     *
     * @param array $data Raw data array
     * @return CIG_Invoice_Item_DTO
     */
    public static function from_array(array $data) {
        $dto = new self();
        
        $dto->id = isset($data['id']) ? (int)$data['id'] : null;
        $dto->invoice_id = isset($data['invoice_id']) ? (int)$data['invoice_id'] : 0;
        $dto->product_id = isset($data['product_id']) ? (int)$data['product_id'] : 0;
        $dto->product_name = $data['product_name'] ?? $data['name'] ?? '';
        $dto->product_sku = $data['product_sku'] ?? $data['sku'] ?? '';
        $dto->quantity = isset($data['quantity']) ? (float)$data['quantity'] : 1.00;
        $dto->unit_price = isset($data['unit_price']) ? (float)$data['unit_price'] : 0.00;
        $dto->line_total = isset($data['line_total']) ? (float)$data['line_total'] : 0.00;
        $dto->warranty = $data['warranty'] ?? null;
        $dto->item_note = $data['item_note'] ?? $data['note'] ?? '';
        $dto->sort_order = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;
        $dto->created_at = $data['created_at'] ?? current_time('mysql');
        
        // Calculate line total if not provided
        if ($dto->line_total == 0 && $dto->quantity > 0 && $dto->unit_price > 0) {
            $dto->line_total = $dto->quantity * $dto->unit_price;
        }
        
        return $dto;
    }

    /**
     * Convert DTO to array
     *
     * @param bool $include_id Include ID field
     * @return array
     */
    public function to_array($include_id = false) {
        $data = [
            'invoice_id' => $this->invoice_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'warranty' => $this->warranty,
            'item_note' => $this->item_note,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
        ];

        if ($include_id && $this->id) {
            $data['id'] = $this->id;
        }

        return $data;
    }

    /**
     * Validate DTO data
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate() {
        $errors = [];

        if ($this->invoice_id <= 0) {
            $errors[] = 'Valid invoice ID is required';
        }

        if ($this->product_id <= 0) {
            $errors[] = 'Valid product ID is required';
        }

        if (empty($this->product_name)) {
            $errors[] = 'Product name is required';
        }

        if ($this->quantity <= 0) {
            $errors[] = 'Quantity must be greater than 0';
        }

        if ($this->unit_price < 0) {
            $errors[] = 'Unit price cannot be negative';
        }

        return $errors;
    }
}
