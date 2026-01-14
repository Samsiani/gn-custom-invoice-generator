<?php
/**
 * Abstract Repository Base Class
 * Provides common database operations and utilities
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Abstract_CIG_Repository {

    /**
     * @var wpdb
     */
    protected $wpdb;

    /**
     * @var CIG_Database
     */
    protected $database;

    /**
     * @var CIG_Logger
     */
    protected $logger;

    /**
     * @var CIG_Cache
     */
    protected $cache;

    /**
     * Constructor
     *
     * @param CIG_Database $database
     * @param CIG_Logger $logger
     * @param CIG_Cache $cache
     */
    public function __construct($database = null, $logger = null, $cache = null) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->database = $database;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Get table name
     *
     * @param string $table Table key
     * @return string Full table name with prefix
     */
    protected function get_table($table) {
        return $this->database ? $this->database->get_table_name($table) : '';
    }

    /**
     * Log error
     *
     * @param string $message Error message
     * @param array $context Additional context
     */
    protected function log_error($message, $context = []) {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }

    /**
     * Log info
     *
     * @param string $message Info message
     * @param array $context Additional context
     */
    protected function log_info($message, $context = []) {
        if ($this->logger) {
            $this->logger->info($message, $context);
        }
    }

    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @return mixed|false False if not found
     */
    protected function get_cache($key) {
        if (!$this->cache) {
            return false;
        }
        return $this->cache->get($key);
    }

    /**
     * Set cached data
     *
     * @param string $key Cache key
     * @param mixed $value Data to cache
     * @param int $expiry Expiry time in seconds
     * @return bool Success status
     */
    protected function set_cache($key, $value, $expiry = null) {
        if (!$this->cache) {
            return false;
        }
        return $this->cache->set($key, $value, $expiry);
    }

    /**
     * Delete cached data
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    protected function delete_cache($key) {
        if (!$this->cache) {
            return false;
        }
        return $this->cache->delete($key);
    }

    /**
     * Build WHERE clause from filters
     *
     * @param array $filters Filter conditions
     * @param array &$values Values array (passed by reference)
     * @return string WHERE clause
     */
    protected function build_where_clause($filters, &$values) {
        $where = [];
        
        foreach ($filters as $key => $value) {
            if (is_null($value)) {
                continue;
            }

            // Handle date range filtering with activated_at fallback
            if ($key === 'date_from' || $key === 'date_to') {
                continue; // These are handled separately
            }

            if (is_array($value)) {
                // IN clause
                $placeholders = implode(',', array_fill(0, count($value), '%s'));
                $where[] = "`{$key}` IN ({$placeholders})";
                $values = array_merge($values, $value);
            } else {
                $where[] = "`{$key}` = %s";
                $values[] = $value;
            }
        }
        
        // Handle date range filtering with activated_at fallback to created_at
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $date_conditions = [];
            
            if (isset($filters['date_from'])) {
                $date_conditions[] = "COALESCE(`activated_at`, `created_at`) >= %s";
                $values[] = $filters['date_from'];
            }
            
            if (isset($filters['date_to'])) {
                $date_conditions[] = "COALESCE(`activated_at`, `created_at`) <= %s";
                $values[] = $filters['date_to'];
            }
            
            if (!empty($date_conditions)) {
                $where[] = '(' . implode(' AND ', $date_conditions) . ')';
            }
        }

        return !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    }

    /**
     * Build ORDER BY clause
     *
     * @param string $order_by Column name
     * @param string $order Direction (ASC/DESC)
     * @return string ORDER BY clause
     */
    protected function build_order_clause($order_by, $order = 'DESC') {
        $order = strtoupper($order);
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        // Use activated_at with fallback to created_at for default ordering
        if ($order_by === 'created_at' || $order_by === 'activated_at') {
            return "ORDER BY COALESCE(`activated_at`, `created_at`) {$order}";
        }

        return $order_by ? "ORDER BY `{$order_by}` {$order}" : '';
    }

    /**
     * Build LIMIT clause
     *
     * @param int $limit Number of records
     * @param int $offset Offset
     * @return string LIMIT clause
     */
    protected function build_limit_clause($limit = null, $offset = 0) {
        if ($limit === null) {
            return '';
        }

        $offset = max(0, (int)$offset);
        $limit = max(1, (int)$limit);

        return $offset > 0 ? "LIMIT {$offset}, {$limit}" : "LIMIT {$limit}";
    }

    /**
     * Check if custom tables are ready
     *
     * @return bool
     */
    protected function tables_ready() {
        return $this->database && $this->database->tables_exist();
    }

    /**
     * Get last insert ID
     *
     * @return int
     */
    protected function get_insert_id() {
        return (int)$this->wpdb->insert_id;
    }

    /**
     * Get last error
     *
     * @return string
     */
    protected function get_last_error() {
        return $this->wpdb->last_error;
    }
}
