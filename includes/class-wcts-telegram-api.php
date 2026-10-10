<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Telegram_API {

    private $bot_token;
    private $worker_url;
    private $image_method; // url | upload | auto

    public function __construct() {
        $this->bot_token    = WCTS_Settings::get( 'bot_token' );
        $this->worker_url   = WCTS_Settings::get( 'worker_url' );
        $this->image_method = WCTS_Settings::get( 'image_method', 'auto' );
    }

    /* ============================================================
       ارسال پیام متنی
       ============================================================ */
    public function send_message( $chat_id, $text, $reply_markup_json = null, $parse_mode = 'Markdown' ) {
        $params = [
            'chat_id'                  => $chat_id,
            'text'                     => $text,
            'parse_mode'               => $parse_mode,
            'disable_web_page_preview' => true,
        ];
        if ( $reply_markup_json ) {
            $params['reply_markup'] = $reply_markup_json;
        }
        return $this->request( 'sendMessage', $params );
    }

    /* ============================================================
       ارسال عکس (سه حالت)
       $local_path: مسیر فایل محلی برای آپلود مستقیم (اختیاری)
       ============================================================ */
    public function send_photo( $chat_id, $photo_url, $caption = '', $reply_markup_json = null, $local_path = null ) {
        // حالت ۱: فقط URL
        if ( $this->image_method === 'url' ) {
            return $this->send_photo_by_url( $chat_id, $photo_url, $caption, $reply_markup_json );
        }

        // حالت ۲: فقط آپلود
        if ( $this->image_method === 'upload' ) {
            return $this->send_photo_by_upload( $chat_id, $photo_url, $caption, $reply_markup_json, $local_path );
        }

        // حالت ۳: auto — اول URL، اگه خطای دانلود داد، آپلود
        $result = $this->send_photo_by_url( $chat_id, $photo_url, $caption, $reply_markup_json );

        if ( $this->is_url_download_error( $result ) ) {
            return $this->send_photo_by_upload( $chat_id, $photo_url, $caption, $reply_markup_json, $local_path );
        }

        return $result;
    }

    /* ============================================================
       ارسال عکس با URL
       ============================================================ */
    private function send_photo_by_url( $chat_id, $photo_url, $caption = '', $reply_markup_json = null ) {
        $params = [
            'chat_id' => $chat_id,
            'photo'   => $photo_url,
        ];
        if ( $caption !== '' ) {
            $params['caption']    = $caption;
            $params['parse_mode'] = 'Markdown';
        }
        if ( $reply_markup_json ) {
            $params['reply_markup'] = $reply_markup_json;
        }
        return $this->request( 'sendPhoto', $params );
    }

    /* ============================================================
       ارسال عکس با آپلود مستقیم
       ============================================================ */
    private function send_photo_by_upload( $chat_id, $photo_url, $caption = '', $reply_markup_json = null, $local_path = null ) {
        // گرفتن محتوای فایل
        $image_data = null;
        $filename   = 'image.jpg';

        // اولویت: مسیر محلی
        if ( $local_path && file_exists( $local_path ) && is_readable( $local_path ) ) {
            $image_data = @file_get_contents( $local_path );
            if ( $image_data !== false ) {
                $filename = basename( $local_path );
            }
        }

        // اگه نشد، از URL دانلود کن
        if ( empty( $image_data ) && ! empty( $photo_url ) ) {
            $response = wp_remote_get( $photo_url, [
                'timeout'     => 60,
                'sslverify'   => false,
                'redirection' => 5,
                'user-agent'  => 'WordPress/TelegramWoo',
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                return [
                    'ok'          => false,
                    'error_code'  => $code,
                    'description' => 'دانلود عکس ناموفق بود: HTTP ' . $code,
                ];
            }

            $image_data = wp_remote_retrieve_body( $response );
            if ( ! empty( $image_data ) ) {
                $url_path = wp_parse_url( $photo_url, PHP_URL_PATH );
                if ( $url_path ) {
                    $maybe_name = basename( $url_path );
                    if ( $maybe_name && strpos( $maybe_name, '.' ) !== false ) {
                        $filename = sanitize_file_name( $maybe_name );
                    }
                }
            }
        }

        if ( empty( $image_data ) ) {
            return [
                'ok'          => false,
                'error_code'  => 500,
                'description' => 'محتوای عکس خالی است.',
            ];
        }

        // تشخیص MIME
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $mime_map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        $mime = $mime_map[ $ext ] ?? 'image/jpeg';
        if ( ! $ext ) {
            $filename = 'image.jpg';
        }

        return $this->upload_photo_multipart( $chat_id, $image_data, $filename, $mime, $caption, $reply_markup_json );
    }

    /* ============================================================
       آپلود multipart/form-data
       ============================================================ */
    private function upload_photo_multipart( $chat_id, $image_data, $filename, $mime, $caption = '', $reply_markup_json = null ) {
        $boundary = '----WCTS' . wp_generate_password( 24, false, false );

        // فیلدهای متنی
        $fields = [ 'chat_id' => $chat_id ];
        if ( $caption !== '' ) {
            $fields['caption']    = $caption;
            $fields['parse_mode'] = 'Markdown';
        }
        if ( $reply_markup_json ) {
            $fields['reply_markup'] = $reply_markup_json;
        }

        $body = '';
        foreach ( $fields as $key => $value ) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        // فایل
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"photo\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $image_data . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // ساخت URL
        if ( ! empty( $this->worker_url ) ) {
            $base = rtrim( $this->worker_url, '/' ) . '/bot' . $this->bot_token;
        } else {
            $base = 'https://api.telegram.org/bot' . $this->bot_token;
        }
        $url = $base . '/sendPhoto';

        $response = wp_remote_post( $url, [
            'timeout' => 90,
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 0, $chat_id, 'error', $response->get_error_message() );
            return $response;
        }

        $body_response = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( $body_response, true );

        $status = ( $code === 200 && ! empty( $data['ok'] ) ) ? 'success' : 'error';
        $this->log( 0, $chat_id, $status, $body_response );

        return $data;
    }

    /* ============================================================
       تشخیص خطای دانلود URL
       ============================================================ */
    private function is_url_download_error( $result ) {
        if ( ! is_array( $result ) ) return false;
        if ( empty( $result['error_code'] ) ) return false;

        $desc = strtolower( $result['description'] ?? '' );

        $patterns = [
            'failed to get http url content',
            'url host is empty',
            'invalid file http url',
            'webpage_curl_failed',
            'wrong file identifier',
            'wrong remote file identifier',
            'http url content',
        ];

        foreach ( $patterns as $p ) {
            if ( strpos( $desc, $p ) !== false ) return true;
        }
        return false;
    }

    /* ============================================================
       آلبوم عکس
       ============================================================ */
    public function send_media_group( $chat_id, $photos, $caption = '' ) {
        $media = [];
        foreach ( $photos as $i => $photo ) {
            $item = [
                'type'  => 'photo',
                'media' => $photo['url'],
            ];
            if ( $i === 0 && $caption !== '' ) {
                $item['caption']    = $caption;
                $item['parse_mode'] = 'Markdown';
            }
            $media[] = $item;
        }

        return $this->request( 'sendMediaGroup', [
            'chat_id' => $chat_id,
            'media'   => $media,
        ] );
    }

    /* ============================================================
       ارسال کامل محصول
       ============================================================ */
    public function send_product( $chat_id, $text, $images, $reply_markup = null ) {
        $count = count( $images );

        // تبدیل reply_markup به JSON
        $markup_json = null;
        if ( is_array( $reply_markup ) && ! empty( $reply_markup ) ) {
            $encoded = wp_json_encode( $reply_markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( $encoded !== false ) $markup_json = $encoded;
        }

        // بدون عکس
        if ( $count === 0 ) {
            return $this->send_message( $chat_id, $text, $markup_json );
        }

        // حالت upload: نمیتونیم آلبوم بفرستیم (چون multipart جدا لازمه)
        if ( $this->image_method === 'upload' ) {
            return $this->send_photos_individually( $chat_id, $text, $images, $markup_json );
        }

        // یک عکس
        if ( $count === 1 ) {
            return $this->send_photo(
                $chat_id,
                $images[0]['url'],
                $text,
                $markup_json,
                $images[0]['path'] ?? null
            );
        }

        // چند عکس (≤ ۱۰): آلبوم با URL
        if ( $count <= 10 ) {
            $album_result = $this->send_media_group( $chat_id, $images, $text );

            // در حالت auto: اگه آلبوم خطا داد، تک تک بفرست
            if ( $this->image_method === 'auto' && is_array( $album_result ) && ! empty( $album_result['error_code'] ) ) {
                return $this->send_photos_individually( $chat_id, $text, $images, $markup_json );
            }

            // ارسال دکمهها به صورت پیام جداگانه (آلبوم reply_markup نمیپذیره)
            if ( $markup_json ) {
                $this->send_message( $chat_id, '👇 گزینههای خرید:', $markup_json );
            }
            return $album_result;
        }

        // بیش از ۱۰ عکس
        $this->send_message( $chat_id, $text, $markup_json );
        $chunks = array_chunk( $images, 10 );
        $results = [];
        foreach ( $chunks as $chunk ) {
            $results[] = $this->send_media_group( $chat_id, $chunk, '' );
        }
        return $results;
    }

    /* ============================================================
       ارسال عکسها به صورت تک تک (fallback)
       ============================================================ */
    private function send_photos_individually( $chat_id, $text, $images, $markup_json ) {
        $first = true;
        $results = [];
        foreach ( $images as $img ) {
            $caption = $first ? $text : '';
            $markup  = $first ? $markup_json : null;

            $results[] = $this->send_photo(
                $chat_id,
                $img['url'],
                $caption,
                $markup,
                $img['path'] ?? null
            );
            $first = false;
            usleep( 300000 );
        }
        return $results;
    }

    /* ============================================================
       درخواست عمومی
       ============================================================ */
    private function request( $method, $params ) {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'wcts_no_token', 'توکن ربات تنظیم نشده است.' );
        }

        if ( ! empty( $this->worker_url ) ) {
            $base = rtrim( $this->worker_url, '/' ) . '/bot' . $this->bot_token;
        } else {
            $base = 'https://api.telegram.org/bot' . $this->bot_token;
        }

        $url = $base . '/' . $method;

        if ( $method === 'sendMediaGroup' ) {
            $response = wp_remote_post( $url, [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ] );
        } else {
            $response = wp_remote_post( $url, [
                'timeout' => 30,
                'body'    => $params,
            ] );
        }

        if ( is_wp_error( $response ) ) {
            $this->log( 0, $params['chat_id'] ?? '', 'error', $response->get_error_message() );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( $body, true );

        $status = ( $code === 200 && ! empty( $data['ok'] ) ) ? 'success' : 'error';
        $this->log( 0, $params['chat_id'] ?? '', $status, $body );

        return $data;
    }

    private function log( $product_id, $chat_id, $status, $response ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcts_logs';
        $wpdb->insert( $table, [
            'product_id' => $product_id,
            'chat_id'    => $chat_id,
            'status'     => $status,
            'response'   => is_string( $response ) ? $response : wp_json_encode( $response ),
        ] );
    }

    public function test_connection( $chat_id ) {
        return $this->send_message(
            $chat_id,
            "✅ *تست اتصال موفق*\n"
          . "🌐 سایت: " . get_bloginfo( 'name' ) . "\n"
          . "🕐 زمان: " . date_i18n( 'Y/m/d H:i:s' ),
            null
        );
    }
}