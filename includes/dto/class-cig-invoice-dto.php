<?php
/**
 * Invoice Data Transfer Object
 * Strict typed data structure for invoice operations
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_DTO {

    public $id;
    public $post_id;
    public $invoice_number;
    public $buyer_name;
    public $buyer_tax_id;
    public $buyer_address;
    public $buyer_phone;
    public $buyer_email;
    public $customer_id;
    public $invoice_status;
    public $lifecycle_status;
    public $rs_uploaded;
    public $subtotal;
    public $tax_amount;
    public $discount_amount;
    public $total_amount;
    public $paid_amount;
    public $balance;
    public $general_note;
    public $created_by;
    public $created_at;
    public $updated_at;

    /**
     * Create DTO from array
     *
     * @param array $data Raw data array
     * @return CIG_Invoice_DTO
     */
    public static function from_array(array $data) {
        $dto = new self();
        
        $dto->id = isset($data['id']) ? (int)$data['id'] : null;
        $dto->post_id = isset($data['post_id']) ? (int)$data['post_id'] : 0;
        $dto->invoice_number = $data['invoice_number'] ?? '';
        $dto->buyer_name = $data['buyer_name'] ?? '';
        $dto->buyer_tax_id = $data['buyer_tax_id'] ?? '';
        $dto->buyer_address = $data['buyer_address'] ?? '';
        $dto->buyer_phone = $data['buyer_phone'] ?? '';
        $dto->buyer_email = $data['buyer_email'] ?? '';
        $dto->customer_id = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
        $dto->invoice_status = $data['invoice_status'] ?? 'standard';
        $dto->lifecycle_status = $data['lifecycle_status'] ?? 'unfinished';
        $dto->rs_uploaded = isset($data['rs_uploaded']) ? (bool)$data['rs_uploaded'] : false;
        $dto->subtotal = isset($data['subtotal']) ? (float)$data['subtotal'] : 0.00;
        $dto->tax_amount = isset($data['tax_amount']) ? (float)$data['tax_amount'] : 0.00;
        $dto->discount_amount = isset($data['discount_amount']) ? (float)$data['discount_amount'] : 0.00;
        $dto->total_amount = isset($data['total_amount']) ? (float)$data['total_amount'] : 0.00;
        $dto->paid_amount = isset($data['paid_amount']) ? (float)$data['paid_amount'] : 0.00;
        $dto->balance = isset($data['balance']) ? (float)$data['balance'] : 0.00;
        $dto->general_note = $data['general_note'] ?? '';
        $dto->created_by = isset($data['created_by']) ? (int)$data['created_by'] : null;
        $dto->created_at = $data['created_at'] ?? current_time('mysql');
        $dto->updated_at = $data['updated_at'] ?? current_time('mysql');
        
        return $dto;
    }

    /**
     * Create DTO from postmeta (for backward compatibility)
     *
     * @param int $post_id Invoice post ID
     * @return CIG_Invoice_DTO|null
     */
    public static function from_postmeta($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'invoice') {
            return null;
        }

        $dto = new self();
        $dto->post_id = $post_id;
        $dto->invoice_number = get_post_meta($post_id, '_cig_invoice_number', true);
        $dto->buyer_name = get_post_meta($post_id, '_cig_buyer_name', true);
        $dto->buyer_tax_id = get_post_meta($post_id, '_cig_buyer_tax_id', true);
        $dto->buyer_address = get_post_meta($post_id, '_cig_buyer_address', true);
        $dto->buyer_phone = get_post_meta($post_id, '_cig_buyer_phone', true);
        $dto->buyer_email = get_post_meta($post_id, '_cig_buyer_email', true);
        $dto->customer_id = (int)get_post_meta($post_id, '_cig_customer_id', true) ?: null;
        $dto->invoice_status = get_post_meta($post_id, '_cig_invoice_status', true) ?: 'standard';
        $dto->lifecycle_status = get_post_meta($post_id, '_cig_lifecycle_status', true) ?: 'unfinished';
        $dto->rs_uploaded = (bool)get_post_meta($post_id, '_cig_rs_uploaded', true);
        $dto->subtotal = (float)get_post_meta($post_id, '_cig_subtotal', true);
        $dto->tax_amount = (float)get_post_meta($post_id, '_cig_tax_amount', true);
        $dto->discount_amount = (float)get_post_meta($post_id, '_cig_discount_amount', true);
        $dto->total_amount = (float)get_post_meta($post_id, '_cig_total_amount', true);
        $dto->paid_amount = (float)get_post_meta($post_id, '_cig_paid_amount', true);
        $dto->balance = (float)get_post_meta($post_id, '_cig_balance', true);
        $dto->general_note = get_post_meta($post_id, '_cig_general_note', true);
        $dto->created_by = (int)$post->post_author ?: null;
        $dto->created_at = $post->post_date;
        $dto->updated_at = $post->post_modified;

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
            'post_id' => $this->post_id,
            'invoice_number' => $this->invoice_number,
            'buyer_name' => $this->buyer_name,
            'buyer_tax_id' => $this->buyer_tax_id,
            'buyer_address' => $this->buyer_address,
            'buyer_phone' => $this->buyer_phone,
            'buyer_email' => $this->buyer_email,
            'customer_id' => $this->customer_id,
            'invoice_status' => $this->invoice_status,
            'lifecycle_status' => $this->lifecycle_status,
            'rs_uploaded' => $this->rs_uploaded ? 1 : 0,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'balance' => $this->balance,
            'general_note' => $this->general_note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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

        if (empty($this->invoice_number)) {
            $errors[] = 'Invoice number is required';
        }

        if (empty($this->buyer_name)) {
            $errors[] = 'Buyer name is required';
        }

        if (empty($this->buyer_tax_id)) {
            $errors[] = 'Buyer tax ID is required';
        }

        if (empty($this->buyer_phone)) {
            $errors[] = 'Buyer phone is required';
        }

        if ($this->post_id <= 0) {
            $errors[] = 'Valid post ID is required';
        }

        // Validate status values
        $valid_statuses = ['standard', 'fictive', 'proforma'];
        if (!in_array($this->invoice_status, $valid_statuses, true)) {
            $errors[] = 'Invalid invoice status';
        }

        $valid_lifecycle = ['unfinished', 'completed', 'cancelled'];
        if (!in_array($this->lifecycle_status, $valid_lifecycle, true)) {
            $errors[] = 'Invalid lifecycle status';
        }

        return $errors;
    }
}
