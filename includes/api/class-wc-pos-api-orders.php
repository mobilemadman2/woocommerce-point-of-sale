<?php
/**
 * POS API Orders Class
 *
 * Handles requests to the /orders endpoint
 *
 * @class      WC_API_POS_Orders
 * @package   WooCommerce POS
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_API_POS_Orders extends WC_REST_Orders_Controller
{

    /** @var string $base the route base */
    protected $rest_base = 'pos_orders';

    /**
     * Create an order
     *
     * @since 2.2
     * @param array $data raw order data
     * @return array
     */
    public function edit_order($id, $data)
    {
        global $wpdb;


        try {
            if (!isset($data['order'])) {
                throw new WC_API_Exception('woocommerce_api_missing_order_data', sprintf(__('No %1$s data specified to edit %1$s', 'woocommerce'), 'order'), 400);
            }

            $data = $data['order'];
            $update_totals = true;

            // permission check
            if (!current_user_can('view_register')) {
                throw new WC_API_Exception('woocommerce_api_user_cannot_create_order', __('You do not have permission to create orders', 'woocommerce'), 401);
            }

            $data = apply_filters('woocommerce_api_edit_order_data', $data, $this);

            $order = wc_get_order($id);

            if (empty($order)) {
                throw new WC_API_Exception('woocommerce_api_invalid_order_id', __('Order ID is invalid', 'woocommerce'), 400);
            }

            if (isset($data['create_post']) && is_array($data['create_post'])) {
                foreach ($data['create_post'] as $post) {
                    if (is_array($post)) {
                        foreach ($post as $key => $value) {
                            $_POST[$key] = $value;
                        }
                    }
                }
            }
            $order_args = array('order_id' => $order->get_id(), 'post_type' => 'shop_order');

            // customer note
            if (isset($data['note'])) {
                $order_args['customer_note'] = $data['note'];
            }
            $current_user_id = get_current_user_id();


            $save_order_status = get_option('wc_pos_save_order_status', 'pending');

            if (empty($save_order_status)) {
                $save_order_status = 'pending';
            } else if (strpos($save_order_status, 'wc-') === 0) {
                $save_order_status = substr($save_order_status, 3);
            }
            if ($save_order_status && $data['action'] == 'create') {
                $order_args['status'] = $save_order_status;
                $order_args['created_via'] = 'POS';
            }

            // if creating order for existing customer
            if (isset($data['customer_id'])) {

                if ($data['customer_id'] > 0) {
                    // make sure customer exists
                    if (false === get_user_by('id', $data['customer_id'])) {
                        throw new WC_API_Exception('woocommerce_api_invalid_customer_id', __('Customer ID is invalid', 'woocommerce'), 400);
                    }
                }

                $order_args['customer_id'] = $data['customer_id'];
                $order_args['post_author'] = $data['customer_id'];

                $order->set_customer_id($data['customer_id']);
                $order->save();
            }

            if (isset($order_args['customer_id'])) {
                update_post_meta($order->get_id(), '_customer_user', $order_args['customer_id']);
                $order = wc_get_order($id);
            }
            // set user meta
            if (isset($data['user_meta']) && is_array($data['user_meta']) && isset($order_args['customer_id'])) {
                foreach ($data['user_meta'] as $key => $value) {
                    update_user_meta($order_args['customer_id'], $key, $value);
                }
            }

            $address_fields = array(
                'first_name',
                'last_name',
                'company',
                'email',
                'phone',
                'address_1',
                'address_2',
                'city',
                'state',
                'postcode',
                'country',
            );
            foreach ($address_fields as $mkey) {
                delete_post_meta($order->get_id(), "_billing_" . $mkey);
                delete_post_meta($order->get_id(), "_shipping_" . $mkey);
            }

            // billing/shipping addresses
            $this->set_order_addresses($order, $data);

            $lines = array(
                'line_item' => 'line_items',
                'shipping' => 'shipping_lines',
                'fee' => 'fee_lines',
                'coupon' => 'coupon_lines',
            );

            $items = $wpdb->get_results($wpdb->prepare("SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type != 'line_item'", $order->get_id()));
            if ($items) {
                foreach ($items as $item) {
                    wc_delete_order_item($item->order_item_id);
                }
            }
            $order_items = $order->get_items();
            if (count($order_items) > 0) {
                $order_stock_reduced = get_post_meta($order->get_id(), '_order_stock_reduced', true);
                foreach ($order_items as $item_id => $item) {
                    if ($order_stock_reduced) {
                        $_product = wc_get_product($item_id);

                        if ($_product && $_product->exists() && $_product->managing_stock()) {
                            $current_qty = $_product->get_stock_quantity();
                            $item_meta =  $_product->get_meta_data();
                            $qty = (float)$current_qty - (float)$item_meta['_qty'][0];
                            $new_stock = $_product->set_stock_quantity($qty);
                            $_product->save();
                        }
                    }
                    wc_delete_order_item($item_id);
                    $order->remove_item($item_id);
                }
                delete_post_meta($order->get_id(), '_order_stock_reduced');
            }

            foreach ($lines as $line_type => $line) {

                if (isset($data[$line]) && is_array($data[$line])) {

                    $set_item = "set_{$line_type}";

                    foreach ($data[$line] as $item) {

                        if ($line_type == 'coupon' && $item['code'] == 'POS Discount' && isset($item['type']) && $item['type'] == 'percent' && isset($item['pamount'])) {
                            $item_id = $order->add_coupon($item['code'], isset($item['amount']) ? floatval($item['amount']) : 0);
                            wc_add_order_item_meta($item_id, 'discount_amount_percent', $item['pamount']);
                            wc_add_order_item_meta($item_id, 'wc_pos_discount_reason', isset($item['reason']) ? $item['reason'] : "None");
                        } elseif ($line_type == 'coupon') {
                            $coupon = new WC_Coupon($item['code']);
                            if ($coupon->get_discount_type() == 'smart_coupon') { //Update amount of WC smart coupons
                                $amount = $coupon->get_amount() - $item['amount'];
                                update_post_meta($coupon->get_id(), 'coupon_amount', $amount);
                            }
                            $this->$set_item($order, $item, 'create');
                        } else {
                            $this->$set_item($order, $item, 'create');
                        }
                        if ($line_type == 'coupon' && $item['code'] == 'WC_POINTS_REDEMPTION') {
                            global $wc_points_rewards;
                            $discount_amount = $item['amount'];
                            $points_redeemed = WC_Points_Rewards_Manager::calculate_points_for_discount($discount_amount);

                            // deduct points
                            WC_Points_Rewards_Manager::decrease_points($order->get_user_id(), $points_redeemed, 'order-redeem', array('discount_code' => $item['code'], 'discount_amount' => $discount_amount), $order->get_id());

                            update_post_meta($order->get_id(), '_wc_points_redeemed', $points_redeemed);

                            // add order note
                            $order->add_order_note(sprintf(__('%d %s redeemed for a %s discount.', 'woocommerce-points-and-rewards'), $points_redeemed, $wc_points_rewards->get_points_label($points_redeemed), wc_price($discount_amount)));
                        }
                    }
                }
            }

            // set order meta
            if (isset($data['before_payment_complete']) && is_array($data['before_payment_complete'])) {
                foreach ($data['before_payment_complete'] as $meta_key => $meta_value) {

                    if (is_string($meta_key)) {
                        update_post_meta($order->get_id(), $meta_key, $meta_value);
                    }
                }
            }

            // calculate totals and set them
            //Commented 09.05.17 - custom tax options bug
            /*$order_saved = get_post_meta($order->get_id(), 'wc_pos_order_saved', true);
            if ($order_saved && $order_saved != 1) {
                $order = $this->calculate_order_totals($order, $data);
            }
            $order->calculate_totals();*/
            $order = $this->calculate_order_totals($order, $data);

            // set order meta
            if (isset($data['order_meta']) && is_array($data['order_meta'])) {
                $this->set_order_meta($order->get_id(), $data['order_meta']);
            }

            // payment method (and payment_complete() if `paid` == true and order needs payment)
            if (isset($data['payment_details']) && is_array($data['payment_details'])) {

                // method ID
                if (isset($data['payment_details']['method_id'])) {
                    $order = apply_filters('wc_payment_gateway_' . $data['payment_details']['method_id'] . '_get_order', $order);
                    update_post_meta($order->get_id(), '_payment_method', $data['payment_details']['method_id']);
                }

                // method title
                if (isset($data['payment_details']['method_title'])) {
                    update_post_meta($order->get_id(), '_payment_method_title', $data['payment_details']['method_title']);
                }

                foreach ($order->get_items() as $item_id => $item) {
                    $_product = $order->get_product_from_item($item);
                    if ($_product && $_product->is_type('booking')) {
                        $booking_status = 'paid';
                        // Set as pending when the booking requires confirmation
                        if (wc_booking_requires_confirmation($_product->get_id())) {
                            $booking_status = 'pending-confirmation';
                        }
                        $booking_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_booking_order_item_id' AND meta_value = %d;", $item_id));
                        $booking = get_wc_booking($booking_id);

                        // Update status
                        $booking->update_status($booking_status);
                    }
                }
                // mark as paid if set
                // Commented by: Save Orders Stock Bug -> https://trello.com/c/fKUKn7x3/426-save-orders-stock-bug
                /*if ($order->needs_payment() && isset($data['payment_details']['paid']) && true === $data['payment_details']['paid']) {
                     $order->payment_complete(isset($data['payment_details']['transaction_id']) ? $data['payment_details']['transaction_id'] : '');
                 }*/
            }

            // set order currency
            if (isset($data['currency'])) {

                if (!array_key_exists($data['currency'], get_woocommerce_currencies())) {
                    throw new WC_API_Exception('woocommerce_invalid_order_currency', __('Provided order currency is invalid', 'woocommerce'), 400);
                }

                update_post_meta($order->get_id(), '_order_currency', $data['currency']);
            }

            // set order number
            if (isset($data['order_number'])) {
                update_post_meta($order->get_id(), '_order_number', $data['order_number']);
            }

            // update the order post to set customer note/modified date
            $this->wc_create_order($order_args, $data['action']);

            $count_orders = esc_attr(get_user_meta($current_user_id, 'wc_pos_count_orders', true));
            if (empty($count_orders)) {
                $count_orders = 0;
            }
            update_user_meta($current_user_id, 'wc_pos_count_orders', $count_orders + 1);

            $served_by = get_userdata($current_user_id);
            $served_by_name = '';
            if ($served_by) {
                $served_by_name = $served_by->display_name;
            }
            update_post_meta($order->get_id(), 'wc_pos_served_by_name', $served_by_name);
            update_post_meta($order->get_id(), 'wc_pos_served_by', $current_user_id);

            // set order meta
            if (isset($data['order_meta']) && is_array($data['order_meta'])) {
                $this->set_order_meta($order->get_id(), $data['order_meta']);
            }
            // set order meta
            if (isset($data['custom_order_meta']) && is_array($data['custom_order_meta'])) {
                foreach ($data['custom_order_meta'] as $meta_key => $meta_value) {

                    if (is_string($meta_key)) {
                        update_post_meta($order->get_id(), $meta_key, $meta_value);
                    }
                }
            }

            wc_delete_shop_order_transients($order->get_id());
            //TODO: hm
            do_action('woocommerce_api_create_order', $order->get_id(), $data, $this);
            do_action( 'woocommerce_new_order', $order->get_id() );
            do_action('woocommerce_api_edit_order', $order->get_id(), $data, $this);


            $id_register = (int)$data['order_meta']['wc_pos_id_register'];

            $result = $this->process_payment($order->get_id(), $data);

            // order status
            if (!empty($data['status']) && $result && $result['result'] == 'success' && (!isset($result['redirect']) || empty($result['redirect']))) {
                if ($order->get_status() == $data['status']) {
                    $order->add_order_note(isset($data['status_note']) ? $data['status_note'] : __('Point of Sale transaction completed.', 'wc_point_of_sale'), false, false);
                } else {
                    $order->update_status($data['status'], isset($data['status_note']) ? $data['status_note'] : __('Point of Sale transaction completed.', 'wc_point_of_sale'));
                    //set rounding sum if need
                    if (isset($data['order_meta']['wc_pos_order_rounding'])) {
                        update_post_meta($order->get_id(), '_order_total', $data['order_meta']['wc_pos_rounding_total']);
                    }
                    //Customer provider note
                    wp_update_post(array(
                            'ID' => $id,
                            'post_excerpt' => $data['note']
                        )
                    );
                }
            }

            if (!get_post_meta($order->get_id(), '_order_stock_reduced', true)) {
                switch ($order->get_status()) {
                    case 'completed':
                    case 'processing':
                    case 'on-hold':
                        wc_reduce_stock_levels($order->get_id());
                        break;
                }
            }
            //26.01.18
            //do_action('woocommerce_checkout_order_processed', $order->get_id(), $data);

            if (class_exists('WC_Subscriptions')) {

                foreach ($order->get_items() as $item_id => $item) {
                    $_product = $order->get_product_from_item($item);
                    if ($_product && WC_Subscriptions_Product::is_subscription($_product->get_id())) {

                        // Load all product info including variation data
                        $product_id = (int)apply_filters('woocommerce_add_to_cart_product_id', $item['product_id']);
                        $quantity = (int)$item['qty'];
                        $variation_id = (int)$item['variation_id'];
                        $variations = array();
                        $cart_item_data = array();

                        foreach ($item['item_meta'] as $meta_name => $meta_value) {
                            if (taxonomy_is_product_attribute($meta_name)) {
                                $variations[$meta_name] = $meta_value[0];
                            } elseif (meta_is_product_attribute($meta_name, $meta_value[0], $product_id)) {
                                $variations[$meta_name] = $meta_value[0];
                            }
                        }

                        // Add to cart validation
                        if (!apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations, $cart_item_data)) {
                            continue;
                        }

                        $recurring_cart = clone WC()->cart;
                        $recurring_cart->empty_cart();
                        $recurring_cart->add_to_cart($product_id, $quantity, $variation_id, $variations, $cart_item_data);
                        /*foreach ($recurring_cart->cart_contents as $cart_item_key => &$cart_item) {
                            $cart_item['line_subtotal']     = $item['line_subtotal'];
                            $cart_item['line_subtotal_tax'] = $item['line_subtotal_tax'];
                            $cart_item['line_total']        = $item['line_total'];
                            $cart_item['line_tax']          = $item['line_tax'];
                            $cart_item['line_tax_data']     = maybe_unserialize($item['line_tax_data']);
                            break;
                        }*/
                        $recurring_cart->calculate_totals();


                        $recurring_cart->start_date = apply_filters('wcs_recurring_cart_start_date', gmdate('Y-m-d H:i:s'), $recurring_cart);
                        $recurring_cart->trial_end_date = apply_filters('wcs_recurring_cart_trial_end_date', WC_Subscriptions_Product::get_trial_expiration_date($_product, $recurring_cart->start_date), $recurring_cart, $_product);
                        $recurring_cart->next_payment_date = apply_filters('wcs_recurring_cart_next_payment_date', WC_Subscriptions_Product::get_first_renewal_payment_date($_product, $recurring_cart->start_date), $recurring_cart, $_product);
                        $recurring_cart->end_date = apply_filters('wcs_recurring_cart_end_date', WC_Subscriptions_Product::get_expiration_date($_product, $recurring_cart->start_date), $recurring_cart, $_product);

                        // Before calculating recurring cart totals, store this recurring cart object
                        /*self::$cached_recurring_cart = $recurring_cart;*/

                        // No fees recur (yet)
                        $recurring_cart->fees = array();
                        $recurring_cart->fee_total = 0;
                        $recurring_cart->calculate_totals();

                        #var_dump($recurring_cart);

                        $subscription = WC_Subscriptions_Checkout::create_subscription($order, $recurring_cart, $order); // Exceptions are caught by WooCommerce
                        wp_update_post(array('ID' => $subscription->get_id(), 'post_status' => 'wc-active'));
                        update_post_meta($subscription->get_id(), '_order_total', $recurring_cart->cart_contents_total);

                        $recurring_cart->empty_cart();

                        if (is_wp_error($subscription)) {
                            throw new WC_API_Exception('wc_pos_api_subscription_error', $subscription->get_error_message(), 400);
                        }
                    }
                }
            }

            $this->stock_modified($order);

            if ($data['action'] == 'create') {
                $order = $this->get_order($order->get_id());
                $order['new_order'] = WC_POS()->register()->crate_order_id($id_register);
            } else {
                $order = $this->get_order($order->get_id());
            }
            $order['payment_result'] = $result;
            //pos_logout($id_register);
            //TODO: fix this.
            $wc_order = wc_get_order($id);
            if ($data['fees']) {
                foreach ($data['fees'] as $fee) {
                    $fee_obj = new WC_Order_Item_Fee();
                    $fee_obj->set_order_id($id);
                    $fee_obj->set_name($fee['name']);
                    $fee_obj->set_amount($fee['amount']);
                    $fee_obj->set_total($fee['amount']);
                    if (!$fee['taxable']) {
                        $fee_obj->set_tax_status('none');
                    } else {
                        $fee_obj->set_tax_status('taxable');
                    }
                    $wc_order->add_item($fee_obj);
                }
            }
            $wc_order->calculate_taxes($this->get_tax_location($wc_order, $data['order_meta']['wc_pos_id_register']));
            $wc_order->calculate_shipping();
            $wc_order->calculate_totals(false);

            sentEmailReceipt($id_register, $wc_order->get_id());

            if(isset($data['print_receipt']) && $data['print_receipt'] == "true"){
                if(get_option('wc_pos_enable_cloud_print', 'disable') == "enable"){
                    $register_id = $data['order_meta']['wc_pos_id_register'];
                    $register = WC_Pos_Registers::instance()->get_data($register_id);
                    if(count($register)){
                        $receipt = WC_Pos_Receipts::instance()->get_data($register[0]['detail']['receipt_template']);
                        $mac = isset($register[0]['settings']['receipt_printer']) ? $register[0]['settings']['receipt_printer'] : "";
                        if(WC_Pos_Cloud_Print_Handler::is_valid_mac($mac) && $receipt[0]['print_by_pos_printer'] != "html" ){
                            $order['order']['print_type'] = "normal";
                            WC_Pos_Cloud_Print::wc_pos_woo_on_thankyou($id);
                        }else{
                            $order['order']['print_type'] = "html";
                        }
                    }
                }else{
                    $order['order']['print_type'] = "html";
                }
            }

            return $order;
        } catch (WC_API_Exception $e) {
            return new WP_Error($e->getErrorCode(), $e->getMessage(), array('status' => $e->getCode()));
        }
    }

    /**
     * @param WP_REST_Request $request
     * @param bool $creating
     * @return bool|WC_Data|WP_Error
     * @throws WC_API_Exception
     */
    protected function save_object($request, $creating = false)
    {
        try {

            if (!current_user_can('view_register')) {
                return new WP_Error('woocommerce_api_user_cannot_create_order', __('You do not have permission to create orders', 'woocommerce'), 401);
            }

            $creating = $request["action"] == "create";

            $object = $this->prepare_object_for_database( $request, $creating );

            if ( is_wp_error( $object ) ) {
                return $object;
            }

            // Make sure gateways are loaded so hooks from gateways fire on save/create.
            WC()->payment_gateways();

            if ( ! is_null( $request['customer_id'] ) && 0 !== $request['customer_id'] ) {
                // Make sure customer exists.
                if ( false === get_user_by( 'id', $request['customer_id'] ) ) {
                    throw new WC_REST_Exception( 'woocommerce_rest_invalid_customer_id', __( 'Customer ID is invalid.', 'woocommerce' ), 400 );
                }

                // Make sure customer is part of blog.
                if ( is_multisite() && ! is_user_member_of_blog( $request['customer_id'] ) ) {
                    throw new WC_REST_Exception( 'woocommerce_rest_invalid_customer_id_network', __( 'Customer ID does not belong to this site.', 'woocommerce' ), 400 );
                }
            }

            if ( $creating ) {
                $object->set_created_via( 'POS' );
                $object->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
                $object->calculate_totals();
            } else {
                // If items have changed, recalculate order totals.
                if ( isset( $request['billing'] ) || isset( $request['shipping'] ) || isset( $request['line_items'] ) || isset( $request['shipping_lines'] ) || isset( $request['fee_lines'] ) || isset( $request['coupon_lines'] ) ) {
                    $object->calculate_totals( true );
                }
            }

            // Set coupons.
            $this->calculate_coupons( $request, $object );

            // Set status.
            $object->set_status( $this->get_status($request) );

            $object->save();

            wp_update_post(array(
                'ID' => $object->get_id(),
                'post_type' => 'shop_order',
            ));

            // Actions for after the order is saved.
            if ( true === $request['set_paid'] ) {
                if ( $creating || $object->needs_payment() ) {
                    $object->payment_complete( $request['transaction_id'] );
                }
            }

            return $this->get_object( $object->get_id() );
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
        } catch ( WC_REST_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
        }
    }


    /**
     * @return WC_Order $order
     * @throws WC_REST_Exception
     * @throws WC_API_Exception
     */
    protected function prepare_object_for_database($request, $creating = false ) {
        $id        = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
        $order     = new WC_Order( $id );
        $schema    = $this->get_item_schema();
        $data_keys = array_keys( array_filter( $schema['properties'], array( $this, 'filter_writable_props' ) ) );

        // POS data modifications
        if (isset($request['create_post']) && is_array($request['create_post'])) {
            foreach ($request['create_post'] as $post) {
                if (is_array($post)) {
                    foreach ($post as $key => $value) {
                        $_POST[$key] = $value;
                    }
                }
            }
        }

        $new_meta_data = $request->get_param('meta_data');

        $new_meta_data[] = array(
            'key' => '_order_number',
            'value' => isset($request['order_number']) ? $request['order_number'] : $order->get_order_number()
        );

        $served_by = get_userdata(get_current_user_id());
        $served_by_name = '';
        if ($served_by) {
            $served_by_name = $served_by->display_name;
        }

        $new_meta_data[] = array(
            'key' => 'wc_pos_served_by_name',
            'value' => $served_by_name
        );
        $new_meta_data[] = array(
            'key' => 'wc_pos_served_by',
            'value' => get_current_user_id()
        );

        $request->set_param('customer_id', (int) $this->get_customer_id($request));
        $request->set_param('meta_data', $new_meta_data);

        // Handle all writable props.
        foreach ( $data_keys as $key ) {
            $value = $request[ $key ];

            if ( ! is_null( $value ) ) {
                switch ( $key ) {
                    case 'coupon_lines':
                    case 'status':
                        // Change should be done later so transitions have new data.
                        break;
                    case 'billing':
                    case 'shipping':
                        $this->update_address( $order, $value, $key );
                        break;
                    case 'line_items':
                    case 'shipping_lines':
                    case 'fee_lines':
                        if ( is_array( $value ) ) {
                            foreach ( $value as $item ) {
                                if ( is_array( $item ) ) {
                                    if ( $this->item_is_null( $item ) || ( isset( $item['quantity'] ) && 0 === $item['quantity'] ) ) {
                                        $order->remove_item( $item['id'] );
                                    } else {
                                        $this->set_item( $order, $key, $item );
                                    }
                                }
                            }
                        }
                        break;
                    case 'meta_data':
                        if ( is_array( $value ) ) {
                            foreach ( $value as $meta ) {
                                $order->update_meta_data( $meta['key'], $meta['value'], isset( $meta['id'] ) ? $meta['id'] : '' );
                            }
                        }
                        break;
                    default:
                        if ( is_callable( array( $order, "set_{$key}" ) ) ) {
                            $order->{"set_{$key}"}( $value );
                        }
                        break;
                }
            }
        }

        if($creating){
            $order->set_date_created(new WC_DateTime());
        }

        /**
         * Filters an object before it is inserted via the REST API.
         *
         * The dynamic portion of the hook name, `$this->post_type`,
         * refers to the object type slug.
         *
         * @param WC_Data         $order    Object object.
         * @param WP_REST_Request $request  Request object.
         * @param bool            $creating If is creating a new object.
         */
        return apply_filters( "woocommerce_rest_pre_insert_{$this->post_type}_object", $order, $request, $creating );
    }

    protected function set_item($order, $item_type, $posted)
    {
        global $wpdb;

        if ( ! empty( $posted['id'] ) ) {
            $action = 'update';
        } else {
            $action = 'create';
        }

        $method = 'prepare_' . $item_type;
        $item   = null;

        // Verify provided line item ID is associated with order.
        if ( 'update' === $action ) {
            $item = $order->get_item( absint( $posted['id'] ), false );

            if ( ! $item ) {
                throw new WC_REST_Exception( 'woocommerce_rest_invalid_item_id', __( 'Order item ID provided is not associated with order.', 'woocommerce' ), 400 );
            }
        }

        // Prepare item data.
        $item = $this->$method( $posted, $action, $item );

        do_action( 'woocommerce_rest_set_order_item', $item, $posted );

        // If creating the order, add the item to it.
        if ( 'create' === $action ) {
            $order->add_item( $item );
        } else {
            $item->save();
        }
    }

    /**
     * @param WP_REST_Request $request
     * @param WC_Order $order
     * @return bool
     * @throws WC_REST_Exception
     */
    protected function calculate_coupons($request, $order ) {
        if ( ! isset( $request['coupon_lines'] ) || ! is_array( $request['coupon_lines'] ) ) {
            return false;
        }

        // Remove all coupons first to ensure calculation is correct.
        foreach ( $order->get_items( 'coupon' ) as $coupon ) {
            $order->remove_coupon( $coupon->get_code() );
        }

        foreach ( $request['coupon_lines'] as $item ) {
            if ( is_array( $item ) ) {
                if ( empty( $item['id'] ) ) {
                    if ( empty( $item['code'] ) ) {
                        throw new WC_REST_Exception( 'woocommerce_rest_invalid_coupon', __( 'Coupon code is required.', 'woocommerce' ), 400 );
                    }

                    if ($item['code'] == 'POS Discount' && isset($item['type']) && $item['type'] == 'percent' && isset($item['pamount'])) {
                        $coupon_item = new WC_Order_Item_Coupon();
                        $coupon_item->set_code($item['code']);
                        $coupon_item->set_discount($item['amount']);
                        $coupon_item->add_meta_data('discount_amount_percent', $item['pamount']);
                        $coupon_item->add_meta_data('wc_pos_discount_reason', $item['reason']);

                        $order->add_item($coupon_item);

                    }elseif ($item['code'] == 'WC_POINTS_REDEMPTION' && class_exists('WC_Points_Rewards')){
                        global $wc_points_rewards;
                        $discount_amount = $item['amount'];
                        $points_redeemed = WC_Points_Rewards_Manager::calculate_points_for_discount($discount_amount);

                        // deduct points
                        WC_Points_Rewards_Manager::decrease_points($order->get_user_id(), $points_redeemed, 'order-redeem', array('discount_code' => $item['code'], 'discount_amount' => $discount_amount), $order->get_id());

                        update_post_meta($order->get_id(), '_wc_points_redeemed', $points_redeemed);

                        // add order note
                        $order->add_order_note(sprintf(__('%d %s redeemed for a %s discount.', 'woocommerce-points-and-rewards'), $points_redeemed, $wc_points_rewards->get_points_label($points_redeemed), wc_price($discount_amount)));
                    }else{
                        $results = $order->apply_coupon( wc_clean( $item['code'] ) );

                        if ( is_wp_error( $results ) ) {
                            throw new WC_REST_Exception( 'woocommerce_rest_' . $results->get_error_code(), $results->get_error_message(), 400 );
                        }
                    }
                }
            }
        }

        return true;
    }

    public function refund_order($order)
    {
        wp_send_json_success($order);
    }

    /**
     * Create an order
     *
     * @since 2.2
     * @param array $data raw order data
     * @return array
     */
    public function create_order($data)
    {

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            if (!isset($data['order'])) {
                throw new WC_API_Exception('woocommerce_api_missing_order_data', sprintf(__('No %1$s data specified to create %1$s', 'woocommerce'), 'order'), 400);
            }

            $data = $data['order'];

            // permission check
            if (!current_user_can('view_register')) {
                throw new WC_API_Exception('woocommerce_api_user_cannot_create_order', __('You do not have permission to create orders', 'woocommerce'), 401);
            }

            $data = apply_filters('woocommerce_api_create_order_data', $data, $this);

            // default order args, note that status is checked for validity in wc_create_order()
            $default_order_args = array(
                'status' => isset($data['status']) ? $data['status'] : '',
                'customer_note' => isset($data['note']) ? $data['note'] : null,
            );

            // if creating order for existing customer
            if (!empty($data['customer_id']) && $data['customer_id'] > 0) {

                // make sure customer exists
                if (false === get_user_by('id', $data['customer_id'])) {
                    throw new WC_API_Exception('woocommerce_api_invalid_customer_id', __('Customer ID is invalid', 'woocommerce'), 400);
                }

                $default_order_args['customer_id'] = $data['customer_id'];

            } else if ($data['create_account'] === true) {
                $billing_data = $data['billing_address'];
                $username_opt = get_option('woocommerce_pos_end_of_sale_username_add_customer');
                switch ($username_opt) {
                    case 2:
                        $username = str_replace(' ', '', strtolower($billing_data['first_name'])) . '-' . str_replace(' ', '', strtolower($billing_data['last_name']));
                        break;
                    case 3:
                        $username = $data['email'];
                        break;
                    default:
                        $username = str_replace(' ', '', strtolower($billing_data['first_name'])) . str_replace(' ', '', strtolower($billing_data['last_name']));
                        break;
                }
                $username = _truncate_post_slug($username, 60);
                $check_sql = "SELECT user_login FROM {$wpdb->users} WHERE user_login = '%s' LIMIT 1";

                $user_name_check = $wpdb->get_var($wpdb->prepare($check_sql, $username));


                if ($user_name_check) {
                    $suffix = 1;
                    do {
                        $alt_user_name = _truncate_post_slug($username, 60 - (strlen($suffix) + 1)) . "-$suffix";
                        $user_name_check = $wpdb->get_var($wpdb->prepare($check_sql, $alt_user_name));
                        $suffix++;
                    } while ($user_name_check);
                    $username = $alt_user_name;
                }

                add_filter('pre_option_woocommerce_registration_generate_password', 'pos_enable_generate_password');
                $new_customer = wc_create_new_customer($billing_data['email'], $username, '');
                remove_filter('pre_option_woocommerce_registration_generate_password', 'pos_enable_generate_password');

                if (is_wp_error($new_customer)) {
                    throw new WC_API_Exception('woocommerce_api_cannot_create_customer_account', $new_customer->get_error_message(), 400);
                }

                // Add customer info from other billing fields
                if ($billing_data['first_name'] && apply_filters('wc_pos_checkout_update_customer_data', true, $this)) {
                    $userdata = array(
                        'ID' => $new_customer,
                        'first_name' => $billing_data['first_name'] ? $billing_data['first_name'] : '',
                        'last_name' => $billing_data['last_name'] ? $billing_data['last_name'] : '',
                        'display_name' => $billing_data['first_name'] ? $billing_data['first_name'] : ''
                    );
                    wp_update_user(apply_filters('wc_pos_checkout_customer_userdata', $userdata, $this));
                }

                $default_order_args['customer_id'] = $new_customer;
            }

            // set user meta
            if (isset($data['user_meta']) && is_array($data['user_meta']) && isset($default_order_args['customer_id'])) {
                foreach ($data['user_meta'] as $key => $value) {
                    update_user_meta($default_order_args['customer_id'], $key, $value);
                }
            }


            // create the pending order
            $order = $this->create_base_order($default_order_args, $data);

            if (is_wp_error($order)) {
                throw new WC_API_Exception('woocommerce_api_cannot_create_order', sprintf(__('Cannot create order: %s', 'woocommerce'), implode(', ', $order->get_error_messages())), 400);
            }

            // billing/shipping addresses
            $this->set_order_addresses($order, $data);

            $lines = array(
                'line_item' => 'line_items',
                'shipping' => 'shipping_lines',
                'fee' => 'fee_lines',
                'coupon' => 'coupon_lines',
            );

            foreach ($lines as $line_type => $line) {

                if (isset($data[$line]) && is_array($data[$line])) {

                    $set_item = "set_{$line_type}";

                    foreach ($data[$line] as $item) {

                        $this->$set_item($order, $item, 'create');
                    }
                }
            }

            // set order meta
            if (isset($data['before_payment_complete']) && is_array($data['before_payment_complete'])) {
                foreach ($data['before_payment_complete'] as $meta_key => $meta_value) {

                    if (is_string($meta_key)) {
                        update_post_meta($order->get_id(), $meta_key, $meta_value);
                    }
                }
            }

            // calculate totals and set them
            $order = $this->calculate_order_totals($order, $data);
            //$order->calculate_totals();

            // payment method (and payment_complete() if `paid` == true)
            if (isset($data['payment_details']) && is_array($data['payment_details'])) {

                // method ID & title are required
                if (empty($data['payment_details']['method_id']) || empty($data['payment_details']['method_title'])) {
                    throw new WC_API_Exception('woocommerce_invalid_payment_details', __('Payment method ID and title are required', 'woocommerce'), 400);
                }

                update_post_meta($order->get_id(), '_payment_method', $data['payment_details']['method_id']);
                update_post_meta($order->get_id(), '_payment_method_title', $data['payment_details']['method_title']);

                // mark as paid if set
                if (isset($data['payment_details']['paid']) && true === $data['payment_details']['paid']) {
                    $order->payment_complete(isset($data['payment_details']['transaction_id']) ? $data['payment_details']['transaction_id'] : '');
                }
            }

            // set order currency
            if (isset($data['currency'])) {

                if (!array_key_exists($data['currency'], get_woocommerce_currencies())) {
                    throw new WC_API_Exception('woocommerce_invalid_order_currency', __('Provided order currency is invalid', 'woocommerce'), 400);
                }

                update_post_meta($order->get_id(), '_order_currency', $data['currency']);
            }

            // set order number
            if (isset($data['order_number'])) {

                update_post_meta($order->get_id(), '_order_number', $data['order_number']);
            }

            // set order meta
            if (isset($data['order_meta']) && is_array($data['order_meta'])) {
                $this->set_order_meta($order->get_id(), $data['order_meta']);
            }


            // HTTP 201 Created
            $this->server->send_status(201);

            wc_delete_shop_order_transients($order->get_id());

            do_action('woocommerce_api_create_order', $order->get_id(), $data, $this);

            $wpdb->query('COMMIT');

            $id_register = $data['order_meta']['wc_pos_id_register'];

            sentEmailReceipt($id_register, $order->get_id());

            $order = $this->get_order($order->get_id());

            return $order;
        } catch (WC_API_Exception $e) {

            $wpdb->query('ROLLBACK');

            return new WP_Error($e->getErrorCode(), $e->getMessage(), array('status' => $e->getCode()));
        }
    }

    /**
     * Create or update a line item
     *
     * @since 2.2
     * @param \WC_Order $order
     * @param array $item line item data
     * @param string $action 'create' to add line item or 'update' to update it
     * @throws WC_API_Exception invalid data, server error
     */
    protected function set_line_item(  $order, $item, $action)
    {

        $creating = ('create' === $action);

        // product is always required
        if (!isset($item['product_id']) && !isset($item['sku'])) {
            throw new WC_API_Exception('woocommerce_api_invalid_product_id', __('Product ID or SKU is required', 'woocommerce'), 400);
        }

        // when updating, ensure product ID provided matches
        if ('update' === $action) {

            $item_product_id = wc_get_order_item_meta($item['id'], '_product_id');
            $item_variation_id = wc_get_order_item_meta($item['id'], '_variation_id');

            if ($item['product_id'] != $item_product_id && $item['product_id'] != $item_variation_id) {
                throw new WC_API_Exception('woocommerce_api_invalid_product_id', __('Product ID provided does not match this line item', 'woocommerce'), 400);
            }
        }

        if (isset($item['product_id'])) {
            $product_id = $item['product_id'];
        } elseif (isset($item['sku'])) {
            $product_id = wc_get_product_id_by_sku($item['sku']);
        }

        $item_args = array();

        // variations must each have a key & value
        $variation_id = 0;
        if (isset($item['variations']) && is_array($item['variations'])) {
            foreach ($item['variations'] as $key => $value) {
                if (!$key || !$value) {
                    throw new WC_API_Exception('woocommerce_api_invalid_product_variation', __('The product variation is invalid', 'woocommerce'), 400);
                }
            }
            $item_args['variation'] = $item['variations'];
        }
        if (isset($item['variation_id']) && $item['variation_id'] > 0) {
            $variation_id = $item['variation_id'];
        }

        $product = wc_get_product($variation_id ? $variation_id : $product_id);

        // must be a valid WC_Product
        if (!is_object($product)) {
            throw new WC_API_Exception('woocommerce_api_invalid_product', __('Product is invalid' . $product_id, 'woocommerce'), 400);
        }

        // quantity must be positive float
        if (isset($item['quantity']) && floatval($item['quantity']) <= 0) {
            throw new WC_API_Exception('woocommerce_api_invalid_product_quantity', __('Product quantity must be a positive float', 'woocommerce'), 400);
        }

        // quantity is required when creating
        if ($creating && !isset($item['quantity'])) {
            throw new WC_API_Exception('woocommerce_api_invalid_product_quantity', __('Product quantity is required', 'woocommerce'), 400);
        }

        // quantity
        if (isset($item['quantity'])) {
            $item_args['qty'] = $item['quantity'];
        }

        // total
        if (isset($item['total'])) {
            $item_args['totals']['total'] = floatval($item['total']);
        }

        // total tax
        if (isset($item['total_tax'])) {
            $item_args['totals']['tax'] = floatval($item['total_tax']);
        }

        // subtotal
        if (isset($item['subtotal'])) {
            $item_args['totals']['subtotal'] = floatval($item['subtotal']);
        }

        // subtotal tax
        if (isset($item['subtotal_tax'])) {
            $item_args['totals']['subtotal_tax'] = floatval($item['subtotal_tax']);
        }

        // subtotal tax
        if (isset($item['tax_data'])) {
            $item_args['totals']['tax_data'] = $item['tax_data'];
        }

        if (class_exists('woocommerce_wpml')) {
            $data = array(
                'ID' => get_option('wc_pos_custom_product_id'),
                'post_title' => $item['title'],
            );
            wp_update_post($data);
        }
        // Set title
        if (isset($item['title']) && !empty($item['title'])) {
            if (WC_VERSION >= 3) {
                $product->set_name($item['title']);
            } else {
                $product->post->post_title = $item['title'];
            }
        }

        // Set tax class
        if (isset($item['tax_class'])) {
            $product->set_tax_class($item['tax_class']);
            $item_args['tax_class'] = $item['tax_class'];
        }

        // Set tax status
        if (isset($item['tax_status'])) {
            $product->set_tax_status($item['tax_status']);
        }
        $decimal_quantity = get_option('wc_pos_decimal_quantity');
        if ($decimal_quantity == 'yes') {
            remove_filter('woocommerce_stock_amount', 'intval');
            add_filter('woocommerce_stock_amount', 'floatval');
        }
        if ($creating) {

            $item_id = $order->add_product($product, $item_args['qty'], $item_args);
            wc_update_order_item_meta($item_id, '_price', $item['price']);

            //hidden_fields
            if (isset($item['hidden_fields']) && isset($item['hidden_fields']['booking'])) {

                parse_str($item['hidden_fields']['booking'], $posted_data);

                if (isset($posted_data['booking_id'])) {
                    $new_booking = get_wc_booking($posted_data['booking_id']);
                    $new_booking->update_status('in-cart');
                    $new_booking->set_order_id($order->get_id(), $item_id);
                } else {
                    $booking_form = new WC_Booking_Form($product);
                    $cart_item_meta = array();
                    $cart_item_meta['booking'] = $booking_form->get_posted_data($posted_data);
                    $cart_item_meta['booking']['_cost'] = $booking_form->calculate_booking_cost($posted_data);
                    $cart_item_meta['booking']['_order_item_id'] = $item_id;

                    // Create the new booking
                    $new_booking = $this->create_booking_from_cart_data($cart_item_meta, $product_id);
                    $new_booking->set_customer_id($order->get_customer_id());
                    $new_booking->set_order_id($order->get_id());
                    $new_booking->save();

                    wc_add_order_item_meta($item_id, __('Booking ID', 'woocommerce-bookings'), $new_booking->get_id());
                }

                // Schedule this item to be removed from the cart if the user is inactive
                $this->schedule_cart_removal($new_booking->get_id());

                // Store in order
            }

            if (!$item_id) {
                throw new WC_API_Exception('woocommerce_cannot_create_line_item', __('Cannot create line item, try again', 'woocommerce'), 500);
            }

        } else {

            $item_id = $order->update_product($item['id'], $product, $item_args);
            wc_update_order_item_meta($item_id, '_price', $item['price']);

            if (!$item_id) {
                throw new WC_API_Exception('woocommerce_cannot_update_line_item', __('Cannot update line item, try again', 'woocommerce'), 500);
            }
            $item_id = $item['id'];
        }
        return $item_id;
    }

    /**
     * Create or update an order shipping method
     *
     * @since 2.2
     * @param \WC_Order $order
     * @param array $shipping item data
     * @param string $action 'create' to add shipping or 'update' to update it
     * @throws WC_API_Exception invalid data, server error
     */
    protected function set_shipping($order, $shipping, $action)
    {

        // total must be a positive float
        if (isset($shipping['total']) && floatval($shipping['total']) < 0) {
            throw new WC_API_Exception('woocommerce_invalid_shipping_total', __('Shipping total must be a positive amount', 'woocommerce'), 400);
        }
        $total = wc_format_decimal($shipping['total']);

        if ('create' === $action) {

            $item_id = wc_add_order_item($order->get_id(), array(
                'order_item_name' => isset($shipping['method_title']) ? $shipping['method_title'] : __('Shipping', 'woocommerce'),
                'order_item_type' => 'shipping'
            ));

            if (!$item_id) {
                throw new WC_API_Exception('woocommerce_cannot_create_shipping', __('Cannot create shipping method, try again', 'woocommerce'), 500);
            }

            wc_add_order_item_meta($item_id, 'method_id', '');
            wc_add_order_item_meta($item_id, 'cost', $total);

            if (!empty($shipping['taxes'])) {
                $taxes = array_map('wc_format_decimal', $shipping['taxes']);
                wc_add_order_item_meta($item_id, 'taxes', $taxes);
            }

            // Update total
            $order->set_total($order->get_shipping_total() + $total, 'shipping');

        } else {

            $shipping_args = array();

            if (isset($shipping['method_id'])) {
                $shipping_args['method_id'] = $shipping['method_id'];
            }

            if (isset($shipping['method_title'])) {
                $shipping_args['method_title'] = $shipping['method_title'];
            }

            if (isset($shipping['total'])) {
                $shipping_args['cost'] = floatval($shipping['total']);
            }

            $shipping_id = $order->update_shipping($shipping['id'], $shipping_args);

            if (!$shipping_id) {
                throw new WC_API_Exception('woocommerce_cannot_update_shipping', __('Cannot update shipping method, try again', 'woocommerce'), 500);
            }
        }
    }

    /**
     * Calculate totals by looking at the contents of the order. Stores the totals and returns the orders final total.
     *
     * @since 2.2
     * @param WC_Order $order
     * @param array $data
     * @param  $and_taxes bool Calc taxes if true
     * @return WC_Order calculated order
     */
    public function calculate_order_totals($order, $data, $and_taxes = true)
    {
        $cart_subtotal = 0;
        $cart_total = 0;
        $fee_total = 0;
        $cart_subtotal_tax = 0;
        $cart_total_tax = 0;

        if ($and_taxes && wc_pos_tax_enabled() && get_option('woocommerce_pos_tax_calculation', 'enabled') == 'enabled') {
            $order = $this->calculate_order_taxes($order, $data);
        } else {
            // Save tax totals
            $order->set_shipping_tax(0);
            $order->set_cart_tax(0);
        }

        // line items
        foreach ($data['line_items'] as $item) {
            $cart_subtotal += wc_format_decimal(isset($item['subtotal']) ? $item['subtotal'] : 0);
            $cart_total += wc_format_decimal(isset($item['total']) ? $item['total'] : 0);
            $cart_subtotal_tax += wc_format_decimal(isset($item['subtotal_tax']) ? $item['subtotal_tax'] : 0);
            $cart_total_tax += wc_format_decimal(isset($item['total_tax']) ? $item['total_tax'] : 0);
        }

        $order->calculate_shipping();

        foreach ($order->get_fees() as $item) {
            $fee_total += $item['line_total'];
        }

        $order->set_discount_total($cart_subtotal - $cart_total);
        $order->set_discount_tax( $cart_subtotal_tax - $cart_total_tax );
        $order->set_total( round( $cart_total + $fee_total + $order->get_shipping_total() + $order->get_cart_tax() + $order->get_shipping_tax(), wc_get_price_decimals() ) );
//        $order->set_cart_tax($cart_subtotal_tax - $cart_total_tax, 'cart_discount_tax');

//        $grand_total = round($cart_total + $fee_total + $order->get_total_shipping() + $order->get_cart_tax() + $order->get_shipping_tax(), wc_get_price_decimals());
//        echo "<pre>";
//            print_r($grand_total);
//        echo "</pre>";
//        $order->legacy_set_total( $grand_total);
        //TODO: maybe right $order->set_total($cart_total);
        $order->save();
        return $order;
    }

    /**
     * Calculate taxes for all line items and shipping, and store the totals and tax rows.
     *
     * Will use the base country unless customer addresses are set.
     *
     * @return bool success or fail
     */
    public function calculate_order_taxes( $order, $data)
    {

        $tax_total = 0;
        $shipping_tax_total = 0;
        $taxes = array();
        $shipping_taxes = array();
        $location = $this->get_tax_location($order, $data['order_meta']['wc_pos_id_register']);

        // Get items
        $tax_classes = array();
        $data_key = 0;

        foreach ($order->get_items(array('line_item', 'fee')) as $item_id => $item) {

            if (isset($data['line_items'][$data_key])) {


                $product = $order->get_product_from_item($item);
                $line_total = isset($data['line_items'][$data_key]['total']) ? $data['line_items'][$data_key]['total'] : 0;
                $line_subtotal = isset($data['line_items'][$data_key]['subtotal']) ? $data['line_items'][$data_key]['subtotal'] : 0;
                $tax_class = $data['line_items'][$data_key]['tax_class'];
                if (get_option('woocommerce_pos_tax_calculation', 'enabled') == 'enabled') {
                    $item_tax_status = $product ? $product->get_tax_status() : 'taxable';
                } else {
                    $item_tax_status = 'none';
                }

                if ('0' !== $tax_class && 'taxable' === $item_tax_status) {
                    $tax_classes[] = $tax_class;

                    $tax_rates = WC_Tax::find_rates(array(
                        'country' => $location['country'],
                        'state' => $location['state'],
                        'postcode' => $location['postcode'],
                        'city' => $location['city'],
                        'tax_class' => $tax_class
                    ));

                    $line_subtotal_taxes = WC_Tax::calc_tax($line_subtotal, $tax_rates, false);
                    $line_taxes = WC_Tax::calc_tax($line_total, $tax_rates, false);
                    $line_subtotal_tax = max(0, array_sum($line_subtotal_taxes));
                    $line_tax = max(0, array_sum($line_taxes));
                    $tax_total += $line_tax;

                    wc_update_order_item_meta($item_id, '_line_subtotal_tax', wc_format_decimal($line_subtotal_tax));
                    wc_update_order_item_meta($item_id, '_line_tax', wc_format_decimal($line_tax));
                    wc_update_order_item_meta($item_id, '_line_tax_data', array('total' => $line_taxes, 'subtotal' => $line_subtotal_taxes));

                    // Sum the item taxes
                    foreach (array_keys($taxes + $line_taxes) as $key) {
                        $taxes[$key] = (isset($line_taxes[$key]) ? $line_taxes[$key] : 0) + (isset($taxes[$key]) ? $taxes[$key] : 0);
                    }
                }
            $data_key++;
            }
        }

        // Now calculate shipping tax
        if (isset($data['shipping_lines'])) {
            $shipping_tax_class = get_option('woocommerce_shipping_tax_class');
            $tax_class = '';
            if ($shipping_tax_class == '' && !empty($tax_classes)) {
                $tax_class = $tax_classes[0];
            } else {
                $tax_class = 'standard' === $shipping_tax_class ? '' : $shipping_tax_class;
            }
            $matched_tax_rates = array();
            $tax_rates = WC_Tax::find_rates(array(
                'country' => $location['country'],
                'state' => $location['state'],
                'postcode' => $location['postcode'],
                'city' => $location['city'],
                'tax_class' => $tax_class
            ));

            if (!empty($tax_rates)) {
                foreach ($tax_rates as $key => $rate) {
                    if (isset($rate['shipping']) && 'yes' === $rate['shipping']) {
                        $matched_tax_rates[$key] = $rate;
                    }
                }
            }

//            $shipping_taxes = WC_Tax::calc_shipping_tax($order->get_shipping_total(), $matched_tax_rates);
//            $shipping_tax_total = WC_Tax::round(array_sum($shipping_taxes));
            $order->set_shipping_tax($shipping_tax_total);

        }

        // Save tax totals
        $order->set_cart_tax($tax_total);
//        $order->calculate_taxes();
        $order->calculate_totals(false);

//        echo "<pre>";
//            print_r($order);
//        echo "</pre>"; exit;
        // Tax rows
        //$order->remove_order_items('tax');

        // Now merge to keep tax rows
        foreach (array_keys($taxes + $shipping_taxes) as $tax_rate_id) {

//                $ship_obj = new WC_Order_Item_Tax();
//                $ship_obj->set_order_id($order->get_id());
//                $ship_obj->set_rate($tax_rate_id);
//                $ship_obj->set_tax_total(isset($taxes[$tax_rate_id]) ? $taxes[$tax_rate_id] : 0);
//                $ship_obj->set_shipping_tax_total(isset($shipping_taxes[$tax_rate_id]) ? $shipping_taxes[$tax_rate_id] : 0);
//                $ship_obj->save();
//                $order->add_item($ship_obj);
//                $order->set_cart_tax((isset($taxes[$tax_rate_id]) ? $taxes[$tax_rate_id] : 0) + (isset($shipping_taxes[$tax_rate_id]) ? $shipping_taxes[$tax_rate_id] : 0));
//                $order->calculate_taxes();
//                $order->calculate_totals();
//                $order->save();

//            $item = new WC_Order_Item_Tax();
//            $item->set_props( array(
//                'rate_id'            => $tax_rate_id,
//                'tax_total'          => isset($taxes[$tax_rate_id]) ? $taxes[$tax_rate_id] : 0,
//                'shipping_tax_total' => isset($shipping_taxes[$tax_rate_id]) ? $shipping_taxes[$tax_rate_id] : 0,
//            ) );
//            $item->set_rate( $tax_rate_id );
//            $item->set_order_id( $order->get_id() );
//            $item->save();
//            $order->add_item( $item );

//            $order->add_tax($tax_rate_id, isset($taxes[$tax_rate_id]) ? $taxes[$tax_rate_id] : 0, isset($shipping_taxes[$tax_rate_id]) ? $shipping_taxes[$tax_rate_id] : 0);
        }

        $order->save();
        return $order;
    }

    private function get_customer_taxable_address( $order, $id_register)
    {
        $taxable_address = array(
            'country' => '',
            'state' => '',
            'postcode' => '',
            'city' => ''
        );
        $tax_based_on = get_option('woocommerce_pos_calculate_tax_based_on', 'outlet');
        if ($tax_based_on == 'default') {
            $tax_based_on = get_option('woocommerce_tax_based_on');
        }

        $customer_id = isset($data['customer_id']) ? $data['customer_id'] : 0;
        $billing_country = $order->get_billing_country();
        if ($customer_id || !empty($billing_country)) {
            switch ($tax_based_on) {
                case 'billing':
                    $taxable_address = array(
                        'country' => $billing_country,
                        'state' => $order->get_billing_state(),
                        'postcode' => $order->get_billing_postcode(),
                        'city' => $order->get_billing_city()
                    );
                    break;
                case 'shipping':
                    $taxable_address = array(
                        'country' => $order->get_shipping_country(),
                        'state' => $order->get_shipping_state(),
                        'postcode' => $order->get_shipping_postcode(),
                        'city' => $order->get_shipping_city()
                    );
                    break;
            }
        } else {
            $default_customer_addr = get_option('woocommerce_pos_tax_default_customer_address', 'outlet');
            switch ($default_customer_addr) {
                case 'base':
                    $default = wc_get_base_location();
                    $taxable_address = array(
                        'country' => $default['country'],
                        'state' => $default['state'],
                        'postcode' => '',
                        'city' => ''
                    );
                    break;
                case 'outlet':
                    $default = wc_pos_get_outlet_location($id_register);
                    $taxable_address = array(
                        'country' => $default['contact']['country'],
                        'state' => $default['contact']['state'],
                        'postcode' => $default['contact']['postcode'],
                        'city' => $default['contact']['city']
                    );
                    break;
            }
        }
        return $taxable_address;
    }

    /**
     * Create a new order programmatically
     *
     * Returns a new order object on success which can then be used to add additional data.
     *
     * @return WC_Order on success, WP_Error on failure
     */
    private function wc_create_order($args = array(), $action = "create")
    {
        $updating = false;
        if ($action != "create") {
            $updating = true;
        }

        $default_args = array(
            'status' => '',
            'customer_id' => null,
            'customer_note' => null,
            'order_id' => 0,
            'created_via' => '',
            'parent' => 0
        );

        $args = wp_parse_args($args, $default_args);
        $order_data = array();

        $order_data['ID'] = $args['order_id'];
        $order_data['post_author'] = $args['post_author'];

        if (!$updating) {
            $order_data['post_type'] = 'shop_order';
            $order_data['post_status'] = 'wc-' . apply_filters('woocommerce_default_order_status', 'pending');
            $order_data['ping_status'] = 'closed';
            $order_data['post_password'] = uniqid('order_');
            $order_data['post_title'] = sprintf(__('Order &ndash; %s', 'woocommerce'), strftime(_x('%b %d, %Y @ %I:%M %p', 'Order date parsed by strftime', 'woocommerce')));
            $order_data['post_parent'] = absint($args['parent']);

            if ($args['status'] && !empty($args['status'])) {
                if (!in_array('wc-' . $args['status'], array_keys(wc_get_order_statuses()))) {
                    return new WP_Error('woocommerce_invalid_order_status', __('Invalid order status', 'woocommerce'));
                }
                $order_data['post_status'] = 'wc-' . $args['status'];
            }
        }

        if (!is_null($args['customer_note'])) {
            $order_data['post_excerpt'] = $args['customer_note'];
        }

        $old_status = get_post_status($args['order_id']);
        $order_id = wp_update_post($order_data);
        $new_status = get_post_status($args['order_id']);

        if (strpos($old_status, 'wc-') === 0) {
            $old_status = substr($old_status, 3);
        }
        if (strpos($new_status, 'wc-') === 0) {
            $new_status = substr($new_status, 3);
        }


        if (is_wp_error($order_id)) {
            return $order_id;
        }

        if (!$updating) {
            update_post_meta($order_id, '_order_key', 'wc_' . apply_filters('woocommerce_generate_order_key', uniqid('order_')));
            update_post_meta($order_id, '_order_currency', get_woocommerce_currency());
            update_post_meta($order_id, '_prices_include_tax', get_option('woocommerce_tax_display_cart'));
            update_post_meta($order_id, '_customer_ip_address', WC_Geolocation::get_ip_address());
            update_post_meta($order_id, '_customer_user_agent', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
            update_post_meta($order_id, '_customer_user', 0);
            update_post_meta($order_id, '_created_via', sanitize_text_field($args['created_via']));
        }

        if (is_numeric($args['customer_id'])) {
            update_post_meta($order_id, '_customer_user', $args['customer_id']);
        }

        update_post_meta($order_id, '_order_version', WC_VERSION);

        do_action('woocommerce_order_status_' . $new_status, $order_id);
        if ($old_status != $new_status) {
            do_action('woocommerce_order_status_' . $old_status . '_to_' . $new_status, $order_id);
            do_action('woocommerce_order_status_changed', $order_id, $old_status, $new_status);
        }

        return wc_get_order($order_id);
    }

    /**
     * Process payment
     * @param $order_id
     * @param $data
     */
    public function process_payment($order_id, $data)
    {
        if (!isset($data['payment_details'])) {
            return false;
        }

        if ($data['payment_details']['method_id'] == "pos_chip_pin" || $data['payment_details']['method_id'] == "cod" || $data['payment_details']['method_id'] == "bacs" || $data['payment_details']['method_id'] == "pos_chip_pin2" || $data['payment_details']['method_id'] == "pos_chip_pin3") {
            $response = array('result' => 'success', 'redirect' => '');
            return $response;
        }

        // some gateways check if a user is signed in, so let's switch to customer
        $logged_in_user = get_current_user_id();
        $customer_id = isset($data['customer_id']) ? $data['customer_id'] : 0;
        wp_set_current_user($customer_id);

        // load the gateways & process payment
        $gateway_id = $data['payment_details']['method_id'];
        $docompleteorder = 0;
        switch ($gateway_id) {
            case 'stripe':
                $_POST['stripe_source'] = $data['stripe_source'];
                break;
            case 'simplify_commerce':
                $_POST['simplify_token'] = $data['simplify_token'];
                break;
            case 'telematika_secure_acceptance_sop':
                $docompleteorder = 1;
                break;
            case 'cybersource_secure_acceptance_sop':
                $docompleteorder = 1;
                break;
        }
        //add_filter('option_woocommerce_'. $gateway_id .'_settings', array($this, 'force_enable_gateway'));

        $gateways = WC()->payment_gateways->get_available_payment_gateways();

        //$gateways = $settings->load_enabled_gateways();
        if (isset($_POST['all_fields'])) {
            $_POST['all_fields'] = $_POST['all_fields'] . '&terms=1';
        }

        if (isset($data['paymentSense'])){
            $_POST['paymentSenseData'] = $data['paymentSense'];
        }

        $response = $gateways[$gateway_id]->process_payment($order_id);

        if (isset($response['result']) && $response['result'] == 'success') {
            if ($docompleteorder == 1) {
                do_action('woocommerce_payment_complete', $order_id);
                $result = $this->payment_success($gateway_id, $order_id, $response);
            } else {
                //Commented by Double Stock Bug
                //do_action('woocommerce_payment_complete', $order_id);
                //update_post_meta($order_id, '_order_stock_reduced', 1);
                $result = $this->payment_success($gateway_id, $order_id, $response);
            }
        } else {
            $result = $this->payment_failure($gateway_id, $order_id, $response);
        }

        // switch back to logged in user
        wp_set_current_user($logged_in_user);


        // clear any payment gateway messages
        wc_clear_notices();

        return $result;
    }

    /**
     * @param $gateway_id
     * @param $order_id
     * @param $response
     */
    private function payment_success($gateway_id, $order_id, $response)
    {
        // capture any instructions
        ob_start();
        do_action('woocommerce_thankyou_' . $gateway_id, $order_id);
        $response['messages'] = ob_get_contents();
        ob_end_clean();

        // redirect
        if (isset($response['redirect'])) {
            $response = $this->payment_redirect($gateway_id, $order_id, $response);
        }

        //Remove authorize.net redirect
        if (in_array($gateway_id, array('authorizenet', 'paypal_pro_payflow'))) {
            $response['redirect'] = '';
        }

        $response['result'] = 'success';

        return $response;
    }

    /**
     * @param $gateway_id
     * @param $order_id
     * @param $response
     * @return array
     */
    private function payment_failure($gateway_id, $order_id, $response)
    {
        $message = isset($response['messages']) ? $response['messages'] : wc_get_notices('error');

        // if messages empty give generic response
        if (empty($message)) {
            $message = __('There was an error processing the payment', 'wc_point_of_sale');
        }

        $response['messages'] = $message;
        $response['result'] = 'error';

        return $response;
    }

    /**
     * @param $gateway_id
     * @param $order_id
     * @param $response
     * @return array
     */
    private function payment_redirect($gateway_id, $order_id, $response)
    {
        $message = $response['messages'];

        // compare url fragments
        $success_url = wc_get_endpoint_url('order-received', $order_id, get_permalink(wc_get_page_id('checkout')));
        $order = wc_get_order($order_id);
        $success_url = apply_filters('woocommerce_get_return_url', $success_url, $order);
        $success = wp_parse_args(parse_url($success_url), array('host' => '', 'path' => ''));
        $redirect = wp_parse_args(parse_url($response['redirect']), array('host' => '', 'path' => ''));

        $offsite = $success['host'] !== $redirect['host'];
        $reload = !$offsite && $success['path'] !== $redirect['path'] && $response['messages'] == '';

        if ($offsite || $reload) {
            update_post_meta($order_id, '_pos_payment_redirect', $response['redirect']);
            $message = __('You are now being redirected offsite to complete the payment. ', 'wc_point_of_sale');
            $message .= sprintf(__('<a href="%s">Click here</a> if you are not redirected automatically. ', 'wc_point_of_sale'), $response['redirect']);
        } else {
            $response['redirect'] = '';
        }
        $response['messages'] = $message;
        if( $gateway_id == 'angelleye_woo_paypal_here') {
            $response['messages'] = $response['redirect'];
        }

        return $response;
    }

    public function stock_modified( $order)
    {

        $post_modified = current_time('mysql');
        $post_modified_gmt = current_time('mysql', true);

        //var_dump($post_modified);

        $order_args = array(
            'ID' => $order->get_id(),
            'post_date' => $post_modified,
            'post_date_gmt' => $post_modified_gmt,
            'post_modified' => $post_modified,
            'post_modified_gmt' => $post_modified_gmt
        );

        $order_id = wp_update_post($order_args, true);

        foreach ($order->get_items() as $item) {

            wp_update_post(array(
                'ID' => $item['product_id'],
                'post_modified' => $post_modified,
                'post_modified_gmt' => $post_modified_gmt
            ));

            // TODO: if variable, update the parent?
            $id = isset($item['variation_id']) && is_numeric($item['variation_id']) && $item['variation_id'] > 0 ? $item['variation_id'] : 0;
            if ($id > 0) {
                wp_update_post(array(
                    'ID' => $id,
                    'post_modified' => $post_modified,
                    'post_modified_gmt' => $post_modified_gmt
                ));
            }
        }
    }

    /**
     * Create booking from cart data
     */
    private function create_booking_from_cart_data($cart_item_meta, $product_id, $status = 'in-cart')
    {
        // Create the new booking
        $new_booking_data = array(
            'product_id' => $product_id, // Booking ID
            'cost' => $cart_item_meta['booking']['_cost'], // Cost of this booking
            'start_date' => $cart_item_meta['booking']['_start_date'],
            'end_date' => $cart_item_meta['booking']['_end_date'],
            'all_day' => $cart_item_meta['booking']['_all_day'],
            'order_item_id' => $cart_item_meta['booking']['_order_item_id']
        );

        // Check if the booking has resources
        if (isset($cart_item_meta['booking']['_resource_id'])) {
            $new_booking_data['resource_id'] = $cart_item_meta['booking']['_resource_id']; // ID of the resource
        }

        // Checks if the booking allows persons
        if (isset($cart_item_meta['booking']['_persons'])) {
            $new_booking_data['persons'] = $cart_item_meta['booking']['_persons']; // Count of persons making booking
        }

        $new_booking = get_wc_booking($new_booking_data);
        $new_booking->create($status);

        return $new_booking;
    }

    /**
     * Schedule booking to be deleted if inactive
     */
    public function schedule_cart_removal($booking_id)
    {
        wp_clear_scheduled_hook('wc-booking-remove-inactive-cart', array($booking_id));
        wp_schedule_single_event(apply_filters('woocommerce_bookings_remove_inactive_cart_time', time() + (60 * 15)), 'wc-booking-remove-inactive-cart', array($booking_id));
    }

    public function create_order_refund($order_id, $data, $api_refund = true)
    {
        try {

            if(get_option('wc_pos_refund_approval', 'no') == 'yes'){
                $user_approval = get_user_meta(get_current_user_id(), 'approve_refunds', true);
                if(!$user_approval || $user_approval == 'no'){
                    throw new WC_API_Exception( 'wc_point_of_sale_no_permission_for_refund', __('You do not have permission to process refunds', 'wc_point_of_sale'), 400 );
                }
            }

            if ( ! isset( $data['order_refund'] ) ) {
                throw new WC_API_Exception( 'woocommerce_api_missing_order_refund_data', sprintf( __( 'No %1$s data specified to create %1$s', 'woocommerce' ), 'order_refund' ), 400 );
            }

            $data = $data['order_refund'];

            // Permission check
            if ( ! current_user_can( 'publish_shop_orders' ) ) {
                throw new WC_API_Exception( 'woocommerce_api_user_cannot_create_order_refund', __( 'You do not have permission to create order refunds', 'woocommerce' ), 401 );
            }

            $order_id = absint( $order_id );

            if ( empty( $order_id ) ) {
                throw new WC_API_Exception( 'woocommerce_api_invalid_order_id', __( 'Order ID is invalid', 'woocommerce' ), 400 );
            }

            $data = apply_filters( 'woocommerce_api_create_order_refund_data', $data, $order_id, $this );

            // Refund amount is required
            if ( ! isset( $data['amount'] ) ) {
                throw new WC_API_Exception( 'woocommerce_api_invalid_order_refund', __( 'Refund amount is required.', 'woocommerce' ), 400 );
            } elseif ( 0 > $data['amount'] ) {
                throw new WC_API_Exception( 'woocommerce_api_invalid_order_refund', __( 'Refund amount must be positive.', 'woocommerce' ), 400 );
            }

            $data['order_id']  = $order_id;
            $data['refund_id'] = 0;

            // Create the refund
            $refund = wc_create_refund( $data );

            if(is_wp_error($refund)){
                throw new WC_API_Exception( 'woocommerce_api_cannot_create_order_refund', $refund->get_error_message(), 400);
            }

            if ( ! $refund ) {
                throw new WC_API_Exception( 'woocommerce_api_cannot_create_order_refund', __( 'Cannot create order refund, please try again.', 'woocommerce' ), 500 );
            }

            if(isset($data['refund_payment'])){
                $api_refund = false;
                $this->set_refund_data($data, $refund);
            }

            // Refund via API
            if ( $api_refund ) {
                if ( WC()->payment_gateways() ) {
                    $payment_gateways = WC()->payment_gateways->payment_gateways();
                }

                $order = wc_get_order( $order_id );

                if ( isset( $payment_gateways[ $order->get_payment_method() ] ) && $payment_gateways[ $order->get_payment_method() ]->supports( 'refunds' ) ) {
                    $result = $payment_gateways[ $order->get_payment_method() ]->process_refund( $order_id, $refund->get_amount(), $refund->get_reason() );

                    if ( is_wp_error( $result ) ) {
                        return $result;
                    } elseif ( ! $result ) {
                        throw new WC_API_Exception( 'woocommerce_api_create_order_refund_api_failed', __( 'An error occurred while attempting to create the refund using the payment gateway API.', 'woocommerce' ), 500 );
                    }
                }
            }

            // HTTP 201 Created
            $this->server->send_status( 201 );

            do_action( 'woocommerce_api_create_order_refund', $refund->get_id(), $order_id, $this );

            $refund_data =  parent::get_order_refund( $order_id, $refund->get_id());
            $refund_data["order_refund"]["refund_receipt"] = admin_url("admin.php?print_pos_receipt=true&order_id=" . $refund_data['order_refund']['id'] . "&_wpnonce=" . wp_create_nonce("print_pos_receipt"));

            return $refund_data;
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => 400 ) );
        } catch ( WC_API_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
        }
    }

    public function get_tax_location($order, $id_register = 0)
    {
        $location = array(
            'country' => '',
            'state' => '',
            'postcode' => '',
            'city' => ''
        );

        $tax_based_on = get_option('woocommerce_pos_calculate_tax_based_on', 'outlet');
        if ($tax_based_on == 'default') {
            $tax_based_on = get_option('woocommerce_tax_based_on');
        }

        if ($tax_based_on == 'shipping' || $tax_based_on == 'billing') {
            $location = $this->get_customer_taxable_address($order, $id_register);
        } else if ('base' === $tax_based_on) {
            $default = wc_get_base_location();
            $location = $location = array(
                'country' => $default['country'],
                'state' => $default['state'],
                'postcode' => '',
                'city' => ''
            );
        } else if ('outlet' === $tax_based_on) {
            $default = wc_pos_get_outlet_location($id_register);
            $location = array(
                'country' => $default['contact']['country'],
                'state' => $default['contact']['state'],
                'postcode' => $default['contact']['postcode'],
                'city' => $default['contact']['city']
            );
        } else if (wc_prices_include_tax()) {
            $default = wc_get_base_location();
            $location = $location = array(
                'country' => $default['country'],
                'state' => $default['state'],
                'postcode' => '',
                'city' => ''
            );
        }

        return $location;

    }

    /**
     * @param array $refund_data
     * @param WC_Order_Refund $refund_object
     */
    public function set_refund_data($refund_data, $refund_object)
    {
        $payment_data = $refund_data['pos_refund_payment'];
        $order = wc_get_order($refund_data['order_id']);

        if(!$order){
            return;
        }

        $refund_message = isset($payment_data['transaction_id']) ?
            sprintf(
                __( 'Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'wc_point_of_sale' ),
                $refund_object->get_amount(),
                $payment_data["transaction_id"],
                $refund_object->get_reason()
            )
            :   __('Refunded %1$s', $refund_object->get_formatted_refund_amount());

        $order->add_order_note( $refund_message );
    }

    protected function query_orders( $args ) {

        // set base query arguments
        $query_args = array(
            'fields'      => 'ids',
            'post_type'   => $this->post_type,
            'post_status' => array_keys( wc_get_order_statuses() ),
        );

        // add status argument
        if ( ! empty( $args['status'] ) ) {
            $statuses                  = 'wc-' . str_replace( ',', ',wc-', $args['status'] );
            $statuses                  = explode( ',', $statuses );
            $query_args['post_status'] = $statuses;

            unset( $args['status'] );
        }

        if ( ! empty( $args['customer_id'] ) ) {
            $query_args['meta_query'] = array(
                array(
                    'key'     => '_customer_user',
                    'value'   => absint( $args['customer_id'] ),
                    'compare' => '=',
                ),
            );
        }

        $query_args = $this->merge_query_args( $query_args, $args );

        return new WP_Query( $query_args );
    }

    public function get_orders( $fields = null, $filter = array(), $status = null, $page = 1 ) {

        if ( ! empty( $status ) ) {
            $filter['status'] = $status;
        }

        $filter['page'] = $page;

        $query = $this->query_orders( $filter );

        $orders = array();

        foreach ( $query->posts as $order_id ) {
            if ( ! $this->is_readable( $order_id ) ) {
                continue;
            }

            $orders[] = current( $this->get_order( $order_id, $fields, $filter ) );
        }

        $this->server->add_pagination_headers( $query );

        return array( 'orders' => $orders );
    }

    public function get_order( $id, $fields = null, $filter = array() ) {

        // Ensure order ID is valid & user has permission to read.
        $id = $this->validate_request( $id, $this->post_type, 'read' );

        if ( is_wp_error( $id ) ) {
            return $id;
        }

        // Get the decimal precession.
        $dp     = ( isset( $filter['dp'] ) ? intval( $filter['dp'] ) : 2 );
        $order  = wc_get_order( $id );
        $expand = array();

        if ( ! empty( $filter['expand'] ) ) {
            $expand = explode( ',', $filter['expand'] );
        }

        $order_data = array(
            'id'                        => $order->get_id(),
            'order_number'              => $order->get_order_number(),
            'order_key'                 => $order->get_order_key(),
            'created_at'                => $this->server->format_datetime( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0, false, false ), // API gives UTC times.
            'updated_at'                => $this->server->format_datetime( $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0, false, false ), // API gives UTC times.
            'completed_at'              => $this->server->format_datetime( $order->get_date_completed() ? $order->get_date_completed()->getTimestamp() : 0, false, false ), // API gives UTC times.
            'status'                    => $order->get_status(),
            'currency'                  => $order->get_currency(),
            'total'                     => wc_format_decimal( $order->get_total(), $dp ),
            'subtotal'                  => wc_format_decimal( $order->get_subtotal(), $dp ),
            'total_line_items_quantity' => $order->get_item_count(),
            'total_tax'                 => wc_format_decimal( $order->get_total_tax(), $dp ),
            'total_shipping'            => wc_format_decimal( $order->get_shipping_total(), $dp ),
            'cart_tax'                  => wc_format_decimal( $order->get_cart_tax(), $dp ),
            'shipping_tax'              => wc_format_decimal( $order->get_shipping_tax(), $dp ),
            'total_discount'            => wc_format_decimal( $order->get_total_discount(), $dp ),
            'shipping_methods'          => $order->get_shipping_method(),
            'payment_details' => array(
                'method_id'    => $order->get_payment_method(),
                'method_title' => $order->get_payment_method_title(),
                'paid'         => ! is_null( $order->get_date_paid() ),
            ),
            'billing_address' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ),
            'shipping_address' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'company'    => $order->get_shipping_company(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ),
            'note'                      => $order->get_customer_note(),
            'customer_ip'               => $order->get_customer_ip_address(),
            'customer_user_agent'       => $order->get_customer_user_agent(),
            'customer_id'               => $order->get_user_id(),
            'view_order_url'            => $order->get_view_order_url(),
            'line_items'                => array(),
            'shipping_lines'            => array(),
            'tax_lines'                 => array(),
            'fee_lines'                 => array(),
            'coupon_lines'              => array(),
        );

        // Add line items.
        foreach ( $order->get_items() as $item_id => $item ) {
            $product    = $item->get_product();
            $hideprefix = ( isset( $filter['all_item_meta'] ) && 'true' === $filter['all_item_meta'] ) ? null : '_';
            $item_meta  = $item->get_formatted_meta_data( $hideprefix, true );

            foreach ( $item_meta as $key => $values ) {
                $item_meta[ $key ]->label = $values->display_key;
                unset( $item_meta[ $key ]->display_key );
                unset( $item_meta[ $key ]->display_value );
            }

            $line_item = array(
                'id'           => $item_id,
                'subtotal'     => wc_format_decimal( $order->get_line_subtotal( $item, false, false ), $dp ),
                'subtotal_tax' => wc_format_decimal( $item->get_subtotal_tax(), $dp ),
                'total'        => wc_format_decimal( $order->get_line_total( $item, false, false ), $dp ),
                'total_tax'    => wc_format_decimal( $item->get_total_tax(), $dp ),
                'price'        => wc_format_decimal( $order->get_item_total( $item, false, false ), $dp ),
                'quantity'     => $item->get_quantity(),
                'tax_class'    => $item->get_tax_class(),
                'name'         => $item->get_name(),
                'product_id'   => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
                'sku'          => is_object( $product ) ? $product->get_sku() : null,
                'meta'         => array_values( $item_meta ),
            );

            if ( in_array( 'products', $expand ) && is_object( $product ) ) {
                $_product_data = WC()->api->WC_API_Products->get_product( $product->get_id() );

                if ( isset( $_product_data['product'] ) ) {
                    $line_item['product_data'] = $_product_data['product'];
                }
            }

            $order_data['line_items'][] = $line_item;
        }

        // Add shipping.
        foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
            $order_data['shipping_lines'][] = array(
                'id'           => $shipping_item_id,
                'method_id'    => $shipping_item->get_method_id(),
                'method_title' => $shipping_item->get_name(),
                'total'        => wc_format_decimal( $shipping_item->get_total(), $dp ),
            );
        }

        // Add taxes.
        foreach ( $order->get_tax_totals() as $tax_code => $tax ) {
            $tax_line = array(
                'id'       => $tax->id,
                'rate_id'  => $tax->rate_id,
                'code'     => $tax_code,
                'title'    => $tax->label,
                'total'    => wc_format_decimal( $tax->amount, $dp ),
                'compound' => (bool) $tax->is_compound,
            );

            if ( in_array( 'taxes', $expand ) ) {
                $_rate_data = WC()->api->WC_API_Taxes->get_tax( $tax->rate_id );

                if ( isset( $_rate_data['tax'] ) ) {
                    $tax_line['rate_data'] = $_rate_data['tax'];
                }
            }

            $order_data['tax_lines'][] = $tax_line;
        }

        // Add fees.
        foreach ( $order->get_fees() as $fee_item_id => $fee_item ) {
            $order_data['fee_lines'][] = array(
                'id'        => $fee_item_id,
                'title'     => $fee_item->get_name(),
                'tax_class' => $fee_item->get_tax_class(),
                'total'     => wc_format_decimal( $order->get_line_total( $fee_item ), $dp ),
                'total_tax' => wc_format_decimal( $order->get_line_tax( $fee_item ), $dp ),
            );
        }

        // Add coupons.
        foreach ( $order->get_items( 'coupon' ) as $coupon_item_id => $coupon_item ) {
            $coupon_line = array(
                'id'     => $coupon_item_id,
                'code'   => $coupon_item->get_code(),
                'amount' => wc_format_decimal( $coupon_item->get_discount(), $dp ),
            );

            if ( in_array( 'coupons', $expand ) ) {
                $_coupon_data = WC()->api->WC_API_Coupons->get_coupon_by_code( $coupon_item->get_code() );

                if ( ! is_wp_error( $_coupon_data ) && isset( $_coupon_data['coupon'] ) ) {
                    $coupon_line['coupon_data'] = $_coupon_data['coupon'];
                }
            }

            $order_data['coupon_lines'][] = $coupon_line;
        }

        return array( 'order' => apply_filters( 'woocommerce_api_order_response', $order_data, $order, $fields, $this->server ) );
    }

    /**
     * @param WP_REST_Request $data
     * @return int|WC_REST_Exception
     */
    public function get_customer_id($data)
    {
        global $wpdb;

        if ($data['create_account'] === true) {

            $billing_data = $data['billing'];

            $username_opt = get_option('woocommerce_pos_end_of_sale_username_add_customer');
            $wc_reg_generate_username_opt = get_option('woocommerce_registration_generate_username');
            $wc_reg_generate_pass_opt = get_option('woocommerce_registration_generate_password');
            if ($wc_reg_generate_username_opt == 'yes') {
                switch ($username_opt) {
                    case 2:
                        $username = str_replace(' ', '', strtolower($billing_data['first_name'])) . '-' . str_replace(' ', '', strtolower($billing_data['last_name']));
                        break;
                    case 3:
                        $username = $billing_data['email'];
                        break;
                    default:
                        $username = str_replace(' ', '', strtolower($billing_data['first_name'])) . str_replace(' ', '', strtolower($billing_data['last_name']));
                        break;
                }
            } else {
                $username = $billing_data['account_username'];
            }

            $username = _truncate_post_slug($username, 60);
            $check_sql = "SELECT user_login FROM {$wpdb->users} WHERE user_login = '%s' LIMIT 1";

            $user_name_check = $wpdb->get_var($wpdb->prepare($check_sql, $username));

            if ($user_name_check) {
                $suffix = 1;
                do {
                    $alt_user_name = _truncate_post_slug($username, 60 - (strlen($suffix) + 1)) . "-$suffix";
                    $user_name_check = $wpdb->get_var($wpdb->prepare($check_sql, $alt_user_name));
                    $suffix++;
                } while ($user_name_check);
                $username = $alt_user_name;
            }

            add_filter('pre_option_woocommerce_registration_generate_password', 'pos_enable_generate_password');
            $password = '';
            if ($wc_reg_generate_pass_opt == 'yes') {
                $password = isset($billing_data['account_password']) ? $billing_data['account_password'] : '';
            }
            $new_customer = wc_create_new_customer($billing_data['email'], $username, $password);
            remove_filter('pre_option_woocommerce_registration_generate_password', 'pos_enable_generate_password');

            if (is_wp_error($new_customer)) {
                return new WC_REST_Exception('woocommerce_api_cannot_create_customer_account', $new_customer->get_error_message(), 400);
            }

            // Add customer info from other billing fields
            if ($billing_data['first_name'] && apply_filters('wc_pos_checkout_update_customer_data', true, $this)) {
                $userdata = array(
                    'ID' => $new_customer,
                    'first_name' => $billing_data['first_name'] ? $billing_data['first_name'] : '',
                    'last_name' => $billing_data['last_name'] ? $billing_data['last_name'] : '',
                    'display_name' => $billing_data['first_name'] ? $billing_data['first_name'] : ''
                );
                wp_update_user(apply_filters('wc_pos_checkout_customer_userdata', $userdata, $this));
            }

            return $new_customer;
        }else{
            return $data['customer_id'];
        }
    }

    /**
     * @param WP_REST_Request $data
     * @return string
     */
    public function get_status($data)
    {
        $save_order_status = get_option('wc_pos_save_order_status', 'pending');
        if (empty($save_order_status)) {
            $save_order_status = 'pending';
        } else if (strpos($save_order_status, 'wc-') === 0) {
            $save_order_status = substr($save_order_status, 3);
        }

        if ($save_order_status && $data["action"] == "creating") {
            return $save_order_status;
        }

        return $data['status'];
    }

    public function get_item_schema()
    {
        $schema = parent::get_item_schema();

        $schema['properties']['line_items']['items']['properties']['quantity']['type'] = 'number';

        return $schema;
    }

}
