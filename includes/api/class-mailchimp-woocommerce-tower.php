<?php

class MailChimp_WooCommerce_Tower extends Mailchimp_Woocommerce_Job
{
    protected $store_id;
    protected $with_shop_sales = true;
    protected $with_log_file = null;
    protected $with_log_search = null;

    /**
     * OrderCreatedHook constructor.
     * @param $store_id
     */
    public function __construct($store_id)
    {
        $this->store_id = $store_id;
    }

    public function withoutShopSales()
    {
        $this->with_shop_sales = false;
        return $this;
    }

    public function withShopSales()
    {
        $this->with_shop_sales = true;
        return $this;
    }

    public function withLogFile($file)
    {
        $this->with_log_file = $file;
        return $this;
    }

    public function withLogSearch($search)
    {
        $this->with_log_search = $search;
        return $this;
    }

    /**
     * @return array
     * @throws MailChimp_WooCommerce_Error
     * @throws MailChimp_WooCommerce_ServerError
     */
    public function handle()
    {
        return $this->getData();
    }

    /**
     * @return array
     * @throws MailChimp_WooCommerce_Error
     * @throws MailChimp_WooCommerce_ServerError
     */
    public function getData()
    {
        $product_count = $customer_count = $order_count = $mc_product_count = $mc_customer_count = $mc_order_count = 0;

        $api = mailchimp_get_api();
        $store_id = mailchimp_get_store_id();
        $authenticated = mailchimp_is_configured();
        $list_id = mailchimp_get_list_id();
        $url = get_option('siteurl');
        $options = (array) get_option('mailchimp-woocommerce');

        try {
            $product_count = mailchimp_get_product_count();
            $customer_count = 0;
            $order_count = mailchimp_get_order_count();
            $plan = $plan_name = 'Woo';
            $store_active = true;
        } catch (\Throwable $e) {
            $store_active = false;
            $plan = null;
        }

        $has_mailchimp_script = false;
        $has_old_integration = false;
        $duplicate_store_problem = false;
        $store_attached = false;
        $syncing_mc = false;
        $list_is_valid = false;
        $account_info = [];
        $shop = null;

        if ($authenticated) {
            $account_info = $api->getProfile();
            if (is_array($account_info)) {
                // don't need these
                unset($account_info['_links']);
            }
            $stores = $api->stores();
            $compare_url = $this->baseDomain($url);
            $list_name = $list_id ? $api->getList($list_id)['name'] : null;

            if (is_array($stores) && !empty($stores)) {
                foreach ($stores as $mc_store) {
                    $store_url = $this->baseDomain($mc_store->getDomain());
                    $public_key_matched = $mc_store->getId() === $store_id;
                    // make sure the current store in context is inside the Mailchimp array of stores.
                    if ($public_key_matched) {
                        $shop = $mc_store;
                        $syncing_mc = $mc_store->isSyncing();
                        $store_attached = true;
                        $list_is_valid = $mc_store->getListId() === $list_id;
                        $has_mailchimp_script = (bool) $mc_store->getConnectedSiteScriptFragment();
                    }
                    if ($store_url === $compare_url) {
                        if (!$public_key_matched && $mc_store->getPlatform() === 'Woocommerce') {
                            $duplicate_store_problem = true;
                        }
                    }
                }
            }

            try {
                if ($store_attached) {
                    $mc_product_count = $api->getProductCount($store_id);
                    $mc_customer_count = $api->getCustomerCount($store_id);
                    $mc_order_count = $api->getOrderCount($store_id);
                }
            } catch (\Throwable $e) {

            }

            $automations = array();
            $merge_fields = array();
            try {
                foreach ($api->getAutomations($list_id) as $automation) {
                    $created = new \DateTime($automation['create_time']);
                    $started = new \DateTime($automation['start_time']);
                    $automations[] = array(
                        'created_at' => $created->format('Y-m-d H:i:s'),
                        'start_at' => $started->format('Y-m-d H:i:s'),
                        'status' => $automation['status'],
                        'name' => $automation['settings']['title'],
                        'type' => $automation['trigger_settings']['workflow_title'],
                        'stats' => $automation['report_summary'],
                    );
                }
                $merge_fields = $api->mergeFields($list_id);
                $merge_fields = $merge_fields['merge_fields'];
            } catch (\Throwable $e) {

            }
        }

        $time = new \DateTime('now');

        return [
            'store' => (object) array(
                'public_key' => $store_id,
                'domain' => $url,
                'secure_url' => $url,
                'user' => (object) array(
                    'email' => isset($options['admin_email']) ? $options['admin_email'] : null,
                ),
                'average_monthly_sales' => $this->getShopSales(),
                'address' => (object) array(
                    'street' => isset($options['store_street']) && $options['store_street'] ? $options['store_street'] : '',
                    'city' => isset($options['store_street']) && $options['store_street'] ? $options['store_street'] : '',
                    'state' => isset($options['store_state']) && $options['store_state'] ? $options['store_state'] : '',
                    'country' => isset($options['store_country']) && $options['store_country'] ? $options['store_country'] : '',
                    'zip' => isset($options['store_postal_code']) && $options['store_postal_code'] ? $options['store_postal_code'] : '',
                    'phone' => isset($options['store_phone']) && $options['store_phone'] ? $options['store_phone'] : '',
                ),
                'metrics' => array_values([
                    'shopify_hooks' => (object) array('key' => 'shopify_hooks', 'value' => true),
                    'shop.products' => (object) array('key' => 'shop.products', 'value' => $product_count),
                    'shop.customers' => (object) array('key' => 'shop.customers', 'value' => $customer_count),
                    'shop.orders' => (object) array('key' => 'shop.orders', 'value' => $order_count),
                    'mc.products' => (object) array('key' => 'mc.products', 'value' => $mc_product_count),
                    'mc.orders' => (object) array('key' => 'mc.orders', 'value' => $mc_order_count),
                    'mc.has_chimpstatic' => (object) array('key' => 'mc.has_chimpstatic', 'value' => true),
                    'mc.has_duplicate_store' => (object) array('key' => 'mc.has_duplicate_store', 'value' => $duplicate_store_problem),
                    'mc.store_attached' => (object) array('key' => 'mc.store_attached', 'value' => $store_attached),
                    'mc.is_syncing' => (object) array('key' => 'mc.is_syncing', 'value' => $syncing_mc),
                    'mailchimp_api_connected' => (object) array('key' => 'mailchimp_api_connected', 'value' => (bool) $account_info),
                    'mc_list_id' => (object) array('key' => 'mc_list_id', 'value' => (bool) $list_id && $list_is_valid),
                    'mc_list_valid' => (object) array('key' => 'mc_list_valid', 'value' => $list_is_valid),
                    'mc.has_legacy_integration' => (object) array('key' => 'mc.has_legacy_integration', 'value' => $has_old_integration),
                    'admin.updated_at' => (object) array('key' => 'admin.updated_at', 'value' => $time->format('Y-m-d H:i:s')),
                    'product_sync_started' => (object) array('key' => 'product_sync_started', 'value' => get_option('mailchimp-woocommerce-sync.products.started_at')),
                    'product_sync_completed' => (object) array('key' => 'product_sync_completed', 'value' => get_option('mailchimp-woocommerce-sync.products.completed_at')),
                    'customer_sync_started' => (object) array('key' => 'customer_sync_started', 'value' => get_option('mailchimp-woocommerce-sync.customers.started_at')),
                    'customer_sync_completed' => (object) array('key' => 'customer_sync_completed', 'value' => get_option('mailchimp-woocommerce-sync.customers.completed_at')),
                    'order_sync_started' => (object) array('key' => 'order_sync_started', 'value' => get_option('mailchimp-woocommerce-sync.orders.started_at')),
                    'order_sync_completed' => (object) array('key' => 'order_sync_completed', 'value' => get_option('mailchimp-woocommerce-sync.orders.completed_at')),
                ]),
                'meta' => $this->getMeta(),
            ),
            'meta' => [
                'timestamp' => $time->format('Y-m-d H:i:s'),
                'platform' => [
                    'active' => $store_active,
                    'plan' => $plan,
                    'store_name' => get_option('blogname'),
                    'domain' => $url,
                    'secure_url' => $url,
                    'user_email' => isset($options['admin_email']) ? $options['admin_email'] : null,
                    'is_syncing' => $syncing_mc,
                    'sync_started_at' => get_option('mailchimp-woocommerce-sync.started_at'),
                    'sync_completed_at' => get_option('mailchimp-woocommerce-sync.completed_at'),
                    'subscribed_to_hooks' => true,
                    'uses_custom_rules' => false,
                    'ecomm_stats' => [
                        'products' => $product_count,
                        'customers' => $customer_count,
                        'orders' => $order_count,
                    ],
                    'shop' => [
                        'phone' => isset($options['store_phone']) && $options['store_phone'] ? $options['store_phone'] : '',
                    ],
                ],
                'mailchimp' => [
                    'shop' => $shop ? $shop->toArray() : false,
                    'chimpstatic_installed' => $has_mailchimp_script,
                    'force_disconnect' => false,
                    'duplicate_store_problem' => $duplicate_store_problem,
                    'has_old_integration' => $has_old_integration,
                    'store_attached' => $store_attached,
                    'ecomm_stats' => [
                        'products' => $mc_product_count,
                        'customers' => $mc_customer_count,
                        'orders' => $mc_order_count,
                    ],
                    'list' => [
                        'id' => $list_id,
                        'name' => $list_name,
                        'double_opt_in' => mailchimp_list_has_double_optin(false),
                        'valid' => $list_is_valid,
                    ],
                    'account_info' => $account_info,
                    'automations' => $automations,
                    'merge_fields' => (object) $merge_fields,
                ],
                'merge_tags' => [

                ]
            ],
            'logs' => static::logs($this->with_log_file, $this->with_log_search),
            'system_report' => $this->getSystemReport(),
        ];
    }

    /**
     * @param $domain
     * @return string|string[]
     */
    protected function baseDomain($domain)
    {
        return str_replace(
            ['http://', 'https://', 'www.'],
            '',
            rtrim(strtolower(trim($domain)), '/')
        );
    }

    /**
     * @param null $file
     * @param null $search
     * @return array
     */
    public function logs($file = null, $search = null)
    {
        $logs = new MailChimp_WooCommerce_Logs();
        $logs->limit(200);
        $logs->withView(!is_null($file) ? $file : $this->with_log_file);
        $logs->searching(!is_null($search) ? $search : $this->with_log_search);
        return $logs->handle();
    }

    public function getShopSales()
    {
        try {
            global $woocommerce, $wpdb;
            include_once($woocommerce->plugin_path() . '/includes/admin/reports/class-wc-admin-report.php');

            // WooCommerce Admin Report
            $wc_report = new WC_Admin_Report();

            // Set date parameters for the current month
            $start_date = strtotime(date('Y-m', current_time('timestamp')) . '-01 midnight');
            $end_date = strtotime('+1month', $start_date) - 86400;
            $wc_report->start_date = $start_date;
            $wc_report->end_date = $end_date;

            // Avoid max join size error
            $wpdb->query('SET SQL_BIG_SELECTS=1');

            // Get data for current month sold products
            $sold_products = $wc_report->get_order_report_data(array(
                'data' => array(
                    '_product_id' => array(
                        'type' => 'order_item_meta',
                        'order_item_type' => 'line_item',
                        'function' => '',
                        'name' => 'product_id'
                    ),
                    '_qty' => array(
                        'type' => 'order_item_meta',
                        'order_item_type' => 'line_item',
                        'function' => 'SUM',
                        'name' => 'quantity'
                    ),
                    '_line_subtotal' => array(
                        'type' => 'order_item_meta',
                        'order_item_type' => 'line_item',
                        'function' => 'SUM',
                        'name' => 'gross'
                    ),
                    '_line_total' => array(
                        'type' => 'order_item_meta',
                        'order_item_type' => 'line_item',
                        'function' => 'SUM',
                        'name' => 'gross_after_discount'
                    )
                ),
                'query_type' => 'get_results',
                'group_by' => 'product_id',
                'where_meta' => '',
                'order_by' => 'quantity DESC',
                'order_types' => wc_get_order_types('order_count'),
                'filter_range' => TRUE,
                'order_status' => array('completed', 'complete'),
            ));
            $total = 0;
            foreach ($sold_products as $product) {
                $total += $product->gross;
            }
            return $total;
        } catch (\Throwable $e) {
            mailchimp_log('tower', $e->getMessage());
            return 0;
        }
    }

    public function getSystemReport()
    {
        global $wp_version;

        $actions = $this->getLastActions();
        $theme = wp_get_theme();

        return array(
            array('key' => 'PhpVersion', 'value' => phpversion()),
            array('key' => 'Memory Limit', 'value' => ini_get('memory_limit')),
            array('key' => 'Curl Enabled', 'value' => function_exists('curl_init')),
            array('key' => 'Curl Version', 'value' => $this->getCurlVersion()),
            array('key' => 'Wordpress Version', 'value' => $wp_version),
            array('key' => 'WooCommerce Version', 'value' => defined('WC_VERSION') ? WC_VERSION : null),
            array('key' => 'Theme Name', 'value' => esc_html($theme->get('Name'))),
            array('key' => 'Theme URL', 'value' => esc_html($theme->get('ThemeURI'))),
            array('key' => 'Active Plugins', 'value' => $this->getActivePlugins()),
            array('key' => 'Actions', 'value' => $actions),
        );
    }

    public function getCurlVersion()
    {
        $version = function_exists('curl_version') ? curl_version() : null;
        return is_array($version) ? $version['version'] : null;
    }

    public function getActivePlugins()
    {
        $active_plugins = "<ul>";
        $plugins = wp_get_active_and_valid_plugins();
        foreach ($plugins as $plugin) {
            $plugin_data = get_plugin_data($plugin);
            $active_plugins .= '<li><span class="font-bold">'.$plugin_data['Name'].'</span>: '.$plugin_data['Version'].'</li>';
        }
        $active_plugins .= "</ul>";
        return print_r($active_plugins, true);
    }

    public function getLastActions()
    {
        global $wpdb;
        if (!class_exists('ActionScheduler') || !ActionScheduler::is_initialized( 'store' ) ) {
            return array();
        }
        if (!ActionScheduler::store()) {
            return array();
        }
        $oldest_and_newest = '<ul>';

        foreach (array_keys(ActionScheduler::store()->get_status_labels()) as $status) {
            if ('in-progress' === $status) {
                continue;
            }
            $newest = $this->get_action_status_date($status, 'newest' );
            $status = ucfirst($status);
            $oldest_and_newest .= "<li><span class='font-bold'>{$status}</span>: {$newest}</li>";
        }

        $oldest_and_newest .= '</ul>';

        return $oldest_and_newest;
    }

    /**
     * @return array|object|null
     */
    public function getMeta()
    {
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM $wpdb->options WHERE option_name LIKE 'mailchimp-woocommerce-%'");
        $response = array();
        $date = new \DateTime('now');
        foreach ($results as $result) {
            $response[] = array(
                'key' => str_replace('mailchimp-woocommerce-', '', $result->option_name),
                'value' => $result->option_value,
                'updated_at' => $date->format('Y-m-d H:i:s'),
            );
        }
        return $response;
    }

    /**
     * This is where we need to hook into tower from the store owner's support request.
     * We can enable and disable this feature which will generate an API token specific to
     * tower which will be used for authentication coming from our server to this specific store.
     *
     * @param bool $enable
     * @return array|mixed|object|null
     */
    public function toggle($enable = true)
    {
        $command = (bool) $enable ? 'enable' : 'disable';
        $store_id = mailchimp_get_store_id();
        $key = mailchimp_get_api_key();
        $list_id = mailchimp_get_list_id();
        $is_connected = mailchimp_is_configured();
        $post_url = "https://tower.vextras.com/admin-api/woocommerce/{$command}/{$store_id}";
        $plugin_options = (array) get_option('mailchimp-woocommerce');

        if ((bool) $enable) {
            mailchimp_set_data('tower.token', $support_token = wp_generate_password());
        } else {
            $support_token = mailchimp_get_data('tower.token');
            delete_option('mailchimp-woocommerce-tower.support_token');
        }

        if ($enable) {
            $data = array(
                'list_id' => $list_id,
                'php_version' => phpversion(),
                'curl_enabled' => function_exists('curl_init'),
                'is_connected' => $is_connected,
                'sync_complete' => mailchimp_is_done_syncing(),
                'rest_url' => MailChimp_WooCommerce_Rest_Api::url(''),
            );
            if ($is_connected) {
                try {
                    $api = mailchimp_get_api();
                    $account_info = $api->getProfile();
                    $list_info = !empty($list_id) ? $api->getList($list_id) : null;
                    $mc_store = $api->getStore($store_id);
                    $syncing_mc = $mc_store ? $mc_store->isSyncing() : false;
                    if (is_array($list_info)) {
                        unset($list_info['_links']);
                    }
                    if (is_array($account_info)) {
                        unset($account_info['_links']);
                    }
                    $job = new MailChimp_WooCommerce_Fix_Duplicate_Store($store_id, false, false);
                    $job->handle();
                    $dup_store = (bool) $job->hasDuplicateStoreProblem();
                } catch (\Throwable $e) {
                    $list_info = false;
                    $syncing_mc = false;
                    $account_info = false;
                    if (!isset($dup_store)) $dup_store = false;
                }
                $data['list_info'] = $list_info;
                $data['is_syncing'] = $syncing_mc;
                $data['account_info'] = $account_info;
                $data['duplicate_mailchimp_store'] = $dup_store;
            }
        } else {
            $data = array();
        }

        try {
            $payload = array(
                'headers' => array(
                    'Content-type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Store-Platform' => 'woocommerce',
                    'X-List-Id' => $list_id,
                    'X-Store-Key' => base64_encode("{$store_id}:{$key}"),
                ),
                'body' => json_encode(array(
                    'name' => !empty($plugin_options) && isset($plugin_options['store_name']) ? $plugin_options['store_name'] : get_option('blogname'),
                    'support_token' => $support_token,
                    'domain' => get_option('siteurl'),
                    'data' => $data,
                )),
                'timeout'     => 30,
            );
            $response = wp_remote_post($post_url, $payload);
            return json_decode($response['body']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get oldest or newest scheduled date for a given status.
     *
     * @param string $status Action status label/name string.
     * @param string $date_type Oldest or Newest.
     * @return string
     */
    protected function get_action_status_date( $status, $date_type = 'oldest' )
    {
        $order = 'oldest' === $date_type ? 'ASC' : 'DESC';
        $store = ActionScheduler::store();
        $action = $store->query_actions(
            array(
                'claimed'  => false,
                'status'   => $status,
                'per_page' => 1,
                'order'    => $order,
            )
        );
        if ( ! empty( $action ) ) {
            $date_object = $store->get_date( $action[0] );
            $action_date = $date_object->format( 'Y-m-d H:i:s O' );
        } else {
            $action_date = '&ndash;';
        }
        return $action_date;
    }
}