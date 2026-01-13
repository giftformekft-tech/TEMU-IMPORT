<?php
if (!defined('ABSPATH')) { exit; }

class WTT_REST {
    public static function init() : void {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    public static function register() {
        register_rest_route('woo-to-temu/v1', '/products', [
            'methods' => 'GET',
            'permission_callback' => [__CLASS__, 'perm'],
            'callback' => [__CLASS__, 'products'],
            'args' => [
                'page' => ['default' => 1],
                'per_page' => ['default' => 25],
                'search' => ['default' => ''],
            ],
        ]);

        register_rest_route('woo-to-temu/v1', '/scan', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'perm'],
            'callback' => [__CLASS__, 'scan'],
        ]);

        register_rest_route('woo-to-temu/v1', '/generate', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'perm'],
            'callback' => [__CLASS__, 'generate'],
        ]);

        register_rest_route('woo-to-temu/v1', '/session', [
            'methods' => 'GET',
            'permission_callback' => [__CLASS__, 'perm'],
            'callback' => [__CLASS__, 'session_rows'],
            'args' => [
                'session_id' => ['required' => True],
                'page' => ['default' => 1],
                'per_page' => ['default' => 50],
            ],
        ]);

        register_rest_route('woo-to-temu/v1', '/export', [
            'methods' => 'GET',
            'permission_callback' => [__CLASS__, 'perm'],
            'callback' => [__CLASS__, 'export_csv'],
            'args' => [
                'session_id' => ['required' => True],
            ],
        ]);

        register_rest_route('woo-to-temu/v1', '/settings', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'perm'],
            'callback' => [__CLASS__, 'save_settings'],
        ]);
    }

    public static function perm() {
        return current_user_can('manage_woocommerce');
    }

    public static function products(WP_REST_Request $req) {
        if (!class_exists('WC_Product')) {
            return new WP_REST_Response(['error' => 'WooCommerce not available'], 400);
        }
        $page = max(1, intval($req->get_param('page')));
        $per_page = min(100, max(1, intval($req->get_param('per_page'))));
        $search = sanitize_text_field($req->get_param('search'));

        $qargs = [
            'status'   => ['publish', 'private', 'draft'],
            'limit'    => $per_page,
            'page'     => $page,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'type'     => ['variable', 'simple'],
            'paginate' => true,
            'return'   => 'objects',
        ];
        if ($search) $qargs['s'] = $search;

        $query = new WC_Product_Query($qargs);
        $res = $query->get_products();

        $products = is_object($res) && isset($res->products) ? $res->products : (is_array($res) ? $res : []);
        $total = is_object($res) && isset($res->total) ? intval($res->total) : count($products);

        $items = [];
        foreach ($products as $p) {
            /** @var WC_Product $p */
            $items[] = [
                'id' => $p->get_id(),
                'name' => $p->get_name(),
                'sku' => $p->get_sku(),
                'type' => $p->get_type(),
                'image' => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail'),
                'status' => $p->get_status(),
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
        ]);
    }

    public static function scan(WP_REST_Request $req) {
        $body = $req->get_json_params();
        $ids = isset($body['product_ids']) && is_array($body['product_ids']) ? array_map('intval', $body['product_ids']) : [];
        $ids = array_values(array_filter($ids));

        $settings = WTT_Exporter_Plugin::settings();
        $exporter = new WTT_Exporter($settings);
        $result = $exporter->scan_attribute_values($ids);

        return new WP_REST_Response($result);
    }

    public static function generate(WP_REST_Request $req) {
        $body = $req->get_json_params();
        $ids = isset($body['product_ids']) && is_array($body['product_ids']) ? array_map('intval', $body['product_ids']) : [];
        $ids = array_values(array_filter($ids));

        $selected = [
            'termektipus' => isset($body['termektipus']) && is_array($body['termektipus']) ? array_map('sanitize_text_field', $body['termektipus']) : [],
            'szin' => isset($body['szin']) && is_array($body['szin']) ? array_map('sanitize_text_field', $body['szin']) : [],
            'meret' => isset($body['meret']) && is_array($body['meret']) ? array_map('sanitize_text_field', $body['meret']) : [],
        ];

        $settings = WTT_Exporter_Plugin::settings();
        $exporter = new WTT_Exporter($settings);
        $out = $exporter->generate_rows_session($ids, $selected);

        return new WP_REST_Response($out);
    }

    public static function session_rows(WP_REST_Request $req) {
        $session_id = sanitize_text_field($req->get_param('session_id'));
        $page = max(1, intval($req->get_param('page')));
        $per_page = min(200, max(1, intval($req->get_param('per_page'))));

        $exporter = new WTT_Exporter(WTT_Exporter_Plugin::settings());
        return new WP_REST_Response($exporter->get_session_rows($session_id, $page, $per_page));
    }

    public static function export_csv(WP_REST_Request $req) {
        $session_id = sanitize_text_field($req->get_param('session_id'));
        $exporter = new WTT_Exporter(WTT_Exporter_Plugin::settings());
        return $exporter->download_csv_response($session_id);
    }

    public static function save_settings(WP_REST_Request $req) {
        $body = $req->get_json_params();
        $current = WTT_Exporter_Plugin::settings();

        $next = $current;
        foreach (['attr_type','attr_color','attr_size'] as $k) {
            if (isset($body[$k])) $next[$k] = sanitize_key($body[$k]);
        }
        if (isset($body['desc_source'])) {
            $v = sanitize_text_field($body['desc_source']);
            $next['desc_source'] = in_array($v, ['short','long'], true) ? $v : 'short';
        }
        if (isset($body['join_sep'])) {
            $next['join_sep'] = sanitize_text_field($body['join_sep']);
        }

        update_option(WTT_Exporter_Plugin::OPT_KEY, $next);
        return new WP_REST_Response(['ok' => true, 'settings' => $next]);
    }
}
