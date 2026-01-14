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
    public $qty;  // Changed from quantity to match database
    public $price;  // Changed from unit_price to match database
    public $total;  // Changed from line_total to match database
    public $warranty;
    public $item_note;
    public $sort_order;
    public $reservation_expires_at;  // New field from database
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
        // Support both old and new column names
        $dto->qty = isset($data['qty']) ? (float)$data['qty'] : (isset($data['quantity']) ? (float)$data['quantity'] : 1.00);
        $dto->price = isset($data['price']) ? (float)$data['price'] : (isset($data['unit_price']) ? (float)$data['unit_price'] : 0.00);
        $dto->total = isset($data['total']) ? (float)$data['total'] : (isset($data['line_total']) ? (float)$data['line_total'] : 0.00);
        $dto->warranty = $data['warranty'] ?? null;
        $dto->item_note = $data['item_note'] ?? $data['note'] ?? '';
        $dto->sort_order = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;
        $dto->reservation_expires_at = $data['reservation_expires_at'] ?? null;
        $dto->created_at = $data['created_at'] ?? current_time('mysql');
        
        // Calculate total if not provided (with epsilon for float comparison)
        if (abs($dto->total) < 0.01 && $dto->qty > 0 && $dto->price > 0) {
            $dto->total = $dto->qty * $dto->price;
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
            'qty' => $this->qty,
            'price' => $this->price,
            'total' => $this->total,
            'warranty' => $this->warranty,
            'item_note' => $this->item_note,
            'sort_order' => $this->sort_order,
            'reservation_expires_at' => $this->reservation_expires_at,
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

        if ($this->qty <= 0) {
            $errors[] = 'Quantity must be greater than 0';
        }

        if ($this->price < 0) {
            $errors[] = 'Unit price cannot be negative';
        }

        return $errors;
    }
}
