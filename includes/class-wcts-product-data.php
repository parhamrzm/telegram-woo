<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Product_Data {

    public static function extract( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return [];

        $data = [];

        $data['product_name']      = $product->get_name();
        $data['product_id']        = $product->get_id();
        $data['sku']               = $product->get_sku() ?: '—';
        $data['price']             = self::format_price( $product->get_price() );
        $data['regular_price']     = self::format_price( $product->get_regular_price() );
        $data['sale_price']        = self::format_price( $product->get_sale_price() );
        $data['discount_percent']  = self::get_discount_percent( $product );
        $data['stock_status']      = self::stock_label( $product->get_stock_status() );
        $data['stock_quantity']    = $product->get_stock_quantity() ?: 'نامشخص';
        $data['short_description'] = wp_strip_all_tags( $product->get_short_description() );
        $data['weight']            = $product->get_weight()
            ? $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' )
            : 'نامشخص';
        $data['product_url']       = get_permalink( $product_id );
        $data['site_name']         = get_bloginfo( 'name' );
        $data['date']              = date_i18n( 'Y/m/d H:i' );

        $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $data['categories'] = ! is_wp_error( $cats ) && ! empty( $cats ) ? implode( '، ', $cats ) : '—';

        $tags = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] );
        $data['tags'] = ! is_wp_error( $tags ) && ! empty( $tags ) ? implode( '، ', $tags ) : '—';

        $dim = $product->get_dimensions();
        $data['dimensions'] = is_array( $dim )
            ? sprintf( '%s × %s × %s', $dim['length'] ?: '0', $dim['width'] ?: '0', $dim['height'] ?: '0' )
            : 'نامشخص';

        $data['attributes']    = self::format_attributes( $product );
        $data['custom_fields'] = self::format_custom_fields( $product_id );

        return $data;
    }

    public static function get_images( $product_id, $max = 5 ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return [];

        $images = [];

        $featured_id = $product->get_image_id();
        if ( $featured_id ) {
            $url = wp_get_attachment_image_url( $featured_id, 'full' );
            if ( $url ) $images[] = [ 'id' => $featured_id, 'url' => $url ];
        }

        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $gid ) {
            if ( count( $images ) >= $max ) break;
            $url = wp_get_attachment_image_url( $gid, 'full' );
            if ( $url ) $images[] = [ 'id' => $gid, 'url' => $url ];
        }

        return array_slice( $images, 0, $max );
    }

    private static function format_attributes( $product ) {
        $attributes = $product->get_attributes();
        if ( empty( $attributes ) ) return '';

        $lines = [];
        foreach ( $attributes as $attribute ) {
            if ( ! $attribute->get_visible() ) continue;
            $label = wc_attribute_label( $attribute->get_name() );

            if ( $attribute->is_taxonomy() ) {
                $terms = wp_get_post_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
                $value = ! is_wp_error( $terms ) ? implode( '، ', $terms ) : '';
            } else {
                $value = implode( '، ', $attribute->get_options() );
            }

            if ( $value ) $lines[] = "▫️ *{$label}:* {$value}";
        }

        return $lines ? "\n" . implode( "\n", $lines ) . "\n" : '';
    }

    private static function format_custom_fields( $product_id ) {
        $settings = WCTS_Settings::get();
        $fields = ! empty( $settings['custom_fields'] ) ? $settings['custom_fields'] : [];
        if ( empty( $fields ) ) return '';

        $lines = [];
        foreach ( $fields as $field ) {
            if ( empty( $field['meta_key'] ) || empty( $field['label'] ) ) continue;
            $value = get_post_meta( $product_id, $field['meta_key'], true );
            if ( $value ) $lines[] = "▫️ *{$field['label']}:* {$value}";
        }

        return $lines ? "\n" . implode( "\n", $lines ) . "\n" : '';
    }

    public static function render_template( $template, $data ) {
        foreach ( $data as $key => $value ) {
            $template = str_replace( '{' . $key . '}', (string) $value, $template );
        }
        return $template;
    }

    private static function get_discount_percent( $product ) {
        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();
        if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
            return round( ( ( $regular - $sale ) / $regular ) * 100 ) . '٪';
        }
        return '—';
    }

    private static function format_price( $price ) {
        if ( ! $price ) return '—';
        return number_format( (float) $price ) . ' تومان';
    }

    private static function stock_label( $status ) {
        $map = [
            'instock'     => '✅ موجود',
            'outofstock'  => '❌ ناموجود',
            'onbackorder' => '⏳ پیشسفارش',
        ];
        return $map[ $status ] ?? $status;
    }
}