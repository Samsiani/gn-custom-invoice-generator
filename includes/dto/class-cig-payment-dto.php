<?php
/**
 * Payment Data Transfer Object
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Payment_DTO {

    public $id;
    public $invoice_id;
    public $date;  // Changed from payment_date to match database (type: date)
    public $payment_method;
    public $amount;
    public $transaction_ref;
    public $note;
    public $user_id;  // Changed from created_by to match database
    public $created_at;

    /**
     * Create DTO from array
     *
     * @param array $data Raw data array
     * @return CIG_Payment_DTO
     */
    public static function from_array(array $data) {
        $dto = new self();
        
        $dto->id = isset($data['id']) ? (int)$data['id'] : null;
        $dto->invoice_id = isset($data['invoice_id']) ? (int)$data['invoice_id'] : 0;
        // Support both old and new column names, convert datetime to date format for database
        $payment_date = $data['date'] ?? $data['payment_date'] ?? current_time('mysql');
        // Extract just the date part (YYYY-MM-DD) from datetime if needed
        $dto->date = date('Y-m-d', strtotime($payment_date));
        $dto->payment_method = $data['payment_method'] ?? $data['method'] ?? '';
        $dto->amount = isset($data['amount']) ? (float)$data['amount'] : 0.00;
        $dto->transaction_ref = $data['transaction_ref'] ?? $data['ref'] ?? '';
        $dto->note = $data['note'] ?? $data['comment'] ?? '';
        // Support both old and new column names
        $dto->user_id = isset($data['user_id']) ? (int)$data['user_id'] : (isset($data['created_by']) ? (int)$data['created_by'] : get_current_user_id());
        $dto->created_at = $data['created_at'] ?? current_time('mysql');
        
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
            'date' => $this->date,
            'payment_method' => $this->payment_method,
            'amount' => $this->amount,
            'transaction_ref' => $this->transaction_ref,
            'note' => $this->note,
            'user_id' => $this->user_id,
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

        if (empty($this->date)) {
            $errors[] = 'Payment date is required';
        }

        if ($this->amount <= 0) {
            $errors[] = 'Payment amount must be greater than 0';
        }

        return $errors;
    }
}
