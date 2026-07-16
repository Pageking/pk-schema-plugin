<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orkestreert de databerzameling + de juiste builder-klasse tot een kant-en-
 * klaar Schema.org-array. Nieuw type toevoegen = nieuwe builder-klasse +
 * registratie in $builders, verder hoeft niets in deze klasse te wijzigen.
 */
class PK_Schema_Generator {

    private $collector;
    private $builders;

    public function __construct() {
        $this->collector = new PK_Schema_Data_Collector();
        $this->builders = array(
            'Article'      => new PK_Schema_Builder_Article(),
            'WebPage'      => new PK_Schema_Builder_WebPage(),
            'Product'      => new PK_Schema_Builder_Product(),
            'JobPosting'   => new PK_Schema_Builder_JobPosting(),
            'Service'      => new PK_Schema_Builder_Service(),
            'Person'       => new PK_Schema_Builder_Person(),
            'Organization' => new PK_Schema_Builder_Organization(),
        );
    }

    /**
     * Bouwt het schema voor een losse post. FAQPage en Review worden hier
     * bewust niet als top-level schema gebouwd: FAQPage hoort op de
     * archiefpagina van het post type thuis (zie build_faqpage_for_archive),
     * Review wordt genest in het Product waar het naar verwijst.
     */
    public function build_for_post($post_id) {
        $post = get_post($post_id);

        if (!$post || !PK_Schema_Settings::is_enabled($post->post_type)) {
            return null;
        }

        $schema_type = PK_Schema_Settings::get_schema_type($post->post_type);

        if (empty($schema_type) || !isset($this->builders[$schema_type])) {
            return null;
        }

        $data = $this->collector->get_post_data($post_id);
        $schema = $this->builders[$schema_type]->build($post, $data);

        if (!$schema) {
            return null;
        }

        if ($schema_type === 'Product') {
            $schema = $this->attach_reviews($post, $schema);
        }

        $schema['@context'] = 'https://schema.org';

        return $schema;
    }

    /**
     * Bouwt een FAQPage-schema voor de archiefpagina van een post type dat als
     * 'FAQPage' is geconfigureerd — alle gepubliceerde posts samen vormen de
     * mainEntity-lijst (elke post = één vraag+antwoord).
     */
    public function build_faqpage_for_archive($post_type) {
        if (empty($post_type) || !PK_Schema_Settings::is_enabled($post_type)) {
            return null;
        }

        if (PK_Schema_Settings::get_schema_type($post_type) !== 'FAQPage') {
            return null;
        }

        // Deze aggregatie doorzoekt bij elke archiefweergave ALLE posts van dit
        // type — cachen via een versie-stempel voorkomt dat we dat bij elke
        // paginaweergave opnieuw doen; de cache wordt automatisch ongeldig
        // zodra een FAQ-post wijzigt (zie PK_Schema_Cache).
        $cache_key = sprintf('pk_schema_faqpage_%s_%d', $post_type, PK_Schema_Cache::get_version(PK_Schema_Cache::FAQPAGE_VERSION_OPTION));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached ?: null;
        }

        $posts = get_posts(array(
            'post_type'        => $post_type,
            'posts_per_page'   => -1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
        ));

        $entities = array();

        foreach ($posts as $post) {
            $data = $this->collector->get_post_data($post->ID);
            $answer = $this->resolve_concept($post_type, $data, 'answer', array('answer', 'antwoord'));

            if (empty($answer)) {
                $answer = $data['core']['post_content'];
            }

            if (empty($answer) || empty($data['core']['post_title'])) {
                continue;
            }

            $entities[] = array(
                '@type'          => 'Question',
                'name'           => $data['core']['post_title'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $this->clean_text($answer),
                ),
            );
        }

        $result = empty($entities) ? null : array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );

        // Lege uitkomst ook cachen (als lege array i.p.v. false, want get_transient()
        // geeft zelf false terug als er niets in de cache zit — dat zou anders elke
        // keer opnieuw als "geen cache" worden gelezen).
        set_transient($cache_key, $result ?: array(), DAY_IN_SECONDS);

        return $result;
    }

    /**
     * Zoekt posts van elk post type dat als 'Review' is geconfigureerd en
     * kijkt — via de al genormaliseerde ACF-data (relationship/post object
     * velden komen uit de collector al als {ID, title, permalink, ...} array)
     * — of ze naar déze product-post verwijzen. Geen aanname over veldnamen
     * per site nodig, omdat de collector die normalisatie al deed.
     */
    private function attach_reviews($product_post, array $product_schema) {
        $type_map = get_option(PK_Schema_Settings::SCHEMA_TYPE_KEY, array());
        $review_post_types = array_keys(array_filter($type_map, function ($type) {
            return $type === 'Review';
        }));

        if (empty($review_post_types)) {
            return $product_schema;
        }

        // Dit doorzoekt bij elke productpagina-weergave ALLE reviews van elk
        // Review-post type — cachen per product voorkomt dat bij elke
        // paginaweergave opnieuw. Ongeldig zodra een review-post wijzigt.
        $cache_key = sprintf('pk_schema_reviews_%d_%d', $product_post->ID, PK_Schema_Cache::get_version(PK_Schema_Cache::REVIEWS_VERSION_OPTION));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached ? array_merge($product_schema, $cached) : $product_schema;
        }

        $reviews = array();
        $ratings = array();

        foreach ($review_post_types as $review_post_type) {
            if (!PK_Schema_Settings::is_enabled($review_post_type)) {
                continue;
            }

            $review_posts = get_posts(array(
                'post_type'        => $review_post_type,
                'posts_per_page'   => -1,
                'post_status'      => 'publish',
                'suppress_filters' => true,
            ));

            foreach ($review_posts as $review_post) {
                $review_data = $this->collector->get_post_data($review_post->ID);

                if (!$this->references_post($review_data['acf'] ?? array(), $product_post->ID)) {
                    continue;
                }

                list($review_schema, $rating) = $this->build_review_schema($review_post_type, $review_data);
                $reviews[] = $review_schema;

                if ($rating !== null) {
                    $ratings[] = $rating;
                }
            }
        }

        if (empty($reviews)) {
            set_transient($cache_key, array(), DAY_IN_SECONDS);
            return $product_schema;
        }

        $additions = array('review' => $reviews);

        if ($ratings) {
            $additions['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => round(array_sum($ratings) / count($ratings), 1),
                'reviewCount' => count($ratings),
            );
        }

        set_transient($cache_key, $additions, DAY_IN_SECONDS);

        return array_merge($product_schema, $additions);
    }

    /**
     * Loopt recursief door genormaliseerde ACF-data (repeaters/groepen inbegrepen)
     * op zoek naar een reference-array met ID gelijk aan het doel.
     */
    private function references_post($value, $target_id) {
        if (!is_array($value)) {
            return false;
        }

        if (isset($value['ID']) && (int) $value['ID'] === (int) $target_id) {
            return true;
        }

        foreach ($value as $sub_value) {
            if ($this->references_post($sub_value, $target_id)) {
                return true;
            }
        }

        return false;
    }

    private function build_review_schema($review_post_type, array $review_data) {
        $reviewer = $this->resolve_concept($review_post_type, $review_data, 'reviewer_name', array('reviewer_name', 'naam'));
        if (!$reviewer) {
            $reviewer = $review_data['core']['author']['name'];
        }

        $rating_raw = $this->resolve_concept($review_post_type, $review_data, 'rating', array('rating', 'score'));
        $rating = ($rating_raw !== null && $rating_raw !== '') ? (float) $rating_raw : null;

        $schema = array(
            '@type'      => 'Review',
            'reviewBody' => $this->clean_text($review_data['core']['post_content']),
            'author'     => array(
                '@type' => 'Person',
                'name'  => $reviewer,
            ),
        );

        if ($rating !== null) {
            $schema['reviewRating'] = array(
                '@type'       => 'Rating',
                'ratingValue' => $rating,
                'bestRating'  => 5,
            );
        }

        return array($schema, $rating);
    }

    private function find_first_value(array $data, array $keys) {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * Zelfde principe als PK_Schema_Builder_Base::resolve_concept() — eerst de
     * handmatige veldmapping uit de instellingenpagina, pas daarna de
     * kandidatenlijst-gok (die zowel ACF-velden als taxonomieën met diezelfde
     * namen probeert — data zit niet altijd in een los veld). Losse
     * implementatie omdat de generator geen builder-subklasse is en hier met
     * een post_type-string werkt i.p.v. WP_Post (de FAQPage-archiefopbouw
     * heeft geen los post-object per se).
     */
    private function resolve_concept($post_type, array $data, $concept, array $fallback_keys) {
        $mapped = PK_Schema_Settings::get_mapped_field($post_type, $concept);

        if ($mapped) {
            if (strpos($mapped, 'tax:') === 0) {
                $taxonomy = substr($mapped, 4);
                if (!empty($data['taxonomies'][$taxonomy])) {
                    $terms = $data['taxonomies'][$taxonomy];
                    return count($terms) === 1 ? $terms[0] : implode(', ', $terms);
                }
            } else {
                $field = strpos($mapped, 'acf:') === 0 ? substr($mapped, 4) : $mapped;
                if (!empty($data['acf'][$field])) {
                    return $data['acf'][$field];
                }
            }
        }

        $value = $this->find_first_value($data['acf'] ?? array(), $fallback_keys);

        if ($value !== null) {
            return $value;
        }

        foreach ($fallback_keys as $key) {
            if (!empty($data['taxonomies'][$key])) {
                $terms = $data['taxonomies'][$key];
                return count($terms) === 1 ? $terms[0] : implode(', ', $terms);
            }
        }

        // Laatste redmiddel: de door OpenAI geëxtraheerde cache (alleen als
        // AI-herkenning aanstaat). Leest alleen de cache, roept nooit de API
        // hier live aan — zie PK_Schema_AI_Extractor.
        if (PK_Schema_Settings::is_ai_enabled() && !empty($data['core']['ID'])) {
            return PK_Schema_AI_Extractor::get_extracted_value($data['core']['ID'], $concept);
        }

        return null;
    }

    /**
     * Strip HTML-tags én decodeer entities (bv. '&euro;' -> '€') — Schema.org
     * tekstvelden zoals reviewBody/Answer.text horen platte tekst te zijn.
     */
    private function clean_text($value) {
        return html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
    }
}
