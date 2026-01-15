<?php
/**
 * Invoice Data Transfer Object
 * Strict typed data structure for invoice operations
 *
 * @package CIG
 * @since 5.0.0
 * @updated 5.0.1 - Added PHP 7.4+ typed properties
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_DTO {

    /** @var int|null */
    public ?int $id = null;
    
    /** @var int Post ID reference for backward compatibility */
    public int $old_post_id = 0;
    
    /** @var string Invoice number (required) */
    public string $invoice_number = '';
    
    /** @var string Buyer/customer name */
    public string $buyer_name = '';
    
    /** @var string Tax identification number */
    public string $buyer_tax_id = '';
    
    /** @var string Buyer address */
    public string $buyer_address = '';
    
    /** @var string Buyer phone number */
    public string $buyer_phone = '';
    
    /** @var string Buyer email address */
    public string $buyer_email = '';
    
    /** @var int|null Customer ID reference */
    public ?int $customer_id = null;
    
    /** @var string Invoice type (standard, fictive, proforma) */
    public string $type = 'standard';
    
    /** @var string Lifecycle status (unfinished, completed, cancelled, reserved) */
    public string $status = 'unfinished';
    
    /** @var bool RS (Revenue Service) upload status */
    public bool $rs_uploaded = false;
    
    /** @var float Subtotal before tax and discount */
    public float $subtotal = 0.00;
    
    /** @var float Tax amount */
    public float $tax_amount = 0.00;
    
    /** @var float Discount amount */
    public float $discount_amount = 0.00;
    
    /** @var float Total invoice amount */
    public float $total_amount = 0.00;
    
    /** @var float Amount paid */
    public float $paid_amount = 0.00;
    
    /** @var float Remaining balance */
    public float $balance = 0.00;
    
    /** @var string General notes */
    public string $general_note = '';
    
    /** @var int|null Author/creator user ID */
    public ?int $author_id = null;
    
    /** @var string Creation timestamp (MySQL datetime) */
    public string $created_at = '';
    
    /** @var string Last update timestamp (MySQL datetime) */
    public string $updated_at = '';
    
    /** @var string|null Activation date (when invoice became active) */
    public ?string $activation_date = null;

    /**
     * Create DTO from array
     *
     * @param array|null $data Raw data array
     * @return CIG_Invoice_DTO|null
     */
    public static function from_array($data = null): ?CIG_Invoice_DTO {
        if ($data === null || !is_array($data)) {
            return null;
        }
        
        $dto = new self();
        
        $dto->id = isset($data['id']) ? (int)$data['id'] : null;
        // Support both old and new column names for compatibility
        $dto->old_post_id = isset($data['old_post_id']) ? (int)$data['old_post_id'] : (isset($data['post_id']) ? (int)$data['post_id'] : 0);
        $dto->invoice_number = $data['invoice_number'] ?? '';
        $dto->buyer_name = $data['buyer_name'] ?? '';
        $dto->buyer_tax_id = $data['buyer_tax_id'] ?? '';
        $dto->buyer_address = $data['buyer_address'] ?? '';
        $dto->buyer_phone = $data['buyer_phone'] ?? '';
        $dto->buyer_email = $data['buyer_email'] ?? '';
        $dto->customer_id = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
        // Support both old and new column names
        $dto->type = $data['type'] ?? $data['invoice_status'] ?? 'standard';
        $dto->status = $data['status'] ?? $data['lifecycle_status'] ?? 'unfinished';
        $dto->rs_uploaded = isset($data['rs_uploaded']) ? (bool)$data['rs_uploaded'] : false;
        $dto->subtotal = isset($data['subtotal']) ? (float)$data['subtotal'] : 0.00;
        $dto->tax_amount = isset($data['tax_amount']) ? (float)$data['tax_amount'] : 0.00;
        $dto->discount_amount = isset($data['discount_amount']) ? (float)$data['discount_amount'] : 0.00;
        $dto->total_amount = isset($data['total_amount']) ? (float)$data['total_amount'] : 0.00;
        $dto->paid_amount = isset($data['paid_amount']) ? (float)$data['paid_amount'] : 0.00;
        $dto->balance = isset($data['balance']) ? (float)$data['balance'] : 0.00;
        $dto->general_note = $data['general_note'] ?? '';
        // Support both old and new column names
        $dto->author_id = isset($data['author_id']) ? (int)$data['author_id'] : (isset($data['created_by']) ? (int)$data['created_by'] : null);
        $dto->created_at = $data['created_at'] ?? current_time('mysql');
        $dto->updated_at = $data['updated_at'] ?? current_time('mysql');
        $dto->activation_date = $data['activation_date'] ?? null;
        
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
        $dto->old_post_id = $post_id;
        $dto->invoice_number = get_post_meta($post_id, '_cig_invoice_number', true);
        $dto->buyer_name = get_post_meta($post_id, '_cig_buyer_name', true);
        $dto->buyer_tax_id = get_post_meta($post_id, '_cig_buyer_tax_id', true);
        $dto->buyer_address = get_post_meta($post_id, '_cig_buyer_address', true);
        $dto->buyer_phone = get_post_meta($post_id, '_cig_buyer_phone', true);
        $dto->buyer_email = get_post_meta($post_id, '_cig_buyer_email', true);
        $dto->customer_id = (int)get_post_meta($post_id, '_cig_customer_id', true) ?: null;
        $dto->type = get_post_meta($post_id, '_cig_invoice_status', true) ?: 'standard';
        $dto->status = get_post_meta($post_id, '_cig_lifecycle_status', true) ?: 'unfinished';
        $dto->rs_uploaded = (bool)get_post_meta($post_id, '_cig_rs_uploaded', true);
        $dto->subtotal = (float)get_post_meta($post_id, '_cig_subtotal', true);
        $dto->tax_amount = (float)get_post_meta($post_id, '_cig_tax_amount', true);
        $dto->discount_amount = (float)get_post_meta($post_id, '_cig_discount_amount', true);
        $dto->total_amount = (float)get_post_meta($post_id, '_cig_total_amount', true);
        $dto->paid_amount = (float)get_post_meta($post_id, '_cig_paid_amount', true);
        $dto->balance = (float)get_post_meta($post_id, '_cig_balance', true);
        $dto->general_note = get_post_meta($post_id, '_cig_general_note', true);
        $dto->author_id = (int)$post->post_author ?: null;
        $dto->created_at = $post->post_date;
        $dto->updated_at = $post->post_modified;
        $dto->activation_date = get_post_meta($post_id, '_cig_activation_date', true) ?: null;

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
            'old_post_id' => $this->old_post_id,
            'invoice_number' => $this->invoice_number,
            'buyer_name' => $this->buyer_name,
            'buyer_tax_id' => $this->buyer_tax_id,
            'buyer_address' => $this->buyer_address,
            'buyer_phone' => $this->buyer_phone,
            'buyer_email' => $this->buyer_email,
            'customer_id' => $this->customer_id,
            'type' => $this->type,
            'status' => $this->status,
            'rs_uploaded' => $this->rs_uploaded ? 1 : 0,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'balance' => $this->balance,
            'general_note' => $this->general_note,
            'author_id' => $this->author_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'activation_date' => $this->activation_date,
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

        if ($this->old_post_id <= 0) {
            $errors[] = 'Valid post ID is required';
        }

        // Validate status values
        $valid_statuses = ['standard', 'fictive', 'proforma'];
        if (!in_array($this->type, $valid_statuses, true)) {
            $errors[] = 'Invalid invoice status';
        }

        $valid_lifecycle = ['unfinished', 'completed', 'cancelled', 'reserved'];
        if (!in_array($this->status, $valid_lifecycle, true)) {
            $errors[] = 'Invalid lifecycle status';
        }

        return $errors;
    }
}
