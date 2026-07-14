<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verzamelt alle beschikbare data voor een post in laagjes, zodat de plugin
 * werkt ongeacht hoe een site is opgebouwd (wel/geen ACF, welke SEO-plugin).
 */
class PK_Schema_Data_Collector {

    /**
     * Bouw de volledige, genormaliseerde data-array voor een post.
     */
    public function get_post_data($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            return null;
        }

        return array(
            'core'        => $this->safe_collect('get_core_data', $post),
            'taxonomies'  => $this->safe_collect('get_taxonomy_data', $post),
            'meta_raw'    => $this->safe_collect('get_raw_meta', $post),
            'acf'         => $this->safe_collect('get_acf_data', $post),
            'seo'         => $this->safe_collect('get_seo_data', $post),
            'woocommerce' => $this->safe_collect('get_woocommerce_data', $post),
            'language'    => $this->safe_collect('get_language_data', $post),
        );
    }

    /**
     * Isoleert elke laag: als één laag (bv. een kapotte ACF-veldgroep of een
     * WooCommerce-edge-case) een fout geeft, mislukt alleen die laag i.p.v.
     * de hele post — cruciaal zodra we straks duizenden posts automatisch
     * doorlopen en niet willen dat één rare post de hele run onderuit haalt.
     */
    private function safe_collect($method, $post) {
        try {
            return $this->$method($post);
        } catch (\Throwable $e) {
            return array('_error' => $e->getMessage());
        }
    }

    /**
     * Laag 1: standaard WP post-velden. Altijd aanwezig.
     */
    private function get_core_data($post) {
        $thumbnail_id = get_post_thumbnail_id($post);

        return array(
            'ID'              => $post->ID,
            'post_type'       => $post->post_type,
            'post_title'      => $post->post_title,
            'post_content'    => $post->post_content,
            'post_excerpt'    => $post->post_excerpt,
            'post_status'     => $post->post_status,
            'post_date'       => $post->post_date,
            'post_modified'   => $post->post_modified,
            'permalink'       => get_permalink($post) ?: null,
            'author'          => array(
                'ID'   => (int) $post->post_author,
                'name' => get_the_author_meta('display_name', $post->post_author),
            ),
            'featured_image'  => $thumbnail_id ? array(
                'ID'  => $thumbnail_id,
                'url' => wp_get_attachment_image_url($thumbnail_id, 'full'),
                'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
            ) : null,
        );
    }

    /**
     * Laag 2: taxonomieën + termen die aan dit post type hangen.
     */
    private function get_taxonomy_data($post) {
        $taxonomies = get_object_taxonomies($post->post_type, 'names');
        $data = array();

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            $data[$taxonomy] = wp_list_pluck($terms, 'name');
        }

        return $data;
    }

    /**
     * Laag 3: ruwe postmeta. Vangnet — vangt alles op wat niet via ACF of
     * een SEO-plugin loopt (custom meta boxes, andere plugins, etc.).
     */
    private function get_raw_meta($post) {
        $meta = get_post_meta($post->ID);

        // Ontdoe elke waarde van het standaard array-wrapper dat get_post_meta() gebruikt.
        return array_map(function ($values) {
            return count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }, $meta);
    }

    /**
     * Laag 4: ACF, alleen als het actief is. get_fields() lost repeaters en
     * flexible content op tot nette geneste arrays i.p.v. platte meta-keys.
     */
    private function get_acf_data($post) {
        if (!function_exists('get_fields')) {
            return null;
        }

        return $this->normalize_acf_value(get_fields($post->ID) ?: array());
    }

    /**
     * Loopt recursief door ACF-data heen (ook diep in repeaters/flexible
     * content) en vervangt WP_Post/WP_Term/WP_User objecten — die relationship-,
     * post object-, taxonomy- en user-velden kunnen teruggeven — door compacte,
     * JSON-vriendelijke arrays i.p.v. de volledige ruwe objecten (die o.a.
     * onnodige data zoals e-mailadressen of de volledige post_content van
     * gerelateerde posts zouden lekken).
     */
    private function normalize_acf_value($value) {
        if ($value instanceof WP_Post) {
            return array(
                'ID'        => $value->ID,
                'title'     => get_the_title($value),
                'permalink' => get_permalink($value) ?: null,
                'post_type' => $value->post_type,
            );
        }

        if ($value instanceof WP_Term) {
            return array(
                'term_id'  => $value->term_id,
                'name'     => $value->name,
                'slug'     => $value->slug,
                'taxonomy' => $value->taxonomy,
            );
        }

        if ($value instanceof WP_User) {
            return array(
                'ID'           => $value->ID,
                'display_name' => $value->display_name,
            );
        }

        if (is_array($value)) {
            return array_map(array($this, 'normalize_acf_value'), $value);
        }

        return $value;
    }

    /**
     * Laag 5: genormaliseerde SEO-data, ongeacht welke SEO-plugin actief is.
     */
    private function get_seo_data($post) {
        if (defined('SEOPRESS_VERSION')) {
            return $this->get_seopress_data($post);
        }

        if (defined('WPSEO_VERSION')) {
            return $this->get_yoast_data($post);
        }

        if (defined('RANK_MATH_VERSION')) {
            return $this->get_rankmath_data($post);
        }

        return null;
    }

    private function get_seopress_data($post) {
        return array(
            'plugin'           => 'seopress',
            'meta_title'       => get_post_meta($post->ID, '_seopress_titles_title', true),
            'meta_description' => get_post_meta($post->ID, '_seopress_titles_desc', true),
            'canonical'        => get_post_meta($post->ID, '_seopress_robots_canonical', true),
            'og_image'         => get_post_meta($post->ID, '_seopress_social_fb_img', true),
        );
    }

    private function get_yoast_data($post) {
        return array(
            'plugin'           => 'yoast',
            'meta_title'       => get_post_meta($post->ID, '_yoast_wpseo_title', true),
            'meta_description' => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true),
            'canonical'        => get_post_meta($post->ID, '_yoast_wpseo_canonical', true),
            'og_image'         => get_post_meta($post->ID, '_yoast_wpseo_opengraph-image', true),
        );
    }

    private function get_rankmath_data($post) {
        return array(
            'plugin'           => 'rankmath',
            'meta_title'       => get_post_meta($post->ID, 'rank_math_title', true),
            'meta_description' => get_post_meta($post->ID, 'rank_math_description', true),
            'canonical'        => get_post_meta($post->ID, 'rank_math_canonical_url', true),
            'og_image'         => get_post_meta($post->ID, 'rank_math_facebook_image', true),
        );
    }

    /**
     * Laag 6: WooCommerce, alleen voor het 'product' post type en alleen als
     * WooCommerce actief is. Productdata zit in een WC_Product object, niet
     * in ACF-velden — daarom een eigen laag i.p.v. de acf/meta_raw laag.
     */
    private function get_woocommerce_data($post) {
        if (!in_array($post->post_type, array('product', 'product_variation'), true) || !class_exists('WooCommerce')) {
            return null;
        }

        $product = wc_get_product($post->ID);

        if (!$product) {
            return null;
        }

        // wp_get_attachment_url() geeft false terug bij een verwijderde bijlage;
        // die willen we niet als "kapotte" waarde in de output laten staan.
        $gallery_urls = array_filter(array_map('wp_get_attachment_url', $product->get_gallery_image_ids()));

        $data = array(
            'sku'                => $product->get_sku(),
            'type'               => $product->get_type(),
            'regular_price'      => $product->get_regular_price(),
            'sale_price'         => $product->get_sale_price(),
            'price'              => $product->get_price(),
            'on_sale'            => $product->is_on_sale(),
            'currency'           => get_woocommerce_currency(),
            'stock_status'       => $product->get_stock_status(),
            'stock_quantity'     => $product->get_stock_quantity(),
            'weight'             => $product->get_weight(),
            'dimensions'         => array(
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ),
            'categories'         => wp_list_pluck(wc_get_product_terms($post->ID, 'product_cat'), 'name'),
            'tags'               => wp_list_pluck(wc_get_product_terms($post->ID, 'product_tag'), 'name'),
            'attributes'         => $this->get_woocommerce_attributes($product),
            'average_rating'     => $product->get_average_rating(),
            'review_count'       => $product->get_review_count(),
            'gallery_image_urls' => array_values($gallery_urls),
            'permalink'          => $product->get_permalink(),
        );

        // Variabele producten (maat/kleur-varianten) hebben geen vaste prijs op
        // het hoofdproduct — die zit per variatie. Zonder deze tak zou 'price'
        // hierboven leeg zijn voor een groot deel van een gemiddelde webshop.
        if ($product->is_type('variable')) {
            $data['price_range'] = array(
                'min' => $product->get_variation_price('min'),
                'max' => $product->get_variation_price('max'),
            );
            $data['variations'] = $this->get_woocommerce_variations($product);
        }

        return $data;
    }

    private function get_woocommerce_variations($product) {
        $variations = array();

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                continue;
            }

            $variations[] = array(
                'ID'           => $variation_id,
                'sku'          => $variation->get_sku(),
                'price'        => $variation->get_price(),
                'stock_status' => $variation->get_stock_status(),
                'attributes'   => $variation->get_variation_attributes(),
            );
        }

        return $variations;
    }

    private function get_woocommerce_attributes($product) {
        $attributes = array();

        foreach ($product->get_attributes() as $attribute) {
            $attributes[$attribute->get_name()] = $product->get_attribute($attribute->get_name());
        }

        return $attributes;
    }

    /**
     * Laag 7: WPML, alleen als het actief is. Belangrijk: WPML filtert
     * standaard elke WP_Query/get_posts() op de huidige taal. Zodra we straks
     * automatisch alle posts van een post type doorlopen, moet die filter
     * expliciet omzeild worden ('suppress_filters' => true), anders wordt
     * vertaalde content in andere talen stilzwijgend overgeslagen.
     */
    private function get_language_data($post) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return null;
        }

        $details = apply_filters('wpml_post_language_details', null, $post->ID);

        if (empty($details) || empty($details['language_code'])) {
            return null;
        }

        $trid = apply_filters('wpml_element_trid', null, $post->ID, 'post_' . $post->post_type);
        $translations = $trid ? apply_filters('wpml_get_element_translations', null, $trid, 'post_' . $post->post_type) : array();

        $related = array();
        foreach ((array) $translations as $lang_code => $translation) {
            if ((int) $translation->element_id === $post->ID) {
                continue;
            }
            $related[$lang_code] = array(
                'ID'        => (int) $translation->element_id,
                'permalink' => get_permalink($translation->element_id) ?: null,
            );
        }

        return array(
            'language_code'      => $details['language_code'],
            'is_default_language' => $details['language_code'] === apply_filters('wpml_default_language', null),
            'translations'       => $related,
        );
    }
}
