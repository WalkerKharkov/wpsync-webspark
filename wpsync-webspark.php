<?php

/*
    Plugin Name: wpsync-webspark
    Description: This plugin allows you to keep the product database up-to-date
    Author: Filatov Alex
    Version: 1.0.0
*/

const WPSYNC_WEBSPARK_API_URL = 'https://wp.webspark.dev/wp-api/products';
const WPSYNC_WEBSPARK_API_REQUEST_ATTEMPTS = 10;
const WPSYNC_WEBSPARK_RESPONSE_STATUS_OK = 200;
const WPSYNC_WEBSPARK_CONNECTTIMEOUT = 21;
const WPSYNC_WEBSPARK_TIMEOUT = 30;

const WPSYNC_WEBSPARC_SHOP_VOLUME = 2000;

const WPSYNC_WEBSPARC_MAX_EXECUTION_TIME = 1800;
const WPSYNC_WEBSPARC_MAX_MEMORY_LIMIT = '4096M';

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';


if ( ! function_exists( 'wpsync_webspark_get_data' ) ) {
    function wpsync_webspark_get_data() {

        // WooCommerce is required to run
        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        // update php.ini to execute a long and resource-intensive script
        ini_set( 'max_execution_time', WPSYNC_WEBSPARC_MAX_EXECUTION_TIME );
        ini_set( 'memory_limit', WPSYNC_WEBSPARC_MAX_MEMORY_LIMIT );

        $uploaded_product_data = array();

        $curl = curl_init( WPSYNC_WEBSPARK_API_URL );

        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, WPSYNC_WEBSPARK_CONNECTTIMEOUT );
        curl_setopt( $curl, CURLOPT_TIMEOUT, WPSYNC_WEBSPARK_TIMEOUT );

        for ( $i = 0; $i <= WPSYNC_WEBSPARK_API_REQUEST_ATTEMPTS; $i++ ) {
            $response = curl_exec( $curl );
            $status = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
            curl_close( $curl );

            if ( $status == WPSYNC_WEBSPARK_RESPONSE_STATUS_OK ) {
                $response = json_decode( $response );

                if ( isset( $response->data ) && is_array( $response->data ) && count( $response->data ) > 0 ) {
                    $uploaded_product_data = $response->data;
                }

                break;
            }
        }

        if ( is_array( $uploaded_product_data ) && count( $uploaded_product_data ) > 0 ) {
            $products = wc_get_products(
                array(
                    'limit' => -1,
                    'return' => 'ids',
                )
            );

            $articles = wpsync_webspark_get_all_articles( $uploaded_product_data );

            // clear base && update products
            foreach ( $products as $key => $product_id ) {
                $product = wc_get_product( $product_id );

                if ( $product ) {
                    $sku = $product->get_sku();

                    if ( ! in_array( $sku, $articles ) ) { // clear product if no data
                        $product->delete( true );
                        unset( $products[$key] );
                    } else { // update product if necessary
                        $article_key = array_search( $sku, $articles );
                        $new_data = $uploaded_product_data[$article_key];

                        if ( isset( $new_data->name ) && $product->get_name() != $new_data->name ) {
                            $product->set_name( $new_data->name );
                        }

                        if ( isset( $new_data->description ) && $product->get_description() != $new_data->description ) {
                            $product->set_description( $new_data->description );
                        }

                        if ( isset( $new_data->price ) ) {
                            $new_price = wpsync_webspark_get_price( $new_data->price );

                            if ( $product->get_regular_price() != $new_price ) {
                                $product->set_regular_price( $new_price );
                            }
                        }

                        if ( isset( $new_data->picture ) ) {
                            $product_image_id = $product->get_image_id();
                            $product_image_url = get_attached_file( $product_image_id );
                            $product_image_name = basename( $product_image_url );
                            $new_product_image_name = basename( $new_data->picture );

                            if ( $product_image_name != $new_product_image_name ) {
                                if ( ! is_wp_error( media_sideload_image( $new_data->picture, $product->get_id() ) ) ) {
                                    $product->set_image_id( get_post_thumbnail_id( $product->get_id() ) );
                                }
                            }
                        }

                        if ( isset( $new_data->in_stock ) && $product->get_stock_quantity() != $new_data->in_stock ) {
                            $product->set_stock_quantity( (int)$new_data->in_stock );
                        }

                        $product->save();
                    }
                }
            }

            $product_count = count( $products );

            // add new products up to shop volume
            if ( $product_count < WPSYNC_WEBSPARC_SHOP_VOLUME ) {
                // we can't overload the shop :)
                $difference = WPSYNC_WEBSPARC_SHOP_VOLUME - $product_count;

                foreach ( $uploaded_product_data as $uploaded_product_datum ) {
                    if ( isset( $uploaded_product_datum->sku ) && ! wc_get_product_id_by_sku( $uploaded_product_datum->sku ) ) {
                        // create new product and fill the data
                        $product = new WC_Product_Simple();

                        try {
                            $product->set_sku( $uploaded_product_datum->sku );
                        } catch ( WC_Data_Exception $e ) {
                            continue;
                        }

                        if ( isset( $uploaded_product_datum->name ) ) {
                            $product->set_name( $uploaded_product_datum->name );
                        }

                        if ( isset( $uploaded_product_datum->description ) ) {
                            $product->set_description( $uploaded_product_datum->description );
                        }

                        if ( isset( $uploaded_product_datum->price ) ) {
                            $product->set_regular_price( wpsync_webspark_get_price( $uploaded_product_datum->price ) );
                        }

                        if ( isset( $uploaded_product_datum->in_stock ) ) {
                            $product->set_manage_stock( true );
                            $product->set_stock_status( 'instock' );
                            $product->set_stock_quantity( (int)$uploaded_product_datum->in_stock );
                        }

                        $product->save(); // double save because we need the product id to set the picture

                        if ( isset( $uploaded_product_datum->picture ) ) {
                            if ( ! is_wp_error( media_sideload_image( $uploaded_product_datum->picture, $product->get_id() ) ) ) {
                                $product->set_image_id( get_post_thumbnail_id( $product->get_id() ) );
                            }
                        }

                        $product->save();

                        $difference--;

                        if ( $difference <= 0 ) {
                            break;
                        }
                    }
                }
            }
        }
    }

    // add cron hook
    add_action( 'wpsync_webspark_update_shop', 'wpsync_webspark_get_data' );
}

// get product articles from uploaded data
if ( ! function_exists( 'wpsync_webspark_get_all_articles' ) ) {
    function wpsync_webspark_get_all_articles( $product_data ) {
        $articles = array();

        foreach ( $product_data as $key => $product_datum ) {
            if ( isset( $product_datum->sku ) ) {
                $articles[$key] = $product_datum->sku;
            }
        }

        return $articles;
    }
}

// string (with currency symbol) to float price transformation
if ( ! function_exists( 'wpsync_webspark_get_price' ) ) {
    function wpsync_webspark_get_price( $string_price ) {
        return floatval( preg_replace( '/[^0-9.,]/', '', $string_price ) );
    }
}

/*
 * CRON
 */

// add cron task once
if ( ! function_exists( 'wpsync_webspark_schedule_update' ) ) {
    function wpsync_webspark_schedule_update() {
        if ( ! wp_next_scheduled( 'wpsync_webspark_update_shop') ) {
            wp_schedule_event( time(), 'hourly', 'wpsync_webspark_update_shop' );
        }
    }

    add_action( 'admin_head', 'wpsync_webspark_schedule_update' );
}

// delete cron task when plugin deactivated
register_deactivation_hook( __FILE__, function () {
    wp_unschedule_hook( 'wpsync_webspark_update_shop' );
} );
