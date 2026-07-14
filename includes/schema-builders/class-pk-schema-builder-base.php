<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gedeelde helpers voor alle Schema-type builders. Elke builder krijgt de
 * volledige, laagjes-array van PK_Schema_Data_Collector::get_post_data() en
 * geeft een array terug die direct als JSON-LD geëncodeerd kan worden (zonder
 * '@context', die voegt de generator zelf toe).
 */
abstract class PK_Schema_Builder_Base {

    /**
     * @param WP_Post $post
     * @param array   $data  Output van PK_Schema_Data_Collector::get_post_data().
     * @return array|null
     */
    abstract public function build($post, array $data);

    protected function get_description(array $data) {
        if (!empty($data['seo']['meta_description'])) {
            return $data['seo']['meta_description'];
        }

        if (!empty($data['core']['post_excerpt'])) {
            return $this->clean_text($data['core']['post_excerpt']);
        }

        $content = $data['core']['post_content'] ?? '';
        return $content ? wp_trim_words($this->clean_text($content), 30) : '';
    }

    /**
     * Strip HTML-tags én decodeer entities (bv. '&euro;' -> '€') — Schema.org
     * tekstvelden zoals description/reviewBody horen platte tekst te zijn.
     */
    protected function clean_text($value) {
        return html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
    }

    protected function get_image_url(array $data) {
        if (!empty($data['core']['featured_image']['url'])) {
            return $data['core']['featured_image']['url'];
        }

        if (!empty($data['woocommerce']['gallery_image_urls'][0])) {
            return $data['woocommerce']['gallery_image_urls'][0];
        }

        return null;
    }

    protected function to_iso8601($mysql_datetime) {
        if (empty($mysql_datetime)) {
            return null;
        }

        $formatted = mysql2date('c', $mysql_datetime);
        return $formatted ?: null;
    }

    protected function get_language(array $data) {
        return !empty($data['language']['language_code']) ? $data['language']['language_code'] : null;
    }

    /**
     * Zoekt een ACF-waarde op onder de eerste van meerdere mogelijke
     * veldnamen — verschillende sites noemen hetzelfde concept anders
     * (bv. 'locatie' vs 'location').
     */
    protected function find_acf_value(array $data, array $possible_keys) {
        foreach ($possible_keys as $key) {
            if (!empty($data['acf'][$key])) {
                return $data['acf'][$key];
            }
        }

        return null;
    }

    /**
     * Haalt een Schema-concept op: eerst de handmatige veldmapping uit de
     * instellingenpagina (PK_Schema_Settings::FIELD_CONCEPTS), en pas als daar
     * niets is ingesteld de ingebouwde kandidatenlijst-gok als fallback.
     */
    protected function resolve_concept($post, array $data, $concept, array $fallback_keys) {
        $mapped_field = PK_Schema_Settings::get_mapped_field($post->post_type, $concept);

        if ($mapped_field && !empty($data['acf'][$mapped_field])) {
            return $data['acf'][$mapped_field];
        }

        return $this->find_acf_value($data, $fallback_keys);
    }
}
