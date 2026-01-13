<?php
/**
 * Plugin Name: Woo to Temu Exporter (POD)
 * Description: Admin felület kijelölt WooCommerce termékek variánsainak Temu import táblázat (CSV) generálásához.
 * Version: 1.0.3
 * Author: ChatGPT
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) { exit; }

final class WTT_Exporter_Plugin {
    public const VERSION = '1.0.3';
    public const OPT_KEY = 'wtt_settings_v1';
    public const REST_NS = 'woo-to-temu/v1';

    private static $instance = null;

    public static function instance() : self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function boot() : void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                if (!current_user_can('activate_plugins')) return;
                echo '<div class="notice notice-error"><p><strong>Woo to Temu Exporter:</strong> WooCommerce szükséges.</p></div>';
            });
            return;
        }

        require_once __DIR__ . '/includes/class-wtt-exporter.php';
        require_once __DIR__ . '/includes/class-wtt-rest.php';

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

        add_action('wp_ajax_wtt_export_csv', [$this, 'ajax_export_csv']);
        WTT_REST::init();
    }

    public static function defaults() : array {
        // A JS UI ezeket a kulcsokat várja.
        return [
            'attr_type'   => 'pa_termektipus',
            'attr_color'  => 'pa_szin',
            'attr_size'   => 'pa_meret',
            'desc_source' => 'short', // short|long
            'join_sep'    => ' | ',
        ];
    }

    public static function get_settings() : array {
        $opt = get_option(self::OPT_KEY, []);
        if (!is_array($opt)) $opt = [];
        // Back-compat: ha régi kulcsok maradtak, átemeljük.
        if (isset($opt['attr_termektipus']) && !isset($opt['attr_type'])) $opt['attr_type'] = $opt['attr_termektipus'];
        if (isset($opt['attr_szin']) && !isset($opt['attr_color'])) $opt['attr_color'] = $opt['attr_szin'];
        if (isset($opt['attr_meret']) && !isset($opt['attr_size'])) $opt['attr_size'] = $opt['attr_meret'];
        if (isset($opt['concat_sep']) && !isset($opt['join_sep'])) $opt['join_sep'] = $opt['concat_sep'];
        return array_merge(self::defaults(), $opt);
    }

    // Alias, mert a REST osztály ezt hívja.
    public static function settings() : array {
        return self::get_settings();
    }

    public function admin_menu() : void {
        add_submenu_page(
            'woocommerce',
            'Woo → Temu Export',
            'Woo → Temu Export',
            'manage_woocommerce',
            'woo-to-temu-export',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() : void {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="wrap"><div id="wtt-admin-root"></div></div>';
    }

    public function enqueue_admin($hook) : void {
        if ($hook !== 'woocommerce_page_woo-to-temu-export') return;

        $handle = 'wtt-admin';
        wp_enqueue_style(
            $handle,
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['wp-element','wp-components','wp-api-fetch','wp-i18n'],
            self::VERSION,
            true
        );

        // Nonce a REST cookie auth-hoz. Sok host/optimalizáló plugin miatt az inline middleware néha nem fut le,
        // ezért a nonce-t JS-ben is átadjuk és ott is beállítjuk.
        $rest_nonce = wp_create_nonce('wp_rest');
        wp_add_inline_script('wp-api-fetch', 'window.wttApiFetchNonce = ' . json_encode($rest_nonce) . ';', 'before');
        wp_add_inline_script('wp-api-fetch', "wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( window.wttApiFetchNonce ) );", 'after');

        $ns = self::REST_NS;
        wp_localize_script($handle, 'WTT', [
            // wp.apiFetch path-hoz (REST root nélkül)
            'restPath' => '/' . $ns,
            // direct download link-hez (REST root-tal)
            'restDownload' => untrailingslashit(rest_url($ns)),
            'restNonce' => $rest_nonce,
            'settings' => self::get_settings(),
            'adminUrl' => admin_url('admin.php?page=woo-to-temu-export'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'exportNonce' => wp_create_nonce('wtt_export_csv'),
        ]);
    }

    public function ajax_export_csv() : void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        $nonce_ok = isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wtt_export_csv');
        if (!$nonce_ok) {
            wp_die('Bad nonce', 401);
        }
        $session_id = isset($_GET['session_id']) ? sanitize_text_field((string)$_GET['session_id']) : '';
        if (!$session_id) {
            wp_die('Missing session_id', 400);
        }

        $exporter = new WTT_Exporter(self::get_settings());
        $resp = $exporter->download_csv_response($session_id);

        if ($resp instanceof WP_REST_Response) {
            $status = $resp->get_status();
            if ($status && $status !== 200) {
                wp_die('Export error', (int)$status);
            }
            $headers = $resp->get_headers();
            foreach ($headers as $k => $v) {
                header($k . ': ' . $v);
            }
            echo $resp->get_data();
            exit;
        }

        wp_die('Export failed', 500);
    }

}

WTT_Exporter_Plugin::instance();
