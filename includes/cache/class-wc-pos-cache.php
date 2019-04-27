<?php

/**
 * Class WC_POS_Cache for working with POS cache
 */
class WC_POS_Cache {

    private $source;
    public $offset = 0;
    public $db;

    public function __construct() {

        global $wpdb;
        $this->db = $wpdb;

    }

    /**
     * @param $pos_id
     * @param $product_ids
     *
     *
     */
    public function get_cache_by_register($pos_id, $product_ids) {
        $current_time = time();

        if(empty($product_ids)) return false;

        if (count($product_ids) > 0) {
            $reg_data = $this->db->get_results( "SELECT * FROM {$this->db->prefix}wc_point_of_sale_cache WHERE pos_id = '{$pos_id}' AND pkey IN (".implode(',', $product_ids).")", OBJECT );
        } else {
            $reg_data = $this->db->get_results( "SELECT * FROM {$this->db->prefix}wc_point_of_sale_cache WHERE pos_id = '{$pos_id}'", OBJECT );
        }

        if (count($reg_data) == 0) {
            return false;
        }


        foreach ($reg_data as $k => $v) {
            $return[$v->pkey] = maybe_unserialize($v->data);
        }

        if ( (int) $current_time - (int) $reg_data[0]->time > (int) get_option('wc_pos_new_api_time', 3600)) {
            $this->clear_cache($pos_id);
            return false;
        }

        return $return;


    }

    public function setSource( CacheSource $source ) {
        $this->source = $source;
    }



    public function authenticate( $obj ) {


        include_once( WC_POS()->plugin_path() . '/includes/api/class-wc-pos-api.php' );
        new WC_Pos_API();
        $user = new WP_User( $_GET['user_id'] );

        return $user;
    }


    public function clear_cache($pos_id) {


        $this->db->query( "DELETE FROM {$this->db->prefix}wc_point_of_sale_cache WHERE pos_id={$pos_id}" );
        return null;
    }

    public function start_cache() {

        $this->offset = $_POST['last'];
        if ( $this->cachePosData() !== false ) {
            wp_send_json_success(
                array(
                    'last'   => $this->offset,
                    'finish' => false
                )
            );
        }
        wp_send_json_error();

    }


    public function save_cache_options() {
        parse_str($_POST['data'], $options);

        if ( update_option('wc_pos_cache_options', $options) ) {
            wp_send_json_success();
        }
        wp_send_json_error();

    }


    public function insert_data( $data, $reg_id ) {


        foreach ($data as $k => $v) {
            $this->db->insert(
                "{$this->db->prefix}wc_point_of_sale_cache",
                array( 'data' => maybe_serialize($v), 'pkey' => $k, 'pos_id' => $reg_id, 'time' => time() ),
                array( '%s', '%s', '%s', '%s' )
            );
        }


    }

    public function get_all_cache() {
        $all = $this->db->get_results( "SELECT * FROM {$this->db->prefix}wc_point_of_sale_cache", OBJECT );

        if ( count( $all ) == 0 ) {
            return null;
        }
        $result = array();

        foreach ( $all as $k => $v ) {
            $result = array_merge( $result, json_decode( $v->data ) );
        }

        return $result;
    }

    public function get_data( $key ) {

        $sql    = $this->db->prepare( "SELECT `data` FROM {$this->db->prefix}wc_point_of_sale_cache WHERE `key` = %s", $key );
        $result = $this->db->get_var( $sql );

        return $result;
    }
}

return new WC_POS_Cache();