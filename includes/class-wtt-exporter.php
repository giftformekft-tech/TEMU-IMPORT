<?php
if (!defined('ABSPATH')) { exit; }

class WTT_Exporter {
    private $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    private function attr_key($which) {
        if ($which === 'type') return $this->settings['attr_type'] ?? 'pa_termektipus';
        if ($which === 'color') return $this->settings['attr_color'] ?? 'pa_szin';
        return $this->settings['attr_size'] ?? 'pa_meret';
    }

    private function get_variation_attr(WC_Product_Variation $v, $attr_key) {
        // WC stores variation attrs as: attribute_pa_xxx => 'value-slug' OR attribute_xxx => 'Value'
        $raw = $v->get_attribute($attr_key);
        if ($raw !== '') return $raw;

        // Fallback: try prefixed key
        $try = 'pa_' . ltrim($attr_key, 'pa_');
        $raw = $v->get_attribute($try);
        if ($raw !== '') return $raw;

        return '';
    }

    public function scan_attribute_values(array $product_ids) {
        $type_key = $this->attr_key('type');
        $color_key = $this->attr_key('color');
        $size_key = $this->attr_key('size');

        $types = [];
        $colors = [];
        $sizes = [];
        $warnings = [];

        foreach ($product_ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            if ($p->get_type() !== 'variable') {
                $warnings[] = "Termék #$pid nem variable (kihagyva a scanből).";
                continue;
            }

            $children = $p->get_children();
            foreach ($children as $vid) {
                $v = wc_get_product($vid);
                if (!$v || !($v instanceof WC_Product_Variation)) continue;

                $t = $this->get_variation_attr($v, $type_key);
                $c = $this->get_variation_attr($v, $color_key);
                $s = $this->get_variation_attr($v, $size_key);

                if ($t !== '') $types[$t] = true;
                if ($c !== '') $colors[$c] = true;
                if ($s !== '') $sizes[$s] = true;
            }
        }

        $sort = function($a){
            $out = array_keys($a);
            natcasesort($out);
            return array_values($out);
        };

        return [
            'termektipus' => $sort($types),
            'szin' => $sort($colors),
            'meret' => $sort($sizes),
            'warnings' => $warnings,
            'settings_used' => [
                'attr_type' => $type_key,
                'attr_color' => $color_key,
                'attr_size' => $size_key,
            ]
        ];
    }

    private function build_cat_tag_desc(WC_Product $p) {
        $join = $this->settings['join_sep'] ?? ' | ';

        $cats = wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']);
        $tags = wp_get_post_terms($p->get_id(), 'product_tag', ['fields' => 'names']);
        $cats_s = is_array($cats) && $cats ? implode(', ', $cats) : '';
        $tags_s = is_array($tags) && $tags ? implode(', ', $tags) : '';

        $desc_source = $this->settings['desc_source'] ?? 'short';
        $desc = ($desc_source === 'long') ? wp_strip_all_tags($p->get_description()) : wp_strip_all_tags($p->get_short_description());
        $desc = trim(preg_replace('/\s+/', ' ', $desc));

        $parts = [];
        if ($cats_s !== '') $parts[] = $cats_s;
        if ($tags_s !== '') $parts[] = $tags_s;
        if ($desc !== '') $parts[] = $desc;
        return implode($join, $parts);
    }

    private function rand5() {
        return strval(random_int(10000, 99999));
    }

    private function make_sku($base, &$seen) {
        $base = trim((string)$base);
        if ($base === '') $base = 'SKU';

        for ($i=0; $i<20; $i++) {
            $sku = $base . '-' . $this->rand5();
            if (!isset($seen[$sku])) { $seen[$sku]=true; return $sku; }
        }
        // last resort
        $sku = $base . '-' . time();
        if (!isset($seen[$sku])) { $seen[$sku]=true; return $sku; }
        return $base . '-' . uniqid();
    }

    public function generate_rows_session(array $product_ids, array $selected) {
        $type_key = $this->attr_key('type');
        $color_key = $this->attr_key('color');
        $size_key = $this->attr_key('size');

        $sel_t = array_flip(array_map('strval', $selected['termektipus'] ?? []));
        $sel_c = array_flip(array_map('strval', $selected['szin'] ?? []));
        $sel_s = array_flip(array_map('strval', $selected['meret'] ?? []));

        $rows = [];
        $warnings = [];
        $seen_skus = [];

        foreach ($product_ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            if ($p->get_type() !== 'variable') {
                $warnings[] = "Termék #$pid nem variable (kihagyva).";
                continue;
            }

            $parent_name = $p->get_name();
            $parent_sku = $p->get_sku();
            $desc = wp_strip_all_tags(($this->settings['desc_source'] ?? 'short') === 'long' ? $p->get_description() : $p->get_short_description());
            $desc = trim(preg_replace('/\s+/', ' ', $desc));
            $cat_tag_desc = $this->build_cat_tag_desc($p);

            foreach ($p->get_children() as $vid) {
                $v = wc_get_product($vid);
                if (!$v || !($v instanceof WC_Product_Variation)) continue;

                $t = $this->get_variation_attr($v, $type_key);
                $c = $this->get_variation_attr($v, $color_key);
                $s = $this->get_variation_attr($v, $size_key);

                if ($t === '' || $c === '' || $s === '') {
                    $warnings[] = "Variáció #$vid hiányos attribútum (t/c/s).";
                    continue;
                }
                if ($sel_t && !isset($sel_t[$t])) continue;
                if ($sel_c && !isset($sel_c[$c])) continue;
                if ($sel_s && !isset($sel_s[$s])) continue;

                $img_id = $v->get_image_id();
                if (!$img_id) $img_id = $p->get_image_id();
                $img_url = $img_id ? wp_get_attachment_url($img_id) : '';

                $base_sku = $v->get_sku();
                if (!$base_sku) $base_sku = $parent_sku;
                if (!$base_sku) $base_sku = 'P' . $pid . 'V' . $vid;

                $sku = $this->make_sku($base_sku, $seen_skus);

                $rows[] = [
                    'termek_nev' => $parent_name,
                    'sku' => $sku,
                    'leiras' => $desc,
                    'kat_tag_leiras' => $cat_tag_desc,
                    'meret' => $s,
                    'szin' => $c,
                    'varians_img_url' => $img_url,
                ];
            }
        }

        $session_id = 'wtt_' . wp_generate_password(18, false, false);
        set_transient($session_id, [
            'created' => time(),
            'rows' => $rows,
            'warnings' => $warnings,
        ], 60 * 30);

        return [
            'session_id' => $session_id,
            'row_count' => count($rows),
            'warnings' => $warnings,
        ];
    }

    public function get_session_rows($session_id, $page, $per_page) {
        $data = get_transient($session_id);
        if (!$data || !is_array($data)) {
            return ['error' => 'Session nem található vagy lejárt.'];
        }
        $rows = $data['rows'] ?? [];
        $total = count($rows);
        $offset = ($page - 1) * $per_page;
        $slice = array_slice($rows, $offset, $per_page);
        return [
            'items' => $slice,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'warnings' => $data['warnings'] ?? [],
        ];
    }

    public function download_csv_response($session_id) {
        $data = get_transient($session_id);
        if (!$data || !is_array($data)) {
            return new WP_REST_Response(['error' => 'Session nem található vagy lejárt.'], 404);
        }
        $rows = $data['rows'] ?? [];

        $filename = 'temu_export_' . date('Y-m-d_His') . '.csv';

        // Build CSV in memory
        $fh = fopen('php://temp', 'w+');
        // UTF-8 BOM for Excel
        fwrite($fh, "\xEF\xBB\xBF");

        $header = ['Terméknév','SKU','Leírás','Kategóriák+Tagek+Leírás','Méret','Szín','Variáns kép URL'];
        fputcsv($fh, $header, ';');

        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['termek_nev'] ?? '',
                $r['sku'] ?? '',
                $r['leiras'] ?? '',
                $r['kat_tag_leiras'] ?? '',
                $r['meret'] ?? '',
                $r['szin'] ?? '',
                $r['varians_img_url'] ?? '',
            ], ';');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $response = new WP_REST_Response($csv);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename=' . $filename);
        return $response;
    }
}
