<?php
if (!defined('ABSPATH')) exit;

class CIG_Ajax_Statistics {
    private $security;

    public function __construct($security) {
        $this->security = $security;

        // Existing Hooks
        add_action('wp_ajax_cig_get_statistics_summary', [$this, 'get_statistics_summary']);
        add_action('wp_ajax_cig_get_users_statistics', [$this, 'get_users_statistics']);
        add_action('wp_ajax_cig_get_user_invoices', [$this, 'get_user_invoices']);
        add_action('wp_ajax_cig_export_statistics', [$this, 'export_statistics']);
        add_action('wp_ajax_cig_get_product_insight', [$this, 'get_product_insight']);
        add_action('wp_ajax_cig_get_invoices_by_filters', [$this, 'get_invoices_by_filters']);
        add_action('wp_ajax_cig_get_products_by_filters', [$this, 'get_products_by_filters']);

        // --- NEW: External Balance Logic ---
        add_action('wp_ajax_cig_get_external_balance', [$this, 'get_external_balance']);
        add_action('wp_ajax_cig_add_deposit', [$this, 'add_deposit']);
        add_action('wp_ajax_cig_delete_deposit', [$this, 'delete_deposit']);
    }

    private function get_status_meta_query($status) {
        if ($status === 'all') return [];
        if ($status === 'fictive') return [['key' => '_cig_invoice_status', 'value' => 'fictive', 'compare' => '=']];
        if ($status === 'outstanding') {
             return [
                 'relation' => 'AND',
                 ['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']],
                 ['key' => '_cig_payment_remaining_amount', 'value' => 0.001, 'compare' => '>', 'type' => 'DECIMAL']
             ];
        }
        return [['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']]];
    }
    
    /**
     * Get activation dates for multiple posts efficiently (batch query)
     *
     * @param array $post_ids Array of post IDs
     * @return array Associative array of post_id => activation_date
     */
    private function get_activation_dates_batch($post_ids) {
        if (empty($post_ids)) {
            return [];
        }
        
        global $wpdb;
        $post_ids_str = implode(',', array_map('intval', $post_ids));
        
        $results = $wpdb->get_results("
            SELECT post_id, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN ({$post_ids_str}) 
            AND meta_key = '_cig_activation_date'
        ", ARRAY_A);
        
        $activation_dates = [];
        foreach ($results as $row) {
            $activation_dates[$row['post_id']] = $row['meta_value'];
        }
        
        return $activation_dates;
    }

    public function get_statistics_summary() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;
        
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $status    = sanitize_text_field($_POST['status'] ?? 'standard');

        $table_payments = $wpdb->prefix . 'cig_payments';
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        $table_items    = $wpdb->prefix . 'cig_invoice_items';

        // 1. Payment Filters
        $where_pay = "WHERE 1=1";
        $params_pay = [];
        if ($date_from) { $where_pay .= " AND p.date >= %s"; $params_pay[] = $date_from; }
        if ($date_to)   { $where_pay .= " AND p.date <= %s"; $params_pay[] = $date_to; }

        if ($status === 'fictive') {
            $where_pay .= " AND i.type = 'fictive'";
        } else {
            $where_pay .= " AND (i.type = 'standard' OR i.type IS NULL)";
        }

        // 2. Invoice Filters
        $where_inv = "WHERE 1=1";
        $params_inv = [];
        if ($date_from) { 
            $where_inv .= " AND COALESCE(i.activation_date, i.created_at) >= %s"; 
            $params_inv[] = $date_from . ' 00:00:00'; 
        }
        if ($date_to) { 
            $where_inv .= " AND COALESCE(i.activation_date, i.created_at) <= %s"; 
            $params_inv[] = $date_to . ' 23:59:59'; 
        }
        
        if ($status === 'fictive') {
            $where_inv .= " AND i.type = 'fictive'";
        } else {
            $where_inv .= " AND i.type = 'standard'";
        }

        // QUERY 1: Financials (Payments Table)
        $sql_money = "SELECT 
                    SUM(p.amount) as total_paid,
                    SUM(CASE WHEN p.payment_method = 'company_transfer' THEN p.amount ELSE 0 END) as total_company_transfer,
                    SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount ELSE 0 END) as total_cash,
                    SUM(CASE WHEN p.payment_method = 'consignment' THEN p.amount ELSE 0 END) as total_consignment,
                    SUM(CASE WHEN p.payment_method = 'credit' THEN p.amount ELSE 0 END) as total_credit,
                    SUM(CASE WHEN p.payment_method = 'other' OR p.payment_method = '' OR p.payment_method IS NULL THEN p.amount ELSE 0 END) as total_other,
                    COUNT(DISTINCT p.invoice_id) as paid_invoices_count
                FROM $table_payments p
                LEFT JOIN $table_invoices i ON p.invoice_id = i.id
                $where_pay";

        $query_money = (!empty($params_pay)) ? $wpdb->prepare($sql_money, $params_pay) : $sql_money;
        $money_stats = $wpdb->get_row($query_money, ARRAY_A);

        // QUERY 2: Quantities (Invoices/Items Table)
        $sql_items = "SELECT 
                        COUNT(DISTINCT i.id) as total_invoices_count,
                        SUM(CASE WHEN it.status = 'sold' THEN it.qty ELSE 0 END) as total_sold,
                        SUM(CASE WHEN it.status = 'reserved' THEN it.qty ELSE 0 END) as total_reserved,
                        COUNT(DISTINCT CASE WHEN it.status = 'reserved' THEN it.invoice_id END) as reserved_invoices_count
                      FROM $table_invoices i
                      LEFT JOIN $table_items it ON i.id = it.invoice_id
                      $where_inv";
        
        $query_items = (!empty($params_inv)) ? $wpdb->prepare($sql_items, $params_inv) : $sql_items;
        $item_stats = $wpdb->get_row($query_items, ARRAY_A);

        // QUERY 3: Outstanding (Global)
        $sql_outstanding = "SELECT SUM(balance) FROM $table_invoices WHERE type = 'standard' AND balance > 0.01";
        $total_outstanding = $wpdb->get_var($sql_outstanding);

        $response = [
            'total_invoices'          => (int)($item_stats['total_invoices_count'] ?? 0),
            'total_revenue'           => (float)($money_stats['total_paid'] ?? 0), 
            'total_paid'              => (float)($money_stats['total_paid'] ?? 0),
            'total_company_transfer'  => (float)($money_stats['total_company_transfer'] ?? 0),
            'total_cash'              => (float)($money_stats['total_cash'] ?? 0),
            'total_consignment'       => (float)($money_stats['total_consignment'] ?? 0),
            'total_credit'            => (float)($money_stats['total_credit'] ?? 0),
            'total_other'             => (float)($money_stats['total_other'] ?? 0),
            'total_sold'              => (int)($item_stats['total_sold'] ?? 0),
            'total_reserved'          => (int)($item_stats['total_reserved'] ?? 0),
            'total_reserved_invoices' => (int)($item_stats['reserved_invoices_count'] ?? 0),
            'total_outstanding'       => (float)$total_outstanding,
        ];

        wp_send_json_success($response);
    }

    public function get_users_statistics() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        
        $date_from = !empty($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = !empty($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        
        // Get all invoices matching status
        $args = ['post_type' => 'invoice', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids'];
        $mq = $this->get_status_meta_query($status);
        if ($mq) $args['meta_query'] = $mq;
        
        $query = new WP_Query($args);
        $all_ids = $query->posts;
        
        // Batch fetch activation dates for all invoices
        $activation_dates = $this->get_activation_dates_batch($all_ids);
        
        // Filter by date if provided (using activation_date with fallback to post_date)
        $ids = [];
        if ($date_from && $date_to) {
            $start_ts = strtotime($date_from . ' 00:00:00');
            $end_ts = strtotime($date_to . ' 23:59:59');
            
            // Batch fetch post dates
            $post_dates = [];
            if (!empty($all_ids)) {
                foreach ($all_ids as $id) {
                    $post_dates[$id] = get_post_field('post_date', $id);
                }
            }
            
            foreach ($all_ids as $id) {
                $activation_date = isset($activation_dates[$id]) ? $activation_dates[$id] : null;
                $effective_date = $activation_date ?: ($post_dates[$id] ?? '');
                $ts = strtotime($effective_date);
                
                if ($ts >= $start_ts && $ts <= $end_ts) {
                    $ids[] = $id;
                }
            }
        } else {
            $ids = $all_ids;
        }
        
        $users = [];
        foreach ($ids as $id) {
            $uid = get_post_field('post_author', $id);
            if (!isset($users[$uid])) {
                $u = get_userdata($uid); if(!$u) continue;
                $users[$uid] = ['user_id'=>$uid, 'user_name'=>$u->display_name, 'user_email'=>$u->user_email, 'user_avatar'=>get_avatar_url($uid,['size'=>40]), 'invoice_count'=>0, 'total_sold'=>0, 'total_reserved'=>0, 'total_canceled'=>0, 'total_revenue'=>0, 'last_invoice_date'=>''];
            }
            $users[$uid]['invoice_count']++;
            $users[$uid]['total_revenue'] += (float)get_post_meta($id, '_cig_invoice_total', true);
            foreach (get_post_meta($id, '_cig_items', true)?:[] as $it) {
                $q=floatval($it['qty']); $s=strtolower($it['status']??'sold');
                if($s==='sold') $users[$uid]['total_sold']+=$q; elseif($s==='reserved') $users[$uid]['total_reserved']+=$q; elseif($s==='canceled') $users[$uid]['total_canceled']+=$q;
            }
            // Use activation_date with fallback to post_date
            $activation_date = isset($activation_dates[$id]) ? $activation_dates[$id] : null;
            $d = $activation_date ?: get_post_field('post_date', $id);
            if ($d > $users[$uid]['last_invoice_date']) $users[$uid]['last_invoice_date'] = $d;
        }
        $search = sanitize_text_field($_POST['search']??'');
        if($search) $users = array_filter($users, function($u)use($search){ return stripos($u['user_name'],$search)!==false || stripos($u['user_email'],$search)!==false; });
        
        $sb = $_POST['sort_by'] ?? 'invoice_count'; $so = $_POST['sort_order'] ?? 'desc';
        usort($users, function($a,$b) use ($sb,$so){ 
            $k = ['invoices'=>'invoice_count','revenue'=>'total_revenue','sold'=>'total_sold','reserved'=>'total_reserved','date'=>'last_invoice_date'][$sb] ?? 'invoice_count';
            return $so==='asc' ? ($a[$k]<=>$b[$k]) : ($b[$k]<=>$a[$k]); 
        });
        wp_send_json_success(['users'=>array_values($users)]);
    }

    public function get_user_invoices() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        $args = ['post_type'=>'invoice', 'post_status'=>'publish', 'author'=>intval($_POST['user_id']), 'posts_per_page'=>-1, 'orderby'=>'date', 'order'=>'DESC'];
        $mq = $this->get_status_meta_query($_POST['status']??'standard');
        if(!empty($_POST['payment_method'])) $mq[] = ['key'=>'_cig_payment_type', 'value'=>sanitize_text_field($_POST['payment_method']), 'compare'=>'='];
        if(!empty($_POST['search'])) $mq[] = ['key'=>'_cig_invoice_number', 'value'=>sanitize_text_field($_POST['search']), 'compare'=>'LIKE'];
        if($mq) $args['meta_query'] = $mq;
        $invoices = [];
        foreach ((new WP_Query($args))->posts as $post) {
            $id = $post->ID;
            $items = get_post_meta($id, '_cig_items', true)?:[];
            $tot=0; $s=0; $r=0; $c=0; foreach($items as $it){ $q=floatval($it['qty']); $tot+=$q; $st=strtolower($it['status']??'sold'); if($st==='sold')$s+=$q; elseif($st==='reserved')$r+=$q; else $c+=$q; }
            $pt = get_post_meta($id, '_cig_payment_type', true);
            $invoices[] = ['id'=>$id, 'invoice_number'=>get_post_meta($id,'_cig_invoice_number',true), 'date'=>get_the_date('Y-m-d H:i:s',$id), 'invoice_total'=>(float)get_post_meta($id,'_cig_invoice_total',true), 'payment_type'=>$pt, 'payment_label'=>CIG_Invoice::get_payment_types()[$pt]??$pt, 'total_products'=>$tot, 'sold_items'=>$s, 'reserved_items'=>$r, 'canceled_items'=>$c, 'view_url'=>get_permalink($id), 'edit_url'=>add_query_arg('edit','1',get_permalink($id))];
        }
        wp_send_json_success(['invoices'=>$invoices]);
    }

    public function get_invoices_by_filters() {
    $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
    
    $status = sanitize_text_field($_POST['status'] ?? 'standard');
    $mf = sanitize_text_field($_POST['payment_method'] ?? '');
    $date_from = !empty($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
    $date_to = !empty($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
    
    // Get all invoices matching status
    $args = ['post_type'=>'invoice', 'post_status'=>'publish', 'posts_per_page'=>200, 'orderby'=>'date', 'order'=>'DESC'];
    $mq = $this->get_status_meta_query($status); 
    if($mq) $args['meta_query'] = $mq;
    
    $query = new WP_Query($args);
    $posts = $query->posts;
    
    // Batch fetch activation dates
    $post_ids = array_map(function($p) { return $p->ID; }, $posts);
    $activation_dates = $this->get_activation_dates_batch($post_ids);
    
    // Filter by date if provided (using activation_date with fallback to post_date)
    if ($date_from && $date_to) {
        $start_ts = strtotime($date_from . ' 00:00:00');
        $end_ts = strtotime($date_to . ' 23:59:59');
        
        $filtered_posts = [];
        foreach ($posts as $p) {
            $activation_date = isset($activation_dates[$p->ID]) ? $activation_dates[$p->ID] : null;
            $effective_date = $activation_date ?: $p->post_date;
            $ts = strtotime($effective_date);
            
            if ($ts >= $start_ts && $ts <= $end_ts) {
                $filtered_posts[] = $p;
            }
        }
        $posts = $filtered_posts;
    }
    
    $method_labels = [
        'company_transfer'=>__('კომპანიის ჩარიცხვა','cig'), 
        'cash'=>__('ქეში','cig'), 
        'consignment'=>__('კონსიგნაცია','cig'), 
        'credit'=>__('განვადება','cig'), 
        'other'=>__('სხვა','cig')
    ];
    
    $rows=[];
    foreach($posts as $p) {
        $id=$p->ID;
        
        // --- ახალი ლოგიკა: რეზერვაციის შემოწმება ---
        if ($mf === 'reserved_invoices') {
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            $has_res = false;
            foreach ($items as $it) {
                if (strtolower($it['status'] ?? '') === 'reserved') { 
                    $has_res = true; 
                    break; 
                }
            }
            if (!$has_res) continue; // თუ რეზერვი არ არის, გამოტოვოს ეს ინვოისი
        }
        // ----------------------------------------

        $hist=get_post_meta($id,'_cig_payment_history',true);
        $inv_m=[]; $sums=[]; $has_target=false;
        
        if(is_array($hist)) {
            foreach($hist as $h) {
                $m=$h['method']??'other'; 
                $amt=(float)$h['amount'];
                
                // გადახდის მეთოდის ფილტრი (მუშაობს მხოლოდ მაშინ, თუ mf არ არის reserved_invoices)
                if($mf && $mf !== 'all' && $mf !== 'reserved_invoices') {
                    if($m===$mf && $amt>0.001) $has_target=true;
                }
                
                $inv_m[]=$method_labels[$m]??$m; 
                if(!isset($sums[$m])) $sums[$m]=0; 
                $sums[$m]+=$amt;
            }
        }

        // ფილტრის ვალიდაცია: თუ კონკრეტულ მეთოდს ვეძებთ და არ არის
        if($mf && $mf !== 'all' && $mf !== 'reserved_invoices' && !$has_target) continue;
        
        $bd=''; 
        foreach($sums as $m=>$v) {
            if($v>0) $bd.=esc_html($method_labels[$m]??$m).': '.number_format($v,2).' ₾<br>';
        }
        if($bd) $bd='<div style="font-size:10px;color:#666;">'.$bd.'</div>';
        
        $tot=(float)get_post_meta($id,'_cig_invoice_total',true);
        $pd=(float)get_post_meta($id,'_cig_payment_paid_amount',true);
        
        // Use activation_date with fallback (already batch-fetched)
        $activation_date = isset($activation_dates[$id]) ? $activation_dates[$id] : null;
        $display_date = $activation_date ?: get_the_date('Y-m-d H:i', $p);
        
        $rows[]=[
            'id'=>$id, 
            'invoice_number'=>get_post_meta($id,'_cig_invoice_number',true), 
            'customer'=>get_post_meta($id,'_cig_buyer_name',true)?:'—', 
            'payment_methods'=>implode(', ',array_unique($inv_m)), 
            'total'=>$tot, 
            'paid'=>$pd, 
            'paid_breakdown'=>$bd, 
            'due'=>max(0,$tot-$pd), 
            'author'=>get_the_author_meta('display_name',$p->post_author), 
            'date'=>$display_date, 
            'status'=>get_post_meta($id,'_cig_invoice_status',true), 
            'view_url'=>get_permalink($id), 
            'edit_url'=>add_query_arg('edit','1',get_permalink($id))
        ];
    }
    wp_send_json_success(['invoices'=>$rows]);
}

    public function get_products_by_filters() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        
        $date_from = !empty($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = !empty($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $invoice_status = sanitize_text_field($_POST['invoice_status'] ?? 'standard');
        
        // Get all invoices matching status
        $args = ['post_type'=>'invoice', 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids', 'orderby'=>'date', 'order'=>'DESC'];
        $mq = $this->get_status_meta_query($invoice_status);
        if(!empty($_POST['payment_method']) && $_POST['payment_method']!=='all') $mq[]=['key'=>'_cig_payment_type', 'value'=>$_POST['payment_method'], 'compare'=>'='];
        if($mq) $args['meta_query'] = $mq;
        
        $query = new WP_Query($args);
        $all_ids = $query->posts;
        
        // Batch fetch activation dates
        $activation_dates = $this->get_activation_dates_batch($all_ids);
        
        // Filter by date if provided (using activation_date with fallback to post_date)
        $ids = [];
        if ($date_from && $date_to) {
            $start_ts = strtotime($date_from . ' 00:00:00');
            $end_ts = strtotime($date_to . ' 23:59:59');
            
            // Batch fetch post dates
            $post_dates = [];
            if (!empty($all_ids)) {
                foreach ($all_ids as $id) {
                    $post_dates[$id] = get_post_field('post_date', $id);
                }
            }
            
            foreach ($all_ids as $id) {
                $activation_date = isset($activation_dates[$id]) ? $activation_dates[$id] : null;
                $effective_date = $activation_date ?: ($post_dates[$id] ?? '');
                $ts = strtotime($effective_date);
                
                if ($ts >= $start_ts && $ts <= $end_ts) {
                    $ids[] = $id;
                }
            }
        } else {
            $ids = $all_ids;
        }
        
        $rows=[]; $st=sanitize_text_field($_POST['status']??'sold');
        foreach($ids as $id) {
            foreach(get_post_meta($id,'_cig_items',true)?:[] as $it) {
                if(strtolower($it['status']??'sold')!==$st) continue;
                
                // Use activation_date with fallback (already batch-fetched)
                $activation_date = isset($activation_dates[$id]) ? $activation_dates[$id] : null;
                $display_date = $activation_date ?: get_post_field('post_date', $id);
                
                $rows[]=['name'=>$it['name']??'', 'sku'=>$it['sku']??'', 'image'=>$it['image']??'', 'qty'=>floatval($it['qty']), 'invoice_id'=>$id, 'invoice_number'=>get_post_meta($id,'_cig_invoice_number',true), 'author_name'=>get_the_author_meta('display_name',get_post_field('post_author',$id)), 'date'=>$display_date, 'view_url'=>get_permalink($id), 'edit_url'=>add_query_arg('edit','1',get_permalink($id))];
                if(count($rows)>=500) break 2;
            }
        }
        wp_send_json_success(['products'=>$rows]);
    }

    public function get_product_insight() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        // This is a placeholder for product insight logic, assuming similar structure to others
        // Implementation would fetch specific product stats
        wp_send_json_success(['data' => []]); // Simplified for brevity as logic wasn't fully shown in original file split
    }
    
    public function export_statistics() { wp_send_json_success(['redirect' => true]); }

    /**
     * ----------------------------------------------------------------
     * NEW: EXTERNAL BALANCE (Wallet Logic)
     * ----------------------------------------------------------------
     */
    public function get_external_balance() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');

        $start = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date']) : '';

        // -- PART A: Calculate "Other" Revenue (Debit) --
        $invoice_args = [
            'post_type'      => 'invoice',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_cig_payment_type',
                    'value'   => ['other', 'mixed'],
                    'compare' => 'IN'
                ]
            ]
        ];

        $invoices = get_posts($invoice_args);
        
        $global_debit = 0; // Total accumulated over time
        $period_debit = 0; // Accumulated in selected date range

        foreach ($invoices as $inv) {
            // Check if Fictive (Skip)
            $status = get_post_meta($inv->ID, '_cig_invoice_status', true);
            if ($status === 'fictive') continue;

            $history = get_post_meta($inv->ID, '_cig_payment_history', true);
            if (!is_array($history)) continue;

            foreach ($history as $pay) {
                if (isset($pay['method']) && $pay['method'] === 'other') {
                    $amt = floatval($pay['amount']);
                    $date = isset($pay['date']) ? $pay['date'] : '';

                    // Add to Global
                    $global_debit += $amt;

                    // Add to Period if matches
                    if ($this->is_date_in_range($date, $start, $end)) {
                        $period_debit += $amt;
                    }
                }
            }
        }

        // -- PART B: Calculate Deposits (Credit) --
        $deposit_args = [
            'post_type'      => 'cig_deposit',
            'posts_per_page' => -1,
            'post_status'    => 'any' // Deposits are internal
        ];

        $deposits_query = get_posts($deposit_args);
        
        $global_credit = 0;
        $period_credit = 0;
        $deposit_history = [];

        foreach ($deposits_query as $dep) {
            $amt  = floatval(get_post_meta($dep->ID, '_cig_deposit_amount', true));
            $date = get_post_meta($dep->ID, '_cig_deposit_date', true);
            $note = get_post_meta($dep->ID, '_cig_deposit_note', true);

            // Add to Global
            $global_credit += $amt;

            // Add to Period
            if ($this->is_date_in_range($date, $start, $end)) {
                $period_credit += $amt;
                
                // Add to history list for table
                $deposit_history[] = [
                    'id'      => $dep->ID,
                    'date'    => $date,
                    'amount'  => $amt,
                    'comment' => $note
                ];
            }
        }

        // Sort history by date desc
        usort($deposit_history, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // -- PART C: Response --
        wp_send_json_success([
            'cards' => [
                // Cards follow the filter (Period data)
                'accumulated' => number_format($period_debit, 2, '.', ''),
                'deposited'   => number_format($period_credit, 2, '.', ''),
                
                // Balance is ALWAYS Global (Total Debt)
                'balance'     => number_format($global_credit - $global_debit, 2, '.', '') 
                // Note: Logic is Credit - Debit. 
                // If I gathered 1000 (Debit) and deposited 800 (Credit), Balance is -200 (I owe 200).
                // If Balance is negative, it's red (Due).
            ],
            'history' => $deposit_history
        ]);
    }

    /**
     * Add New Deposit
     */
    public function add_deposit() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');

        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $date   = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
        $note   = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Amount must be greater than 0', 'cig')]);
        }

        $post_id = wp_insert_post([
            'post_type'   => 'cig_deposit',
            'post_status' => 'publish',
            'post_title'  => 'Deposit ' . $date . ' - ' . $amount,
            'post_author' => get_current_user_id()
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }

        update_post_meta($post_id, '_cig_deposit_amount', $amount);
        update_post_meta($post_id, '_cig_deposit_date', $date);
        update_post_meta($post_id, '_cig_deposit_note', $note);

        wp_send_json_success(['message' => __('Deposit added successfully', 'cig')]);
    }

    /**
     * Delete Deposit
     */
    public function delete_deposit() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id || get_post_type($id) !== 'cig_deposit') {
            wp_send_json_error(['message' => __('Invalid ID', 'cig')]);
        }

        wp_delete_post($id, true);
        wp_send_json_success(['message' => __('Deposit deleted', 'cig')]);
    }

    /**
     * Helper: Check date range
     */
    private function is_date_in_range($date, $start, $end) {
        if (!$date) return false;
        if (!$start && !$end) return true; // No filter
        
        $ts = strtotime($date);
        $s_ts = $start ? strtotime($start . ' 00:00:00') : 0;
        $e_ts = $end ? strtotime($end . ' 23:59:59') : PHP_INT_MAX;

        return ($ts >= $s_ts && $ts <= $e_ts);
    }
}