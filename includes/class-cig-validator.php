<?php
/**
 * Validation & sanitization helpers
 *
 * @package CIG
 * @since 3.0.0
 * @updated 5.0.0 - Added centralized request validation
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Validator {

    /**
     * Validation constants
     */
    const MIN_ITEM_QUANTITY = 0.01;
    const MAX_DISPLAYED_ERRORS = 3;

    /**
     * Validation rules for invoice payload
     * @var array
     */
    private static $invoice_rules = [
        'invoice_number' => ['type' => 'string', 'required' => true, 'sanitize' => 'sanitize_text_field'],
        'buyer.name' => ['type' => 'string', 'required' => true, 'sanitize' => 'sanitize_text_field', 'max_length' => 255],
        'buyer.tax_id' => ['type' => 'string', 'required' => true, 'sanitize' => 'sanitize_text_field', 'max_length' => 100],
        'buyer.phone' => ['type' => 'string', 'required' => true, 'sanitize' => 'sanitize_text_field', 'max_length' => 50],
        'buyer.address' => ['type' => 'string', 'required' => false, 'sanitize' => 'sanitize_textarea_field'],
        'buyer.email' => ['type' => 'email', 'required' => false, 'sanitize' => 'sanitize_email'],
        'general_note' => ['type' => 'string', 'required' => false, 'sanitize' => 'sanitize_textarea_field'],
    ];

    /**
     * Validate and sanitize invoice request payload
     *
     * @param array $data Raw request data
     * @return array ['valid' => bool, 'data' => sanitized data, 'errors' => array of errors]
     */
    public function validate_invoice_request($data) {
        $errors = [];
        $sanitized = [];

        // Validate buyer data
        $buyer = $data['buyer'] ?? [];
        $sanitized['buyer'] = [
            'name' => $this->sanitize_and_validate($buyer['name'] ?? '', 'buyer.name', $errors),
            'tax_id' => $this->sanitize_and_validate($buyer['tax_id'] ?? '', 'buyer.tax_id', $errors),
            'phone' => $this->sanitize_and_validate($buyer['phone'] ?? '', 'buyer.phone', $errors),
            'address' => $this->sanitize_and_validate($buyer['address'] ?? '', 'buyer.address', $errors),
            'email' => $this->sanitize_and_validate($buyer['email'] ?? '', 'buyer.email', $errors),
        ];

        // Validate invoice number
        $sanitized['invoice_number'] = $this->sanitize_and_validate($data['invoice_number'] ?? '', 'invoice_number', $errors);

        // Validate items
        $sanitized['items'] = $this->validate_invoice_items($data['items'] ?? [], $errors);

        // Validate payment history
        $sanitized['payment'] = [
            'history' => $this->validate_payment_history($data['payment']['history'] ?? [], $errors)
        ];

        // General note
        $sanitized['general_note'] = sanitize_textarea_field($data['general_note'] ?? '');

        // Invoice ID for updates
        if (isset($data['invoice_id'])) {
            $sanitized['invoice_id'] = absint($data['invoice_id']);
        }

        return [
            'valid' => empty($errors),
            'data' => $sanitized,
            'errors' => $errors
        ];
    }

    /**
     * Sanitize and validate a single field
     *
     * @param mixed $value Field value
     * @param string $rule_key Rule key from $invoice_rules
     * @param array &$errors Error array (passed by reference)
     * @return mixed Sanitized value
     */
    private function sanitize_and_validate($value, $rule_key, &$errors) {
        if (!isset(self::$invoice_rules[$rule_key])) {
            return sanitize_text_field($value);
        }

        $rule = self::$invoice_rules[$rule_key];
        
        // Apply sanitization
        $sanitized = $value;
        if (isset($rule['sanitize']) && function_exists($rule['sanitize'])) {
            $sanitized = call_user_func($rule['sanitize'], $value);
        }

        // Check required
        if (!empty($rule['required']) && empty($sanitized)) {
            $errors[] = sprintf('%s is required', ucfirst(str_replace('.', ' ', $rule_key)));
        }

        // Check max length
        if (isset($rule['max_length']) && strlen($sanitized) > $rule['max_length']) {
            $sanitized = substr($sanitized, 0, $rule['max_length']);
        }

        // Type-specific validation
        if ($rule['type'] === 'email' && !empty($sanitized) && !is_email($sanitized)) {
            $errors[] = sprintf('%s must be a valid email address', ucfirst(str_replace('.', ' ', $rule_key)));
        }

        return $sanitized;
    }

    /**
     * Validate invoice items array
     *
     * @param array $items Raw items data
     * @param array &$errors Error array
     * @return array Sanitized items
     */
    private function validate_invoice_items($items, &$errors) {
        $sanitized = [];
        
        if (!is_array($items)) {
            $errors[] = 'Items must be an array';
            return $sanitized;
        }

        foreach ($items as $index => $item) {
            $name = sanitize_text_field($item['name'] ?? '');
            if (empty($name)) {
                continue; // Skip empty items
            }

            $sanitized[] = [
                'product_id' => absint($item['product_id'] ?? 0),
                'name' => $name,
                'sku' => sanitize_text_field($item['sku'] ?? ''),
                'brand' => sanitize_text_field($item['brand'] ?? ''),
                'desc' => sanitize_textarea_field($item['desc'] ?? ''),
                'image' => esc_url_raw($item['image'] ?? ''),
                'qty' => max(self::MIN_ITEM_QUANTITY, $this->sanitize_float($item['qty'] ?? 1)),
                'price' => max(0, $this->sanitize_float($item['price'] ?? 0)),
                'total' => max(0, $this->sanitize_float($item['total'] ?? 0)),
                'status' => $this->sanitize_item_status($item['status'] ?? 'none'),
                'reservation_days' => $this->sanitize_reservation_days($item['reservation_days'] ?? 0),
                'warranty' => $this->sanitize_warranty($item['warranty'] ?? ''),
            ];
        }

        if (empty($sanitized)) {
            $errors[] = 'At least one product is required';
        }

        return $sanitized;
    }

    /**
     * Validate payment history array
     *
     * @param array $history Raw payment history
     * @param array &$errors Error array
     * @return array Sanitized history
     */
    private function validate_payment_history($history, &$errors) {
        $sanitized = [];
        
        if (!is_array($history)) {
            return $sanitized;
        }

        $valid_methods = ['company_transfer', 'cash', 'consignment', 'credit', 'other'];

        foreach ($history as $payment) {
            $amount = $this->sanitize_float($payment['amount'] ?? 0);
            if ($amount <= 0) {
                continue; // Skip zero/negative payments
            }

            $method = sanitize_text_field($payment['method'] ?? 'other');
            if (!in_array($method, $valid_methods, true)) {
                $method = 'other';
            }

            $sanitized[] = [
                'date' => $this->sanitize_date($payment['date'] ?? ''),
                'amount' => $amount,
                'method' => $method,
                'comment' => sanitize_text_field($payment['comment'] ?? ''),
                'user_id' => absint($payment['user_id'] ?? get_current_user_id()),
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitize item status
     *
     * @param string $status Raw status
     * @return string Valid status
     */
    private function sanitize_item_status($status) {
        $valid = ['none', 'sold', 'reserved', 'canceled'];
        $status = sanitize_text_field($status);
        return in_array($status, $valid, true) ? $status : 'none';
    }

    /**
     * Sanitize reservation days
     *
     * @param int|string $days Raw reservation days value
     * @return int Valid reservation days (1 to CIG_MAX_RESERVATION_DAYS)
     */
    private function sanitize_reservation_days($days) {
        $d = intval($days);
        if ($d < 1) {
            return 0; // No reservation
        }
        $max = defined('CIG_MAX_RESERVATION_DAYS') ? CIG_MAX_RESERVATION_DAYS : 90;
        return min($d, $max);
    }

    /**
     * Sanitize warranty value
     *
     * @param string $warranty Raw warranty
     * @return string Valid warranty
     */
    private function sanitize_warranty($warranty) {
        $valid = ['', '6m', '1y', '2y', '3y'];
        $warranty = sanitize_text_field($warranty);
        return in_array($warranty, $valid, true) ? $warranty : '';
    }

    /**
     * Sanitize date to MySQL format
     *
     * @param string $date Raw date
     * @return string Formatted date or current date
     */
    private function sanitize_date($date) {
        $date = sanitize_text_field($date);
        
        // Validate date format Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parsed = date_parse($date);
            if ($parsed['error_count'] === 0 && checkdate($parsed['month'], $parsed['day'], $parsed['year'])) {
                return $date;
            }
        }
        
        return current_time('Y-m-d');
    }

    /**
     * Deep sanitize text fields in array/object
     */
    public function sanitize_text_deep($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize_text_deep'], $data);
        }
        if (is_object($data)) {
            foreach ($data as $k => $v) {
                $data->$k = $this->sanitize_text_deep($v);
            }
            return $data;
        }
        return is_scalar($data) ? sanitize_text_field($data) : $data;
    }

    public function sanitize_float($val, $decimals = 2) {
        $num = floatval(is_string($val) ? str_replace(',', '.', $val) : $val);
        return round($num, $decimals);
    }

    public function sanitize_bool($val) {
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    public function validate_date_range($from, $to) {
        if (!$from || !$to) return false;
        $from_ts = strtotime($from . ' 00:00:00');
        $to_ts   = strtotime($to . ' 23:59:59');
        return $from_ts !== false && $to_ts !== false && $from_ts <= $to_ts;
    }

    public function ensure_invoice_number_format($maybe) {
        // Must be like N########
        if (is_string($maybe) && preg_match('/^[Nn][0-9]{8}$/', $maybe)) {
            return strtoupper($maybe);
        }
        return null;
    }

    public function clamp_reservation_days($days) {
        $d = intval($days);
        if ($d < 1) $d = 1;
        if ($d > CIG_MAX_RESERVATION_DAYS) $d = CIG_MAX_RESERVATION_DAYS;
        return $d;
    }
}