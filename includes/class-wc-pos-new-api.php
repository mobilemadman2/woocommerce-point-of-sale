<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly

/**
 *
 *
 *
 *
 * @class
 * @version
 * @package   WoocommercePointOfSale/Classes
 * @category  Class
 * @author    Actuality Extensions
 */
class WC_NEW_API {

    /**
     * @var
     */
    private $limit;
    /**
     * @var string
     */
    private $type = 'product';
    /**
     * @var
     */
    private $offset;
    /**
     * @var
     */
    private $order_by;
    /**
     * @var
     */
    private $order;
    /**
     * @var
     */
    private $post_status;
    /**
     * @var
     */
    private $current_user;
    /**
     * @var
     */
    private $search_result;
    /**
     * @var array
     */
    private $meta_query = array();


    /**
     * @var null
     */
    private $errors = null;

    /**
     * @var bool
     */
    private $only_ids = false;

    /**
     * @var array
     */
    private $include = array();
    private $current_register = 0;


    private $update = false;

    private $category = '';

    private $show_out_of_stock;

    /**
     *
     */
    public function __construct() {

        $ajax_events = array(
            'get_products_by'            => true,
            'get_products_ids'           => true,
            'get_products_from_category' => true,
            'get_default_variations'     => true,
            'get_served_products_count'  => true,
            'get_served_products'        => true,
            'get_served_orders_count'    => true,
            'get_served_orders'          => true,
            'get_served_coupons_count'   => true,
            'get_served_coupons'         => true,
            'get_customers_count'        => true,
            'get_customers'              => true,
            'get_user_by_term'           => true,
            'get_products_by_search'     => true,

        );

        foreach ( $ajax_events as $ajax_event => $nopriv ) {
            add_action( 'wp_ajax_wc_pos_' . $ajax_event, array( $this, $ajax_event ) );

            if ( $nopriv ) {
                add_action( 'wp_ajax_nopriv_wc_pos_' . $ajax_event, array( $this, $ajax_event ) );
            }
        }
        $this->init();
    }

    /**
     * @param string $type
     * @param string $order_by
     * @param string $order
     * @param int $limit
     * @param int $offset
     * @param string $post_status
     */
    public function init( $type = 'product', $order_by = 'date', $order = 'ASC', $limit = 100, $offset = 0, $post_status = 'publish' ) {

        $this->type = $this->clear( $type );

        $this->show_out_of_stock = get_option( 'wc_pos_show_out_of_stock_products', 'no' );


        $this->limit       = $this->clear( $limit );
        $this->order_by    = $this->clear( $order_by );
        $this->order       = $this->clear( $order );
        $this->post_status = $this->clear( $post_status );
        $this->offset      = $this->clear( $offset );

        if ( get_option( 'wc_pos_visibility', 'no' ) == 'yes' ) {

            $this->meta_query = array(
                'relation' => 'OR',
                array(
                    'key'     => '_pos_visibility',
                    'value'   => 'pos',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_pos_visibility',
                    'value'   => 'pos_online',
                    'compare' => '=',
                )
            );
        }

        $this->current_user = get_current_user_id();

    }

    /**
     *
     */
    public function get_products_ids() {
        $this->only_ids = true;

        wp_send_json( $this->get_product_ids() );

    }


    /**
     *
     */
    public function get_products_by() {

        $this->current_register = isset( $_POST['reg_id'] ) ? $_POST['reg_id'] : 0;
        $this->include          = isset( $_POST['visible'] ) ? $_POST['visible'] : array();
        $this->offset           = isset( $_POST['offset'] ) ? $_POST['offset'] : 0;

        if ( $_POST['update'] == 'true' ) {
            $this->update = true;
        }


        wp_send_json( $this->go() );
    }

    public function get_products_from_category() {
        $this->category = isset( $_POST['cat_id'] ) ? $_POST['cat_id'] : '';
        wp_send_json( $this->get_products_by_category() );
    }


    public function get_product_ids() {
        $args = array(
            'numberposts'      => $this->limit,
            'offset'           => $this->offset,
            'orderby'          => $this->order_by,
            //'include'          => $this->include,
            'order'            => $this->order,
            'post_type'        => $this->type,
            'suppress_filters' => true,
            'post_status'      => $this->post_status,
            'meta_query'       => $this->meta_query,
        );


        $pre_result = get_posts( $args );
        $ids        = array();
        if ( count( $pre_result ) > 0 ) {

            foreach ( $pre_result as $post ) {
                $prod = new WC_Product( $post->ID );
                if ( $prod->is_in_stock() ) {
                    $ids[] = $post->ID;
                }
            }

        }

        return $ids;
    }

    public function get_products_by_category() {

        if ( $this->category != '' ) {

            $this->category = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $this->category,
                    'operator' => 'IN'
                ),
                array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'slug',
                    'terms'    => 'exclude-from-catalog',
                    'operator' => 'NOT IN'
                )
            );
        }

        $args = array(
            'posts_per_page'   => $this->limit,
            'orderby'          => $this->order_by,
            'post__in'         => $this->include,
            'order'            => $this->order,
            'post_type'        => $this->type,
            'suppress_filters' => true,
            'post_status'      => $this->post_status,
            'meta_query'       => $this->meta_query,
            'tax_query'        => $this->category
        );


        $pre_result = new WP_Query( $args );
        $pre_result = $pre_result->posts;
        $return = array();
        if ( count( $pre_result ) > 0 ) {

            foreach ( $pre_result as $post ) {

                $pf = wc_get_product( $post->ID );


                $return[ $post->ID ]              = $pf->get_data();
                $data                                          = $pf->get_data();
                $array                                         = array(
                    'type'               => $pf->get_type(),
                    'price_html'         => $pf->get_price_html(),
                    'taxable'            => $pf->is_taxable(),
                    'tax_status'         => $pf->get_tax_status(),
                    'tax_class'          => $pf->get_tax_class(),
                    'managing_stock'     => $pf->managing_stock(),
                    'stock_quantity'     => $pf->get_stock_quantity(),
                    'in_stock'           => $pf->is_in_stock(),
                    'backorders_allowed' => $pf->backorders_allowed(),
                    'backordered'        => $pf->is_on_backorder(),
                    'sold_individually'  => $pf->is_sold_individually(),
                    'purchaseable'       => $pf->is_purchasable(),
                    'featured'           => $pf->is_featured(),
                    'visible'            => $pf->is_visible(),
                    'catalog_visibility' => $pf->get_catalog_visibility(),
                    'on_sale'            => $pf->is_on_sale(),
                    'title'              => $data['name'],
                    'attributes'         => $this->get_attributes($pf),
                    'variations'         => $this->get_variants($pf),
                    'default_attributes' => $pf->get_default_attributes(),


                );
                $return[ $post->ID ]              = array_merge( $return[ $post->ID ], $array );
                $return[ $post->ID ]['image_url'] = '';

                if ( isset( $data['image_id'] ) ) {
                    $return[ $post->ID ]['image_url']     = wp_get_attachment_image_src( $data['image_id'] )[0];
                    $return[ $post->ID ]['thumbnail_src'] = wp_get_attachment_image_src( $data['image_id'] )[0];
                }
                

            }

        }

        return $return;

    }

    public function get_products_by_search()
    {
        $term = isset($_POST["term"]) ? $_POST["term"] : "";
        $wp_query =  new WP_Query(array(
            's' => $term
        ));

        $products = array();
        foreach ($wp_query->posts as $id => $post){
            $product = wc_get_product($post->ID);
            if($product) $products[$post->ID] = $this->get_product_structure($product);
        }
        wp_send_json_success($products);

    }

    /**
     * @param bool $only_ids
     */
    private function build_search_query() {


        $cache    = new WC_POS_Cache();
        $reg_data = $cache->get_cache_by_register( $this->current_register, $this->include );

        if ( $reg_data !== false ) {
            $this->search_result = $reg_data;

            return;
        }

        if ( $reg_data === false ) {
            $this->limit   = - 1;
            $this->include = array();
        }


        $args = array(
            'posts_per_page'   => $this->limit,
            'orderby'          => $this->order_by,
            'post__in'         => $this->include,
            'order'            => $this->order,
            'post_type'        => $this->type,
            'suppress_filters' => true,
            'post_status'      => $this->post_status,
            'meta_query'       => $this->meta_query,
        );


        $pre_result = new WP_Query( $args );
        $pre_result = $pre_result->posts;

        if ( count( $pre_result ) > 0 ) {

            foreach ( $pre_result as $post ) {

                $pf = wc_get_product( $post->ID );

                $to_cache[$post->ID] = $this->get_product_structure($pf);
            }

            if ( $reg_data === false ) {
                $cache->insert_data( $to_cache, $this->current_register );
            }
        }


    }


    /**
     * @param $arg
     *
     * @return string
     */
    private function clear( $arg ) {
        $arg = sanitize_text_field( $arg );

        return $arg;
    }

    public function get_default_variations() {
        echo "<pre>";
        print_r( $_POST );
        echo "</pre>";
    }

    public function get_served_products_count() {
        $prod = new WC_Product();
//        $prod->coun
    }

    public function get_served_products() {
        echo "<pre>";
        print_r( $_POST );
        echo "</pre>";
    }

    public function get_served_orders_count() {

        $query = new WC_Order_Query( array(
            'limit' => -1,
            'status' => $_POST['status'],
            'return' => 'objects'
        ) );
        $orders = $query->get_orders();



        wp_send_json( array( 'count' => count( $orders ) ) );

    }

    public function get_served_orders() {
        $query = new WC_Order_Query( array(
            'limit' => -1,
            'status' => $_POST['status'],
            'return' => 'objects'

        ) );

        $orders = $query->get_orders();
        $return = array();
        if (count($orders) > 0) {
            foreach ($orders as $k=> $order) {
                $return[$order->get_id()] = $order->get_data();
            }
        }

        wp_send_json( array( 'orders' =>  $return ) );

    }

    public function get_served_coupons_count() {
        $args = array(
            'posts_per_page' => - 1,
            'orderby'        => 'title',
            'order'          => 'asc',
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
        );

        $coupons = get_posts( $args );

        wp_send_json( array( 'count' => count( $coupons ) ) );

    }

    public function get_served_coupons() {
        $args = array(
            'posts_per_page' => - 1,
            'orderby'        => 'title',
            'order'          => 'asc',
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
        );

        $coupons = get_posts( $args );
        if ( count( $coupons ) > 0 ) {
            foreach ( $coupons as $k => $v ) {
                $c                                     = new WC_Coupon( $v->id );
                $coupons[ $k ]                         = $c->get_data();
                $coupons[ $k ]['coupon_custom_fields'] = $c->get_meta_data();
            }
        }
        wp_send_json( array( 'coupons' => $coupons ) );
    }

    public function get_customers_count() {
        $args  = array(
            'blog_id'             => $GLOBALS['blog_id'],
            'orderby'             => 'login',
            'order'               => 'ASC',
            'count_total'         => false,
            'fields'              => 'all',
            'has_published_posts' => null,
        );
        $users = get_users( $args );

        wp_send_json( array( 'count' => count( $users ) ) );
    }

    public function get_customers() {
        $args  = array(
            'blog_id'             => $GLOBALS['blog_id'],
            'orderby'             => 'login',
            'order'               => 'ASC',
            'count_total'         => false,
            'fields'              => 'all',
            'has_published_posts' => null,
        );
        $users = get_users( $args );


        if ( count( $users ) > 0 ) {
            foreach ( $users as $k => $v ) {
                $c                             = new WC_Customer( $v->ID );
                $users[ $k ]                   = $c->get_data();
                $users[ $k ]['avatar_url']     = $c->get_avatar_url();
                $users[ $k ]['orders_count']   = $c->get_order_count();
                $users[ $k ]['points_balance'] = 0;
                if ( isset( $GLOBALS['wc_points_rewards'] ) ) {
                    $users[ $k ]['points_balance'] = WC_Points_Rewards_Manager::get_users_points( $v->ID );
                }
                $users[ $k ]['total_spent'] = $c->get_total_spent();
                $users[ $k ]['shipping_address'] = $users[ $k ]['shipping'];
                $users[ $k ]['user_meta'] = $users[ $k ]['meta_data'];
                $users[ $k ]['fullname'] = $users[ $k ]['display_name'];
            }
        }
        wp_send_json( array( 'customers' => $users ) );
    }


    public function get_user_by_term() {

        $search = isset($_POST['term'])? $_POST['term']: '';

        $users = new WP_User_Query( array(
            'search'         => '*'.esc_attr( $search ).'*',
            'search_columns' => array(
                'user_login',
                'user_nicename',
                'user_email',
                'user_url',
            ),
        ) );
        $users_found = $users->get_results();

        $users = array();
        if ( count( $users_found ) > 0 ) {
            foreach ( $users_found as $k => $v ) {
                $c                             = new WC_Customer( $v->ID );
                $users[ $k ]                   = $c->get_data();
                $users[ $k ]['avatar_url']     = $c->get_avatar_url();
                $users[ $k ]['orders_count']   = $c->get_order_count();
                $users[ $k ]['points_balance'] = 0;
                if ( isset( $GLOBALS['wc_points_rewards'] ) ) {
                    $users[ $k ]['points_balance'] = WC_Points_Rewards_Manager::get_users_points( $v->ID );
                }
                $users[ $k ]['total_spent']      = $c->get_total_spent();
                $users[ $k ]['shipping_address'] = $users[ $k ]['shipping'];
                $users[ $k ]['user_meta']        = $users[ $k ]['meta_data'];
                $users[ $k ]['fullname']         = $users[ $k ]['display_name'];
                $users[ $k ]['phone']         =     $users[ $k ]['billing']['phone'];
            }
        }


        wp_send_json($users);

    }
    /**
     * @return array|bool
     */
    public function go() {

        $this->build_search_query();
        if ( empty ( $this->search_result ) ) {
            return array();
        }
        $this->search_result["ids"] = $_POST['grid'] == "all" ? $this->get_product_ids() : array();
        return $this->search_result;
    }


    /**
     * @param WC_Product $product
     * @return array
     */
    public function get_product_structure($product){

        $value = $product->get_data();
        $data  = $product->get_data();

        $array = array(
            'type'               => $product->get_type(),
            'price_html'         => $product->get_price_html(),
            'taxable'            => $product->is_taxable(),
            'tax_status'         => $product->get_tax_status(),
            'tax_class'          => $product->get_tax_class(),
            'managing_stock'     => $product->managing_stock(),
            'stock_quantity'     => $product->get_stock_quantity(),
            'in_stock'           => $product->is_in_stock(),
            'backorders_allowed' => $product->backorders_allowed(),
            'backordered'        => $product->is_on_backorder(),
            'sold_individually'  => $product->is_sold_individually(),
            'purchaseable'       => $product->is_purchasable(),
            'featured'           => $product->is_featured(),
            'visible'            => $product->is_visible(),
            'catalog_visibility' => $product->get_catalog_visibility(),
            'on_sale'            => $product->is_on_sale(),
            'title'              => $product->get_title(),
            'attributes'         => $this->get_attributes($product),
            'variations'         => $this->get_variants($product),
            'default_attributes' => $product->get_default_attributes(),
        );

        $value             = array_merge( $value, $array );
        $value['image_url'] = '';
        if ( isset( $data['image_id'] ) ) {
            $value['image_url']     = wp_get_attachment_image_src( $data['image_id'] )[0];
            $value['thumbnail_src'] = wp_get_attachment_image_src( $data['image_id'] )[0];
        }

        if ( in_array( $product->get_id(), $this->include )) {
            $this->search_result[ $product->get_id() ]              = $product->get_data();
            $this->search_result[ $product->get_id() ]              = array_merge( $this->search_result[ $product->get_id() ], $array );
            $this->search_result[ $product->get_id() ]['image_url'] = '';

            if ( isset( $data['image_id'] ) ) {
                $this->search_result[ $product->get_id() ]['image_url']     = wp_get_attachment_image_src( $data['image_id'] )[0];
                $this->search_result[ $product->get_id() ]['thumbnail_src'] = wp_get_attachment_image_src( $data['image_id'] )[0];
            }
        }

        return $value;
    }

    /**
     * @param WC_Product $product
     * @return array
     */
    public function get_attributes($product)
    {
        $attributes = array();
        foreach ($product->get_attributes() as $slug =>  $attribute){
            $data = $attribute->get_data();
            $slugs = $attribute->get_slugs();
            $data['slug'] = $slug;
            if($data["is_taxonomy"]){
                $taxonomy = get_taxonomy($slug);
                $data['name'] = $taxonomy ? $taxonomy->labels->singular_name : $data['name'];
            }
            if(isset($data['options'])){
                foreach ($data['options'] as $key => $option){
                    $options = array();
                    if($data["is_taxonomy"]){
                        $term = get_term_by('id', $option, $taxonomy->name);
                    }
                    $options["name"] = isset($term) ? $term->name : $option;
                    $options["slug"] = $slugs[$key];
                    $data['options'][$key] = $options;
                }
            }
            $attributes[] = $data;
        }

        return $attributes;
    }

    /**
     * @param WC_Product $product
     * @return array
     */
    public function get_variants($product)
    {
        $variant_data = array();
        if($product->is_type('variable')){
            $variations = count($product->get_children()) ? $product->get_children() : array();
            foreach ($variations as $key => $value){
                $variation = wc_get_product($value);
                if($variation){
                    $data = $variation->get_data();
                    $image_id = !empty($data["image_id"]) ? $data["image_id"] : $product->get_image_id();
                    $data['thumbnail_src'] = wp_get_attachment_image_src($image_id, "thumbnail", true)[0];
                    $variant_data[] = $data;
                }
            }
        }

        return $variant_data;
    }


}

new WC_NEW_API();
