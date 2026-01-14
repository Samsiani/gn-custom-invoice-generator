<?php
/**
 * Customer Data Transfer Object
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Customer_DTO {

    public $id;
    public $name;
    public $tax_id;
    public $address;
    public $phone;
    public $email;
    public $created_at;
    public $updated_at;

    /**
     * Create DTO from array
     *
     * @param array $data Raw data array
     * @return CIG_Customer_DTO
     */
    public static function from_array(array $data) {
        $dto = new self();
        
        $dto->id = isset($data['id']) ? (int)$data['id'] : null;
        $dto->name = $data['name'] ?? '';
        $dto->tax_id = $data['tax_id'] ?? '';
        $dto->address = $data['address'] ?? '';
        $dto->phone = $data['phone'] ?? '';
        $dto->email = $data['email'] ?? '';
        $dto->created_at = $data['created_at'] ?? current_time('mysql');
        $dto->updated_at = $data['updated_at'] ?? current_time('mysql');
        
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
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
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

        if (empty($this->name)) {
            $errors[] = 'Customer name is required';
        }

        if (empty($this->tax_id)) {
            $errors[] = 'Customer tax ID is required';
        }

        if (empty($this->phone)) {
            $errors[] = 'Customer phone is required';
        }

        return $errors;
    }
}
