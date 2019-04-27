<?php

/**
 * API Class
 *
 * Handles the products
 *
 * @class      WC_Pos_API
 * @package   WooCommerce POS
 */
class WC_Pos_API
{

    public function __construct()
    {
        // try and increase server timeout
        //$this->increase_timeout();

        // remove wc api authentication
        if (isset(WC()->api) && isset(WC()->api->authentication)) {
            remove_filter('woocommerce_api_check_authentication', array(WC()->api->authentication, 'authenticate'), 0);
        }

        // Compatibility for clients that can't use PUT/PATCH/DELETE
        if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $_GET['_method'] = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
        }

        $this->init_hooks();
    }

    public function init_hooks()
    {
        remove_filter('comments_clauses', array('WC_Comments', 'exclude_order_comments'), 10, 1);

        add_filter( 'woocommerce_api_check_authentication', array( $this, 'wc_api_authentication' ), 20, 1 );

        if ( get_option( 'wc_pos_enable_new_api', 'no' ) == 'no' ) {

            add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'filter_product_response' ), 99, 3 );
            add_action( 'woocommerce_api_coupon_response', array( $this, 'api_coupon_response' ), 99, 4 );
            add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'filter_order_response' ), 999, 3 );
            add_filter( 'woocommerce_api_query_args', array( $this, 'filter_api_query_args' ), 99, 2 );
            add_filter( 'woocommerce_rest_customer_query', array( $this, 'filter_user_api_query_args' ), 99, 2 );
        }
        add_action('woocommerce_rest_orders_prepare_object_query', array($this, 'filter_order_args'), 99, 2);
        add_filter( 'woocommerce_rest_prepare_customer', array( $this, 'filter_customer_response' ), 99, 3 );

        $params = array_merge($_GET, $_POST);
        if (isset($params['role']) && $params['role'] == 'all') {
            add_action('pre_get_users', array($this, 'pre_get_users'), 99, 1);
        }
    }

    /**
     * Bypass authenication for WC REST API
     * @return WP_User object
     */
    public function wc_api_authentication($user)
    {
        //if( $this->is_pos_referer() === true || is_pos() ){
        global $current_user;
        $user = $current_user;


        if (!user_can($user->ID, 'view_register'))
            $user = new WP_Error(
                'woocommerce_pos_authentication_error',
                __('User not authorized to access WooCommerce POS', 'wc_point_of_sale'),
                array('status' => 401)
            );

        //}

        return $user;

    }

    /**
     * WC REST API can timeout on some servers
     * This is an attempt t o increase the timeout limit
     */
    public function increase_timeout()
    {
        $timeout = 6000;
        if (!ini_get('safe_mode'))
            @set_time_limit($timeout);

        @ini_set('memory_limit', WP_MAX_MEMORY_LIMIT);
        @ini_set('max_execution_time', (int)$timeout);
    }

    public function pre_get_users($query)
    {
        $query->query_vars['role'] = '';
        return $query;
    }

    public function modify_user_query($query)
    {

        $args = array_merge($_GET, $_POST);
        $filter = array();
        if (!empty($args['filter'])) {
            $filter = $args['filter'];

            // Updated date
            if (!empty($filter['updated_at_min'])) {
                $updated_at_min = WC()->api->server->parse_datetime($filter['updated_at_min']);
                if ($updated_at_min) {
                    $query->query_where .= sprintf(" AND user_modified_gmt >= STR_TO_DATE( '%s', '%%Y-%%m-%%d %%H:%%i:%%s' )", esc_sql($updated_at_min));
                }
            }

            if (!empty($filter['updated_at_max'])) {
                $updated_at_max = WC()->api->server->parse_datetime($filter['updated_at_max']);
                if ($updated_at_max) {
                    $query->query_where .= sprintf(" AND user_modified_gmt <= STR_TO_DATE( '%s', '%%Y-%%m-%%d %%H:%%i:%%s' )", esc_sql($updated_at_max));
                }
            }

        }
    }


    /**
     * Filter product response from WC REST API for easier handling by backbone.js
     * @param  WP_REST_Response $response
     * @param  WC_Data $product
     * @param  WP_REST_Request $request
     * @return WP_REST_Response $response
     */
    public function filter_product_response($response, $product, $request)
    {
        $product_data = $response->get_data();
        // flatten variable data
        $product_data['categories_ids'] = array_map(function($category) {
            return $category['id'];
        },$product_data['categories']);

        if (!empty($product_data['attributes'])) {

            foreach ($product_data['attributes'] as $attr_key => $attribute) {

                $taxonomy = wc_get_attribute($attribute['id']);

                $product_data['attributes'][$attr_key]['slug'] = $taxonomy ? $taxonomy->slug : wc_sanitize_taxonomy_name($attribute['name']);
                $product_data['attributes'][$attr_key]['is_taxonomy'] = $taxonomy ? true : false;

                $options = array();
                foreach ($product_data['attributes'][$attr_key]['options'] as $opt) {

                    if ($taxonomy) {
                        // Don't use wc_clean as it destroys sanitized characters
                        $a = get_term_by('name', $opt, 'pa_' . $taxonomy->slug);
                        if ($a) {
                            $value = $a->slug;
                        } else {
                            $value = sanitize_title(stripslashes($opt));
                        }
                    } else {
                        $value = wc_clean(stripslashes($opt));
                    }

                    $options[] = array('slug' => $value, 'name' => $opt);

                }
                $product_data['attributes'][$attr_key]['options'] = $options;

            }

        }
        $parent_image = wp_get_attachment_image_src(get_post_thumbnail_id($product_data['id']), 'shop_thumbnail');
        $parent_src = wp_get_attachment_image_src(get_post_thumbnail_id($product_data['id']), 'full');
        $product_data['thumbnail_src'] = $parent_image ? current($parent_image) : wc_placeholder_img_src();
        $product_data['featured_src'] = $parent_src ? current($parent_src) : $product_data['thumbnail_src'];

        if (function_exists('is_wc_booking_product') && is_wc_booking_product($product)) {
            $product_data['booking'] = $this->get_booking($product);
        }
        if ($product->get_type() == 'subscription' || $product->get_type() == 'variable-subscription') {
            $product_data['subscription'] = $this->get_subscription($product->get_id());
        }

        $scan_field = get_option('woocommerce_pos_register_scan_field');
        if ($scan_field) {
            $product_data['post_meta'][$scan_field][] = get_post_meta($product->get_id(), $scan_field, true);
        }
        $product_data['post_meta']['product_addons'] = get_post_meta($product->get_id(), '_product_addons', true);

        $product_data['points_earned'] = '';
        $product_data['points_max_discount'] = '';
        if (isset($GLOBALS['wc_points_rewards'])) {
            $product_data['points_earned'] = self::get_product_points($product);
            $product_data['points_max_discount'] = self::get_product_max_discount($product);
        }

        if (count($product_data['variations'])) {
            foreach ($product_data['variations'] as $key => $variation) {
                $variation = wc_get_product($variation);

                $product_data['variations'][$key] = $variation->get_data();
                $product_data['variations'][$key]['type'] = $product->get_type();

                $image = wp_get_attachment_image_src(get_post_thumbnail_id($variation->get_id()), 'shop_thumbnail');
                $f_image = wp_get_attachment_image_src(get_post_thumbnail_id($variation->get_id()), 'full');
                $product_data['variations'][$key]['thumbnail_src'] = $image ? current($image) : $product_data['thumbnail_src'];
                $product_data['variations'][$key]['featured_src'] = $f_image ? current($f_image) : $product_data['thumbnail_src'];

                if ($scan_field) {
                    $product_data['variations'][$key]['post_meta'][$scan_field][] = get_post_meta($variation->get_id(), $scan_field, true);
                }

                $product_data['variations'][$key]['points_earned'] = '';
                if (isset($GLOBALS['wc_points_rewards'])) {
                    $variation_product = wc_get_product($variation->get_id());
                    $product_data['variations'][$key]['points_earned'] = self::get_product_points($variation_product);
                    $product_data['variations'][$key]['points_max_discount'] = self::get_product_max_discount($variation_product);
                }

                if ($product->get_type() == 'subscription' || $product->get_type() == 'variable-subscription') {
                    $product_data['variations'][$key]['subscription'] = $this->get_subscription($variation->get_id());
                }
            }
        }

        if($product_data["type"] == "variable"){
            $product_data["default_variations"] = get_post_meta($product->get_id(), '_default_attributes', true);
        }

        $response->set_data($product_data);

        return $response;
    }

    private function get_subscription($product_id)
    {
        $subscription = array();
        $post_meta_keys = array(
            'trial_length' => '_subscription_trial_length',
            'sign_up_fee' => '_subscription_sign_up_fee',
            'period' => '_subscription_period',
            'period_interval' => '_subscription_period_interval',
            'length' => '_subscription_length',
            'trial_period' => '_subscription_trial_period',
            'limit' => '_subscription_limit',
            'one_time_shipping' => '_subscription_one_time_shipping',
            'payment_sync_date' => '_subscription_payment_sync_date',

        );
        foreach ($post_meta_keys as $key => $meta_value) {
            $subscription[$key] = get_post_meta($product_id, $meta_value, true);
        }
        return $subscription;
    }


    private function get_booking($product)
    {
        $product = new WC_Product_Booking($product);

        $post_meta_keys = array(
            'duration_type' => '_wc_booking_duration_type',
            'duration' => '_wc_booking_duration',
            'min_duration' => '_wc_booking_min_duration',
            'max_duration' => '_wc_booking_max_duration',
            'max_persons_group' => '_wc_booking_max_persons_group',
            'has_resources' => '_wc_booking_has_resources',
            'resources_assignment' => '_wc_booking_resources_assignment',
            'cost' => '_wc_booking_cost',
            'resource_label' => 'wc_booking_resource_label',
            'check_availability_against' => '_wc_booking_check_availability_against',
            'person_qty_multiplier' => '_wc_booking_person_qty_multiplier',

        );
        $person_types = $product->get_person_types();
        foreach ($person_types as $key => $person_type) {
            $min = get_post_meta($person_type->get_id(), 'min', true);
            $max = get_post_meta($person_type->get_id(), 'max', true);

            $k = $person_type->get_sort_order();

            $person_types[$k] = new stdClass();
            $person_types[$k]->min_person_type_persons = $min && !empty($min) ? $min : 0;
            $person_types[$k]->max_person_type_persons = $max && !empty($max) ? $max : 0;
            $person_types[$k]->post_title = $person_type->post_title;
            $person_types[$k]->post_excerpt = $person_type->post_excerpt;
            $person_types[$k]->ID = $person_type->get_id();
        }

        $resources = $product->get_resources();
        foreach ($resources as $key => $resource) {
            $resources[$key]->base_cost = $resource->get_base_cost();
            $resources[$key]->block_cost = $resource->get_block_cost();
            $resources[$key]->ID = $resource->ID;
            $resources[$key]->post_title = $resource->post_title;
        }

        $booking = array(
            'base_cost' => $product->get_base_cost(),
            'duration_unit' => $product->get_duration_unit(),
            'has_persons' => $product->has_persons(),
            'has_person_types' => $product->has_person_types(),
            'person_types' => $person_types,
            'min_persons' => $product->get_min_persons(),
            'max_persons' => $product->get_max_persons(),
            'resources' => $resources,
            'min_date' => $product->get_min_date(),
            'max_date' => $product->get_max_date(),
            'default_availability' => $product->get_default_availability(),
            'is_range_picker_enabled' => $product->is_range_picker_enabled(),
            'is_customer_range_picker' => $product->get_duration_type() == 'customer' && $product->is_range_picker_enabled(),
        );

        $booking_form = new WC_Booking_Form($product);

        $bookings_path = untrailingslashit(plugin_dir_path(WC_BOOKINGS_MAIN_FILE)) . '/includes/booking-form/';

        switch ($booking['duration_unit']) {
            case 'month':
                include_once($bookings_path . 'class-wc-booking-form-month-picker.php');
                $month_picker = new WC_Booking_Form_Month_Picker($booking_form);
                $booking['Month_Picker'] = $month_picker->get_args();
                break;
            case 'day':
            case 'night':
                include_once($bookings_path . 'class-wc-booking-form-date-picker.php');
                $date_picker = new WC_Booking_Form_Date_Picker($booking_form);
                $booking['Date_Picker'] = $date_picker->get_args();
                break;
            case 'minute' :
            case 'hour' :
                include_once($bookings_path . 'class-wc-booking-form-datetime-picker.php');
                $datetime_picker = new WC_Booking_Form_Datetime_Picker($booking_form);
                $booking['Datetime_Picker'] = $datetime_picker->get_args();
                /*
                 * setting up this as this data need to check booking availability
                 */
                $booking['Datetime_Picker']['availability_rules'][] = array();
                $booking['Datetime_Picker']['availability_rules'][0]   = $booking_form->product->get_availability_rules();
                $booking['Datetime_Picker']['restricted_days']   = $booking_form->product->has_restricted_days() ? $booking_form->product->get_restricted_days() : false;

                if ( $booking_form->product->has_resources() ) {
                    foreach ( $booking_form->product->get_resources() as $resource ) {
                        $booking['Datetime_Picker']['availability_rules'][ $resource->ID ] = $booking_form->product->get_availability_rules( $resource->ID );
                    }
                }
            break;
        }


        foreach ($post_meta_keys as $key => $meta_value) {
            $booking[$key] = get_post_meta($product->get_id(), $meta_value, true);
        }
        return $booking;
    }

    private static function get_product_max_discount($product)
    {

        if (empty($product->variation_id)) {

            // simple product
            $max_discount = (isset($product->wc_points_max_discount)) ? $product->wc_points_max_discount : '';

        } else {
            // variable product
            $points_max_discount = get_post_meta($product->variation_id, '_wc_points_max_discount', true);
            $max_discount = (isset($points_max_discount) ? $points_max_discount : '');
        }

        return $max_discount;
    }

    private static function get_product_points($product)
    {

        if (empty($product->variation_id)) {
            // simple or variable product, for variable product return the maximum possible points earned
            if (method_exists($product, 'get_variation_price')) {
                $points = (isset($product->wc_max_points_earned)) ? $product->wc_max_points_earned : '';
            } else {
                $points = (isset($product->wc_points_earned)) ? $product->wc_points_earned : '';

                // subscriptions integration - if subscriptions is active check if this is a renewal order
                if (class_exists('WC_Subscriptions_Renewal_Order') && is_object($order)) {
                    if (WC_Subscriptions_Renewal_Order::is_renewal($order)) {
                        $points = (isset($product->wc_points_rewnewal_points)) ? $product->wc_points_rewnewal_points : $points;
                    }
                }
            }
        } else {
            // variation product
            $points = get_post_meta($product->variation_id, '_wc_points_earned', true);

            // subscriptions integration - if subscriptions is active check if this is a renewal order
            if (class_exists('WC_Subscriptions_Renewal_Order') && is_object($order)) {
                if (WC_Subscriptions_Renewal_Order::is_renewal($order)) {
                    $renewal_points = get_post_meta($product->variation_id, '_wc_points_rewnewal_points', true);
                    $points = ('' === $renewal_points) ? $points : $renewal_points;
                }
            }

            // if points aren't set at variation level, use them if they're set at the product level
            if ('' === $points) {
                $points = (isset($product->parent->wc_points_earned)) ? $product->parent->wc_points_earned : '';

                // subscriptions integration - if subscriptions is active check if this is a renewal order
                if (class_exists('WC_Subscriptions_Renewal_Order') && is_object($order)) {
                    if (WC_Subscriptions_Renewal_Order::is_renewal($order)) {
                        $points = (isset($product->parent->wc_points_rewnewal_points)) ? $product->parent->wc_points_rewnewal_points : $points;
                    }
                }
            }
        }
        return $points;
    }

    /**
     * @param WP_REST_Response $response
     * @param WP_User $user_data
     * @param WP_REST_Request $request
     * @return mixed
     */
    public function filter_customer_response($response, $user_data, $request)
    {
        $customer_data = $response->get_data();
        $customer_data['billing_address'] = $customer_data['billing'];
        $customer_data['shipping_address'] = $customer_data['shipping'];
        $customer_data['points_balance'] = 0;

        if (isset($GLOBALS['wc_points_rewards'])) {
            $customer_data['points_balance'] = WC_Points_Rewards_Manager::get_users_points($user_data->ID);
        }

        if(class_exists('WC_Gateway_Account_Funds') && in_array('accountfunds', get_option('pos_enabled_gateways', array()))){
            $customer_data['account_funds'] = WC_Account_Funds::get_account_funds($user_data->ID);
        }

        foreach (array('billing', 'shipping') as $type){
            $country = $customer_data[$type . '_address']['country'];
            $value = $customer_data[$type . '_address']['state'];
            $fields = WC()->countries->get_address_fields($country, $type . '_');
            $fields = $fields[$type . '_state'];

            $fields['country'] = $country;
            $fields['return'] = true;

            $customer_data[$type . '_address']['state_field'] = woocommerce_form_field($type . '_state', $fields, $value);
        }

        unset($customer_data['billing']);
        unset($customer_data['shipping']);

        $response->set_data($customer_data);

        return $response;
    }

    /**
     * Get attribute taxonomy by slug.
     */
    private function get_attribute_taxonomy_by_id($id)
    {
        $taxonomy = null;
        $attribute_taxonomies = wc_get_attribute_taxonomies();

        foreach ($attribute_taxonomies as $key => $tax) {
            if ($id == $tax->attribute_id) {
                $taxonomy = wc_attribute_taxonomy_name($tax->attribute_name);
                break;
            }
        }

        return $taxonomy;
    }


    /**
     * @param WP_REST_Response $response The response object.
     * @param WC_Data          $the_order   Object data.
     * @param WP_REST_Request  $request  Request object.
     */
    public function filter_order_response($response, $the_order, $request)
    {
        global $wpdb;

        $order_data = $response->get_data();
        $post = get_post($order_data["id"]);

        $order_data['order_status'] = sprintf('<mark class="order-status status-%s tips" data-tip="%s"><span>%s</span></mark>', sanitize_title($the_order->get_status()), wc_get_order_status_name($the_order->get_status()), wc_get_order_status_name($the_order->get_status()));

        $formatted_address = '';
        if ($f_address = $the_order->get_formatted_shipping_address()) {
            $formatted_address = '<a target="_blank" href="' . esc_url($the_order->get_shipping_address_map_url()) . '">' . esc_html(preg_replace('#<br\s*/?>#i', ', ', $f_address)) . '</a>';
        } else {
            $formatted_address = '<span>&ndash;</span>';
        }

        if ($the_order->get_shipping_method()) {
            $formatted_address .= '<small class="meta">' . __('Via', 'woocommerce') . ' ' . esc_html($the_order->get_shipping_method()) . '</small>';
        }

        $order_data['formatted_shipping_address'] = $formatted_address;

        if ('0000-00-00 00:00:00' == $post->post_date) {
            $t_time = $h_time = __('Unpublished', 'woocommerce');
        } else {
            $t_time = get_the_time(__('Y/m/d g:i:s A', 'woocommerce'), $post);
            $h_time = get_the_time(__('Y/m/d', 'woocommerce'), $post);
        }

        $order_data['order_date'] = '<abbr title="' . esc_attr($t_time) . '">' . esc_html(apply_filters('post_date_column_time', $h_time, $post)) . '</abbr>';

        if ($the_order->get_customer_note()) {
            $order_data['customer_message'] = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip($the_order->get_customer_note()) . '">' . __('Yes', 'woocommerce') . '</span>';
        } else {
            $order_data['customer_message'] = '<span class="na">&ndash;</span>';
        }

        $order_notes = '<span class="na">&ndash;</span>';

        if ($post->comment_count) {
            $comment_count = absint($post->comment_count);


            // check the status of the post
            $status = ('trash' !== $post->post_status) ? '' : 'post-trashed';

            $latest_notes = get_comments(array(
                'post_id' => $post->ID,
                'number' => 1,
                'status' => $status
            ));

            $latest_note = current($latest_notes);

            if (isset($latest_note->comment_content) && $comment_count == 1) {
                $order_notes = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip($latest_note->comment_content) . '">' . __('Yes', 'woocommerce') . '</span>';
            } elseif (isset($latest_note->comment_content)) {
                $order_notes = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip($latest_note->comment_content . '<br/><small style="display:block">' . sprintf(_n('plus %d other note', 'plus %d other notes', ($comment_count - 1), 'woocommerce'), $comment_count - 1) . '</small>') . '">' . __('Yes', 'woocommerce') . '</span>';
            } else {
                $order_notes = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip(sprintf(_n('%d note', '%d notes', $comment_count, 'woocommerce'), $comment_count)) . '">' . __('Yes', 'woocommerce') . '</span>';
            }
        }

        $order_data['order_notes'] = $order_notes;
        $order_data['order_total'] = $the_order->get_formatted_order_total();

        if ($the_order->get_payment_method_title()) {
            $order_data['order_total'] .= '<small class="meta">' . __('Via', 'woocommerce') . ' ' . esc_html($the_order->get_payment_method_title()) . '</small>';
        }

        if (sizeof($order_data['line_items']) > 0) {
            foreach ($order_data['line_items'] as $key => $item) {
                $parents = get_post_ancestors($item['product_id']);
                if ($parents && !empty($parents)) {
                    $order_data['line_items'][$key]['variation_id'] = $item['product_id'];
                    $order_data['line_items'][$key]['product_id'] = $parents[0];
                }
                $thumb_id = get_post_thumbnail_id($item['product_id']);
                $order_data['line_items'][$key]['image'] = $thumb_id ? wp_get_attachment_image(get_post_thumbnail_id($item['product_id'])) : wc_placeholder_img();
                $price = wc_get_order_item_meta($item['id'], '_price');
                if ($price) {
                    $order_data['line_items'][$key]['price'] = $price;
                } else {
                    $dp = (isset($filter['dp']) ? intval($filter['dp']) : 2);
                    $order_data['line_items'][$key]['price'] = wc_format_decimal($this->get_item_price($item), $dp);
                }


                $_product = wc_get_product($item['product_id']);

                if ($_product && $_product->is_type('booking')) {
                    $booking_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_booking_order_item_id' AND meta_value = %d;", $item['id']));
                    if ($booking_id) {
                        $order_data['line_items'][$key]['hidden_fields'] = array(
                            'booking' => 'booking_id=' . $booking_id
                        );
                    }
                }


            }
        }

        if (sizeof($order_data['coupon_lines']) > 0) {
            foreach ($order_data['coupon_lines'] as $key => $coupon) {
                if ($coupon['code'] == 'POS Discount') {
                    $pamount = wc_get_order_item_meta($coupon['id'], 'discount_amount_percent', true);
                    if ($pamount && !empty($pamount)) {
                        $order_data['coupon_lines'][$key]['percent'] = (float)$pamount;
                    }
                }

            }
        }


        $order_data['print_url'] = wp_nonce_url(admin_url('admin.php?print_pos_receipt=true&order_id=' . $the_order->get_id()), 'print_pos_receipt');
        $order_data['stock_reduced'] = get_post_meta($the_order->get_id(), '_order_stock_reduced', true) ? true : false;

        if($request->get_param('action') == 'create'){
            foreach ($request->get_param('meta_data') as $meta){
                if($meta["key"] === "wc_pos_id_register"){
                    $order_data['new_order'] = WC_POS()->register()->crate_order_id($meta["value"]);
                    break;
                }
            }
        }

        $print_receipt = $request->get_param('print_receipt');
        if($print_receipt){
            if(get_option('wc_pos_enable_cloud_print', 'disable') == "enable"){
                $register_id = $request['meta_data']['wc_pos_id_register'];
                $register = WC_Pos_Registers::instance()->get_data($register_id);
                if(count($register)){
                    $receipt = WC_Pos_Receipts::instance()->get_data($register[0]['detail']['receipt_template']);
                    $mac = isset($register[0]['settings']['receipt_printer']) ? $register[0]['settings']['receipt_printer'] : "";
                    if(WC_Pos_Cloud_Print_Handler::is_valid_mac($mac) && $receipt[0]['print_by_pos_printer'] != "html" ){
                        $order_data['print_type'] = "normal";
                        WC_Pos_Cloud_Print::wc_pos_woo_on_thankyou($the_order->get_id());
                    }else{
                        $order_data['print_type'] = "html";
                    }
                }
            }else{
                $order_data['print_type'] = "html";
            }
        }

        $response->set_data($order_data);

        return $response;
    }

    public function get_item_price($item)
    {
        $round = false;
        $inc_tax = wc_prices_include_tax();

        $qty = (!empty($item['quantity'])) ? $item['quantity'] : 1;

        if ($inc_tax) {
            $price = ($item['subtotal'] + $item['subtotal_tax']) / max(1, $qty);
        } else {
            $price = $item['subtotal'] / max(1, $qty);
        }

        $price = $round ? round($price, wc_get_price_decimals()) : $price;

        return $price;
    }


    public function filter_api_query_args($args, $request_args)
    {
        if (!empty($request_args['meta_key'])) {
            $args['meta_key'] = $request_args['meta_key'];
            unset($request_args['meta_key']);
        }
        if (!empty($request_args['meta_value'])) {
            $args['meta_value'] = $request_args['meta_value'];
            unset($request_args['meta_value']);
        }
        if (!empty($request_args['meta_compare'])) {
            $args['meta_compare'] = $request_args['meta_compare'];
            unset($request_args['meta_compare']);
        }

        if (!empty($args['s'])) {
            global $wpdb;
            $search_fields = array_map('wc_clean', apply_filters('woocommerce_shop_order_search_fields', array(
                '_order_key',
                '_billing_company',
                '_billing_address_1',
                '_billing_address_2',
                '_billing_city',
                '_billing_postcode',
                '_billing_country',
                '_billing_state',
                '_billing_email',
                '_billing_phone',
                '_shipping_address_1',
                '_shipping_address_2',
                '_shipping_city',
                '_shipping_postcode',
                '_shipping_country',
                '_shipping_state'
            )));

            $search_order_id = str_replace('Order #', '', $args['s']);
            if (!is_numeric($search_order_id)) {
                $search_order_id = 0;
            }

            // Search orders
            $post_ids = array_unique(array_merge(
                $wpdb->get_col(
                    $wpdb->prepare("
						SELECT DISTINCT p1.post_id
						FROM {$wpdb->postmeta} p1
						INNER JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id
						WHERE
							( p1.meta_key = '_billing_first_name' AND p2.meta_key = '_billing_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
						OR
							( p1.meta_key = '_shipping_first_name' AND p2.meta_key = '_shipping_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
						OR
							( p1.meta_key IN ('" . implode("','", $search_fields) . "') AND p1.meta_value LIKE '%%%s%%' )
						",
                        esc_attr($args['s']), esc_attr($args['s']), esc_attr($args['s'])
                    )
                ),
                $wpdb->get_col(
                    $wpdb->prepare("
						SELECT order_id
						FROM {$wpdb->prefix}woocommerce_order_items as order_items
						WHERE order_item_name LIKE '%%%s%%'
						",
                        esc_attr($args['s'])
                    )
                ),
                array($search_order_id)
            ));
            unset($args['s']);

            $args['shop_order_search'] = true;

            // Search by found posts
            if (!empty($args['post__in'])) {
                $args['post__in'] = array_merge($args['post__in'], $post_ids);
            } else {
                $args['post__in'] = $post_ids;
            }
        }
        return $args;
    }

    public function api_coupon_response($coupon_data, $coupon, $fields, $server)
    {
        if (!empty($coupon_data) && is_array($coupon_data)) {
            $used_by = get_post_meta($coupon_data['id'], '_used_by');
            if ($used_by)
                $coupon_data['used_by'] = (array)$used_by;
            else
                $coupon_data['used_by'] = null;

            if (!$coupon->get_date_expires())
                $coupon_data['expiry_date'] = false;

            $coupon_data['maximum_amount'] = $coupon->get_maximum_amount();
            $coupon_data['limit_usage_to_x_items'] = !empty($coupon->get_limit_usage_to_x_items()) ? absint($coupon->get_limit_usage_to_x_items()) : $coupon->get_limit_usage_to_x_items();
            $coupon_data['coupon_custom_fields'] = get_post_meta($coupon_data['id']);
        }
        return $coupon_data;
    }


    /**
     * @param array $args
     * @param WP_REST_Request $request
     * @return array
     */
    public function filter_order_args($args, $request)
    {
        if(empty($request->get_param('reg_id'))){
            return $args;
        }

        if($request->get_param('reg_id') == "all"){
            return $args;
        }

        $meta_query = isset($args['meta_query']) ? $args['meta_query'] : array();
        $data = array(
            'key'     => 'wc_pos_id_register',
            'value'   => $_REQUEST['reg_id'],
        );
        array_push($meta_query, $data);

        $args['meta_query'] = $meta_query;

        return $args;
    }

    /**
     * @param array $args
     * @param WP_REST_Request $request
     * @return array
     */
    public function filter_user_api_query_args($args, $request)
    {
        $referer = $request->get_header('referer');
        if (strpos($referer, 'point-of-sale') === false) {
            return $args;
        }

        $meta_query = isset($args['meta_query']) ? $args['meta_query'] : array();
        $meta_data = array(
            'relation' => 'OR',
            array(
                'key' => 'first_name',
                'value' => $_REQUEST['search'],
                'compare' => 'LIKE'
            ),
            array(
                'key' => 'last_name',
                'value' => $_REQUEST['search'],
                'compare' => 'LIKE'
            )
        );

        $args['meta_query'] = count($meta_query) ? array_push($meta_query, $meta_data) : $meta_data;
        $args['search_columns'] = array('user_login', 'user_nicename', 'user_email');


        return $args;
    }


}