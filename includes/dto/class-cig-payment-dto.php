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
    public $payment_date;
    public $payment_method;
    public $amount;
    public $transaction_ref;
    public $note;
    public $created_by;
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
        $dto->payment_date = $data['payment_date'] ?? $data['date'] ?? current_time('mysql');
        $dto->payment_method = $data['payment_method'] ?? $data['method'] ?? '';
        $dto->amount = isset($data['amount']) ? (float)$data['amount'] : 0.00;
        $dto->transaction_ref = $data['transaction_ref'] ?? $data['ref'] ?? '';
        $dto->note = $data['note'] ?? $data['comment'] ?? '';
        $dto->created_by = isset($data['created_by']) ? (int)$data['created_by'] : get_current_user_id();
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
            'payment_date' => $this->payment_date,
            'payment_method' => $this->payment_method,
            'amount' => $this->amount,
            'transaction_ref' => $this->transaction_ref,
            'note' => $this->note,
            'created_by' => $this->created_by,
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

        if (empty($this->payment_date)) {
            $errors[] = 'Payment date is required';
        }

        if ($this->amount <= 0) {
            $errors[] = 'Payment amount must be greater than 0';
        }

        return $errors;
    }
}
