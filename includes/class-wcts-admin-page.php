<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Admin_Page {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'wp_ajax_wcts_manual_send', [ __CLASS__, 'ajax_manual_send' ] );
    }

    public static function add_meta_box() {
        $settings = WCTS_Settings::get();
        if ( empty( $settings['enable_manual_button'] ) || $settings['enable_manual_button'] !== '1' ) {
            return;
        }

        add_meta_box(
            'wcts_manual_send',
            'ارسال به تلگرام',
            [ __CLASS__, 'render_meta_box' ],
            'product',
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'wcts_manual_send', 'wcts_nonce' );
        ?>
        <p>
            <button type="button" class="button button-primary" id="wcts-manual-send"
                    data-product-id="<?php echo esc_attr( $post->ID ); ?>">
                📤 ارسال به تلگرام
            </button>
        </p>
        <div id="wcts-manual-result" style="margin-top:8px;"></div>

        <script>
        jQuery(document).ready(function($) {
            $('#wcts-manual-send').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var nonce = $('#wcts_nonce').val();

                btn.prop('disabled', true).text('در حال ارسال...');
                $('#wcts-manual-result').html('<span style="color:#666;">لطفاً صبر کنید...</span>');

                $.post(ajaxurl, {
                    action: 'wcts_manual_send',
                    product_id: productId,
                    _ajax_nonce: nonce
                }, function(response) {
                    btn.prop('disabled', false).text('📤 ارسال به تلگرام');
                    if (response.success) {
                        $('#wcts-manual-result').html(
                            '<span style="color:green;">✅ ' + response.data.message + '</span>'
                        );
                    } else {
                        $('#wcts-manual-result').html(
                            '<span style="color:red;">❌ ' + response.data.message + '</span>'
                        );
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('📤 ارسال به تلگرام');
                    $('#wcts-manual-result').html(
                        '<span style="color:red;">❌ خطا در ارتباط با سرور</span>'
                    );
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_manual_send() {
        check_ajax_referer( 'wcts_manual_send', '_ajax_nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        $product_id = intval( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'شناسه محصول نامعتبر است' ] );
        }

        $result = WCTS_Hooks::process_send( $product_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        $success_count = 0;
        $error_count = 0;
        foreach ( $result as $chat_id => $res ) {
            if ( is_array( $res ) && ! empty( $res['ok'] ) ) {
                $success_count++;
            } else {
                $error_count++;
            }
        }

        if ( $success_count > 0 ) {
            wp_send_json_success( [
                'message' => sprintf(
                    'ارسال شد به %d مقصد. %s',
                    $success_count,
                    $error_count ? "خطا در {$error_count} مقصد." : ''
                ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => 'ارسال ناموفق بود.' ] );
        }
    }
}