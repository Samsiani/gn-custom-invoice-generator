<?php
/**
 * Database Manager - Custom Tables Handler
 * Manages custom database tables for high-performance invoice operations
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Database {

    /**
     * Database version for schema migrations
     */
    const DB_VERSION = '5.0.0';
    const DB_VERSION_OPTION = 'cig_db_version';

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * Table names (without prefix)
     */
    private $tables = [
        'invoices' => 'cig_invoices',
        'items' => 'cig_invoice_items',
        'payments' => 'cig_payments',
    ];

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Get full table name with prefix
     *
     * @param string $table Table key (invoices, items, payments)
     * @return string Full table name with prefix
     */
    public function get_table_name($table) {
        if (!isset($this->tables[$table])) {
            return '';
        }
        return $this->wpdb->prefix . $this->tables[$table];
    }

    /**
     * Create all custom tables
     *
     * @return bool Success status
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $this->wpdb->get_charset_collate();
        $created = true;

        // Table 1: wp_cig_invoices
        $table_invoices = $this->get_table_name('invoices');
        $sql_invoices = "CREATE TABLE IF NOT EXISTS `{$table_invoices}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `post_id` bigint(20) unsigned NOT NULL,
            `invoice_number` varchar(50) NOT NULL,
            `buyer_name` varchar(255) DEFAULT NULL,
            `buyer_tax_id` varchar(100) DEFAULT NULL,
            `buyer_address` text DEFAULT NULL,
            `buyer_phone` varchar(50) DEFAULT NULL,
            `buyer_email` varchar(100) DEFAULT NULL,
            `customer_id` bigint(20) unsigned DEFAULT NULL,
            `invoice_status` varchar(20) DEFAULT 'standard',
            `lifecycle_status` varchar(20) DEFAULT 'unfinished',
            `rs_uploaded` tinyint(1) DEFAULT 0,
            `subtotal` decimal(10,2) DEFAULT 0.00,
            `tax_amount` decimal(10,2) DEFAULT 0.00,
            `discount_amount` decimal(10,2) DEFAULT 0.00,
            `total_amount` decimal(10,2) DEFAULT 0.00,
            `paid_amount` decimal(10,2) DEFAULT 0.00,
            `balance` decimal(10,2) DEFAULT 0.00,
            `general_note` text DEFAULT NULL,
            `created_by` bigint(20) unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `post_id` (`post_id`),
            KEY `customer_id` (`customer_id`),
            KEY `invoice_status` (`invoice_status`),
            KEY `created_by` (`created_by`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_invoices);
        
        // Verify table was created
        if ($this->wpdb->last_error) {
            $created = false;
        }

        // Table 2: wp_cig_invoice_items
        $table_items = $this->get_table_name('items');
        $sql_items = "CREATE TABLE IF NOT EXISTS `{$table_items}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `invoice_id` bigint(20) unsigned NOT NULL,
            `product_id` bigint(20) unsigned NOT NULL,
            `product_name` varchar(255) NOT NULL,
            `product_sku` varchar(100) DEFAULT NULL,
            `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
            `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
            `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
            `warranty` varchar(20) DEFAULT NULL,
            `item_note` text DEFAULT NULL,
            `sort_order` int(11) DEFAULT 0,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `invoice_id` (`invoice_id`),
            KEY `product_id` (`product_id`),
            KEY `sort_order` (`sort_order`)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_items);
        
        if ($this->wpdb->last_error) {
            $created = false;
        }

        // Table 3: wp_cig_payments
        $table_payments = $this->get_table_name('payments');
        $sql_payments = "CREATE TABLE IF NOT EXISTS `{$table_payments}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `invoice_id` bigint(20) unsigned NOT NULL,
            `payment_date` datetime NOT NULL,
            `payment_method` varchar(50) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
            `transaction_ref` varchar(100) DEFAULT NULL,
            `note` text DEFAULT NULL,
            `created_by` bigint(20) unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `invoice_id` (`invoice_id`),
            KEY `payment_date` (`payment_date`),
            KEY `payment_method` (`payment_method`)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_payments);
        
        if ($this->wpdb->last_error) {
            $created = false;
        }

        // Update database version
        if ($created) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        }

        return $created;
    }

    /**
     * Check if custom tables exist
     *
     * @return bool True if all tables exist
     */
    public function tables_exist() {
        $tables_to_check = [
            $this->get_table_name('invoices'),
            $this->get_table_name('items'),
            $this->get_table_name('payments'),
        ];

        foreach ($tables_to_check as $table) {
            $query = $this->wpdb->prepare('SHOW TABLES LIKE %s', $table);
            if ($this->wpdb->get_var($query) !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if tables have data
     *
     * @return bool True if invoices table has records
     */
    public function tables_have_data() {
        $table = $this->get_table_name('invoices');
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        return (int)$count > 0;
    }

    /**
     * Get database health status
     *
     * @return array Health status information
     */
    public function get_health_status() {
        $status = [
            'tables_exist' => $this->tables_exist(),
            'has_data' => false,
            'version' => get_option(self::DB_VERSION_OPTION, '0.0.0'),
            'table_count' => [],
        ];

        if ($status['tables_exist']) {
            $status['has_data'] = $this->tables_have_data();
            
            // Get record counts
            $tables = ['invoices', 'items', 'payments'];
            foreach ($tables as $table) {
                $table_name = $this->get_table_name($table);
                $count = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
                $status['table_count'][$table] = (int)$count;
            }
        }

        return $status;
    }

    /**
     * Drop all custom tables (for rollback)
     *
     * @return bool Success status
     */
    public function drop_tables() {
        $tables = [
            $this->get_table_name('payments'),
            $this->get_table_name('items'),
            $this->get_table_name('invoices'),
        ];

        foreach ($tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(self::DB_VERSION_OPTION);

        return true;
    }

    /**
     * Truncate all custom tables (for testing)
     *
     * @return bool Success status
     */
    public function truncate_tables() {
        $tables = [
            $this->get_table_name('payments'),
            $this->get_table_name('items'),
            $this->get_table_name('invoices'),
        ];

        foreach ($tables as $table) {
            $this->wpdb->query("TRUNCATE TABLE `{$table}`");
        }

        return true;
    }

    /**
     * Verify database integrity
     *
     * @return array Integrity check results
     */
    public function verify_integrity() {
        $results = [
            'status' => 'ok',
            'errors' => [],
        ];

        if (!$this->tables_exist()) {
            $results['status'] = 'error';
            $results['errors'][] = 'One or more custom tables do not exist';
            return $results;
        }

        // Check for orphaned items (items without invoice)
        $table_items = $this->get_table_name('items');
        $table_invoices = $this->get_table_name('invoices');
        
        $orphaned_items = $this->wpdb->get_var("
            SELECT COUNT(*) 
            FROM `{$table_items}` i 
            LEFT JOIN `{$table_invoices}` inv ON i.invoice_id = inv.id 
            WHERE inv.id IS NULL
        ");

        if ($orphaned_items > 0) {
            $results['status'] = 'warning';
            $results['errors'][] = "Found {$orphaned_items} orphaned invoice items";
        }

        // Check for orphaned payments
        $table_payments = $this->get_table_name('payments');
        
        $orphaned_payments = $this->wpdb->get_var("
            SELECT COUNT(*) 
            FROM `{$table_payments}` p 
            LEFT JOIN `{$table_invoices}` inv ON p.invoice_id = inv.id 
            WHERE inv.id IS NULL
        ");

        if ($orphaned_payments > 0) {
            $results['status'] = 'warning';
            $results['errors'][] = "Found {$orphaned_payments} orphaned payments";
        }

        return $results;
    }

    /**
     * Run schema migrations if needed
     *
     * @return bool Success status
     */
    public function maybe_migrate_schema() {
        $current_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            // Schema needs update
            // First try to create tables (for fresh installs)
            $this->create_tables();
            
            // Then run migration to add any missing columns (for existing tables)
            return $this->migrate_schema_to_current();
        }

        return true;
    }

    /**
     * Migrate schema to current version by adding missing columns
     *
     * @return bool Success status
     */
    private function migrate_schema_to_current() {
        $success = true;
        
        // Migrate invoices table
        $table_invoices = $this->get_table_name('invoices');
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_invoices}'") === $table_invoices) {
            // Add missing columns to invoices table
            $columns_to_add = [
                'post_id' => "ADD COLUMN `post_id` bigint(20) unsigned NOT NULL AFTER `id`, ADD KEY `post_id` (`post_id`)",
                'buyer_name' => "ADD COLUMN `buyer_name` varchar(255) DEFAULT NULL AFTER `invoice_number`",
                'buyer_tax_id' => "ADD COLUMN `buyer_tax_id` varchar(100) DEFAULT NULL AFTER `buyer_name`",
                'buyer_address' => "ADD COLUMN `buyer_address` text DEFAULT NULL AFTER `buyer_tax_id`",
                'buyer_phone' => "ADD COLUMN `buyer_phone` varchar(50) DEFAULT NULL AFTER `buyer_address`",
                'buyer_email' => "ADD COLUMN `buyer_email` varchar(100) DEFAULT NULL AFTER `buyer_phone`",
                'customer_id' => "ADD COLUMN `customer_id` bigint(20) unsigned DEFAULT NULL AFTER `buyer_email`, ADD KEY `customer_id` (`customer_id`)",
                'invoice_status' => "ADD COLUMN `invoice_status` varchar(20) DEFAULT 'standard' AFTER `customer_id`, ADD KEY `invoice_status` (`invoice_status`)",
                'lifecycle_status' => "ADD COLUMN `lifecycle_status` varchar(20) DEFAULT 'unfinished' AFTER `invoice_status`",
                'rs_uploaded' => "ADD COLUMN `rs_uploaded` tinyint(1) DEFAULT 0 AFTER `lifecycle_status`",
                'subtotal' => "ADD COLUMN `subtotal` decimal(10,2) DEFAULT 0.00 AFTER `rs_uploaded`",
                'tax_amount' => "ADD COLUMN `tax_amount` decimal(10,2) DEFAULT 0.00 AFTER `subtotal`",
                'discount_amount' => "ADD COLUMN `discount_amount` decimal(10,2) DEFAULT 0.00 AFTER `tax_amount`",
                'total_amount' => "ADD COLUMN `total_amount` decimal(10,2) DEFAULT 0.00 AFTER `discount_amount`",
                'paid_amount' => "ADD COLUMN `paid_amount` decimal(10,2) DEFAULT 0.00 AFTER `total_amount`",
                'balance' => "ADD COLUMN `balance` decimal(10,2) DEFAULT 0.00 AFTER `paid_amount`",
                'general_note' => "ADD COLUMN `general_note` text DEFAULT NULL AFTER `balance`",
                'created_by' => "ADD COLUMN `created_by` bigint(20) unsigned DEFAULT NULL AFTER `general_note`, ADD KEY `created_by` (`created_by`)",
                'created_at' => "ADD COLUMN `created_at` datetime NOT NULL AFTER `created_by`, ADD KEY `created_at` (`created_at`)",
                'updated_at' => "ADD COLUMN `updated_at` datetime NOT NULL AFTER `created_at`",
            ];
            
            foreach ($columns_to_add as $column => $alter_statement) {
                if (!$this->column_exists($table_invoices, $column)) {
                    $result = $this->wpdb->query("ALTER TABLE `{$table_invoices}` {$alter_statement}");
                    if ($result === false) {
                        $success = false;
                    }
                }
            }
        }
        
        // Migrate items table
        $table_items = $this->get_table_name('items');
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_items}'") === $table_items) {
            $columns_to_add = [
                'product_id' => "ADD COLUMN `product_id` bigint(20) unsigned NOT NULL AFTER `invoice_id`, ADD KEY `product_id` (`product_id`)",
                'product_name' => "ADD COLUMN `product_name` varchar(255) NOT NULL AFTER `product_id`",
                'product_sku' => "ADD COLUMN `product_sku` varchar(100) DEFAULT NULL AFTER `product_name`",
                'quantity' => "ADD COLUMN `quantity` decimal(10,2) NOT NULL DEFAULT 1.00 AFTER `product_sku`",
                'unit_price' => "ADD COLUMN `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `quantity`",
                'line_total' => "ADD COLUMN `line_total` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`",
                'warranty' => "ADD COLUMN `warranty` varchar(20) DEFAULT NULL AFTER `line_total`",
                'item_note' => "ADD COLUMN `item_note` text DEFAULT NULL AFTER `warranty`",
                'sort_order' => "ADD COLUMN `sort_order` int(11) DEFAULT 0 AFTER `item_note`, ADD KEY `sort_order` (`sort_order`)",
                'created_at' => "ADD COLUMN `created_at` datetime NOT NULL AFTER `sort_order`",
            ];
            
            foreach ($columns_to_add as $column => $alter_statement) {
                if (!$this->column_exists($table_items, $column)) {
                    $result = $this->wpdb->query("ALTER TABLE `{$table_items}` {$alter_statement}");
                    if ($result === false) {
                        $success = false;
                    }
                }
            }
        }
        
        // Migrate payments table
        $table_payments = $this->get_table_name('payments');
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_payments}'") === $table_payments) {
            $columns_to_add = [
                'payment_date' => "ADD COLUMN `payment_date` datetime NOT NULL AFTER `invoice_id`, ADD KEY `payment_date` (`payment_date`)",
                'payment_method' => "ADD COLUMN `payment_method` varchar(50) DEFAULT NULL AFTER `payment_date`, ADD KEY `payment_method` (`payment_method`)",
                'amount' => "ADD COLUMN `amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `payment_method`",
                'transaction_ref' => "ADD COLUMN `transaction_ref` varchar(100) DEFAULT NULL AFTER `amount`",
                'note' => "ADD COLUMN `note` text DEFAULT NULL AFTER `transaction_ref`",
                'created_by' => "ADD COLUMN `created_by` bigint(20) unsigned DEFAULT NULL AFTER `note`",
                'created_at' => "ADD COLUMN `created_at` datetime NOT NULL AFTER `created_by`",
            ];
            
            foreach ($columns_to_add as $column => $alter_statement) {
                if (!$this->column_exists($table_payments, $column)) {
                    $result = $this->wpdb->query("ALTER TABLE `{$table_payments}` {$alter_statement}");
                    if ($result === false) {
                        $success = false;
                    }
                }
            }
        }
        
        if ($success) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        }
        
        return $success;
    }

    /**
     * Check if a column exists in a table
     *
     * @param string $table_name Full table name with prefix
     * @param string $column_name Column name to check
     * @return bool True if column exists
     */
    private function column_exists($table_name, $column_name) {
        $query = $this->wpdb->prepare(
            "SHOW COLUMNS FROM `{$table_name}` LIKE %s",
            $column_name
        );
        $result = $this->wpdb->get_var($query);
        return $result === $column_name;
    }
}
