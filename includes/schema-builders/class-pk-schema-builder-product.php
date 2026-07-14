<?php
if (!defined('ABSPATH')) {
    exit;
}

class PK_Schema_Builder_Product extends PK_Schema_Builder_Base {

    public function build($post, array $data) {
        $schema = array(
            '@type'       => 'Product',
            'name'        => $data['core']['post_title'],
            'description' => $this->get_description($data),
            'url'         => $data['core']['permalink'],
        );

        $image = $this->get_image_url($data);
        if ($image) {
            $schema['image'] = $image;
        }

        $sku = !empty($data['woocommerce']['sku'])
            ? $data['woocommerce']['sku']
            : $this->resolve_concept($post, $data, 'sku', array('sku'));
        if ($sku) {
            $schema['sku'] = $sku;
        }

        $brand = $this->resolve_concept($post, $data, 'brand', array('brand', 'merk'));
        if ($brand) {
            $schema['brand'] = array('@type' => 'Brand', 'name' => $brand);
        }

        $offers = $this->build_offers($post, $data);
        if ($offers) {
            $schema['offers'] = $offers;
        }

        if (!empty($data['woocommerce']['average_rating']) && (float) $data['woocommerce']['average_rating'] > 0) {
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => $data['woocommerce']['average_rating'],
                'reviewCount' => (int) $data['woocommerce']['review_count'],
            );
        }

        return $schema;
    }

    /**
     * Werkt zowel voor WooCommerce-producten (simpel en variabel) als voor
     * losse ACF-velden (prijs/beschikbaarheid) op een custom product post type.
     */
    private function build_offers($post, array $data) {
        $currency = !empty($data['woocommerce']['currency']) ? $data['woocommerce']['currency'] : get_option('woocommerce_currency', 'EUR');

        // Variabel WooCommerce-product: prijsrange i.p.v. één vaste prijs.
        if (!empty($data['woocommerce']['price_range'])) {
            return array(
                '@type'         => 'AggregateOffer',
                'lowPrice'      => $data['woocommerce']['price_range']['min'],
                'highPrice'     => $data['woocommerce']['price_range']['max'],
                'priceCurrency' => $currency,
                'url'           => $data['core']['permalink'],
            );
        }

        $price = !empty($data['woocommerce']['price'])
            ? $data['woocommerce']['price']
            : $this->resolve_concept($post, $data, 'price', array('price', 'prijs'));

        if ($price === null || $price === '') {
            return null;
        }

        $offer = array(
            '@type'         => 'Offer',
            'price'         => $price,
            'priceCurrency' => $currency,
            'url'           => $data['core']['permalink'],
        );

        $availability = $this->map_availability(
            !empty($data['woocommerce']['stock_status'])
                ? $data['woocommerce']['stock_status']
                : $this->resolve_concept($post, $data, 'availability', array('availability', 'beschikbaarheid'))
        );

        if ($availability) {
            $offer['availability'] = $availability;
        }

        return $offer;
    }

    private function map_availability($status) {
        $map = array(
            'instock'      => 'https://schema.org/InStock',
            'outofstock'   => 'https://schema.org/OutOfStock',
            'onbackorder'  => 'https://schema.org/BackOrder',
            'in_stock'     => 'https://schema.org/InStock',
            'out_of_stock' => 'https://schema.org/OutOfStock',
            'preorder'     => 'https://schema.org/PreOrder',
        );

        return isset($map[$status]) ? $map[$status] : null;
    }
}
