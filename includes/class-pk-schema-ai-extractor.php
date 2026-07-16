<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Laatste-redmiddel-laag: als een Schema-concept nergens via mapping, ACF of
 * taxonomie te vinden is (bv. functie-eisen die als losse bullets in een
 * WYSIWYG-veld binnen een repeater staan), laat OpenAI de vrije tekst van de
 * post doorzoeken. Draait op de achtergrond bij het opslaan van een post
 * (nooit live bij een paginaweergave) en het resultaat wordt gecached als
 * postmeta — resolve_concept() in de builders/generator leest die cache,
 * roept zelf nooit de API aan.
 */
class PK_Schema_AI_Extractor {

    const META_KEY      = '_pk_schema_ai_extracted';
    const HASH_META_KEY  = '_pk_schema_ai_extracted_hash';
    const MAX_TEXT_LENGTH = 8000;

    private $data_collector;

    public function __construct() {
        $this->data_collector = new PK_Schema_Data_Collector();
        add_action('pk_schema_run_ai_extraction', array($this, 'run_extraction'));
        add_action('save_post', array($this, 'maybe_schedule_extraction'));
    }

    /**
     * Zet alleen een achtergrondtaak klaar (WP Cron) i.p.v. de OpenAI-call
     * synchroon tijdens save_post uit te voeren — anders wacht de beheerder
     * in wp-admin op een externe API-aanroep bij elke publicatie/update.
     */
    public function maybe_schedule_extraction($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!PK_Schema_Settings::is_ai_enabled()) {
            return;
        }

        $post_type = get_post_type($post_id);

        if (!$post_type || !PK_Schema_Settings::is_enabled($post_type)) {
            return;
        }

        if (!wp_next_scheduled('pk_schema_run_ai_extraction', array($post_id))) {
            wp_schedule_single_event(time() + 10, 'pk_schema_run_ai_extraction', array($post_id));
        }
    }

    public static function get_extracted_value($post_id, $concept) {
        $data = get_post_meta($post_id, self::META_KEY, true);
        return (is_array($data) && isset($data[$concept]) && $data[$concept] !== null && $data[$concept] !== '')
            ? $data[$concept]
            : null;
    }

    /**
     * Uitgevoerd via een achtergrond-cronjob (zie PK_Schema_Cache), niet
     * synchroon tijdens het opslaan — anders wacht de beheerder in wp-admin
     * op een externe API-aanroep bij elke keer publiceren/bijwerken.
     */
    public function run_extraction($post_id) {
        if (!PK_Schema_Settings::is_ai_enabled()) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $schema_type = PK_Schema_Settings::get_schema_type($post->post_type);
        if (empty($schema_type) || !isset(PK_Schema_Settings::FIELD_CONCEPTS[$schema_type])) {
            return;
        }

        $data = $this->data_collector->get_post_data($post_id);

        // Alleen opnieuw naar OpenAI als de content écht gewijzigd is sinds
        // de vorige run — voorkomt onnodige kosten bij elke tussentijdse save.
        $content_hash = md5(wp_json_encode($data['acf']) . $data['core']['post_content']);
        if (get_post_meta($post_id, self::HASH_META_KEY, true) === $content_hash) {
            return;
        }

        $concepts_to_ask = $this->find_concepts_to_ask($post->post_type, $schema_type);

        if (empty($concepts_to_ask)) {
            delete_post_meta($post_id, self::META_KEY);
            update_post_meta($post_id, self::HASH_META_KEY, $content_hash);
            return;
        }

        $text = $this->collect_text($data);

        if (trim($text) !== '') {
            $extracted = $this->call_openai($text, $concepts_to_ask, $schema_type);

            if ($extracted !== null) {
                update_post_meta($post_id, self::META_KEY, $extracted);
            }
        }

        update_post_meta($post_id, self::HASH_META_KEY, $content_hash);
    }

    /**
     * Concepten waar geen expliciete handmatige mapping voor is ingesteld —
     * die stuurt de plugin naar OpenAI. Concepten die de kandidatenlijst-gok
     * in ACF/taxonomie wél vindt, worden door resolve_concept() sowieso al
     * vóór de AI-cache gebruikt, dus het is geen probleem als we hier iets
     * vragen dat toch al gevonden zou zijn — het kost alleen een beetje extra
     * (eenmalige, niet-live) API-gebruik.
     */
    private function find_concepts_to_ask($post_type, $schema_type) {
        $concepts = array_keys(PK_Schema_Settings::FIELD_CONCEPTS[$schema_type]);

        return array_values(array_filter($concepts, function ($concept) use ($post_type) {
            return PK_Schema_Settings::get_mapped_field($post_type, $concept) === '';
        }));
    }

    /**
     * Verzamelt alle vrije tekst van de post: kernvelden + elke tekstwaarde
     * die ergens in de (al genormaliseerde) ACF-data zit, hoe diep ook genest
     * in repeaters/flexible content — precies waar functie-eisen in een
     * WYSIWYG-repeater anders onvindbaar zouden blijven.
     */
    private function collect_text(array $data) {
        $parts = array(
            $data['core']['post_title'] ?? '',
            $data['core']['post_content'] ?? '',
            $data['core']['post_excerpt'] ?? '',
        );

        $this->collect_text_recursive($data['acf'] ?? array(), $parts);

        $text = wp_strip_all_tags(implode("\n", array_filter($parts)));

        return mb_substr($text, 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * Technische media-metadata (afbeeldingsafmetingen, mime-types, etc.)
     * overslaan — dat is ruis, geen content om te doorzoeken.
     */
    private function collect_text_recursive($value, array &$parts) {
        static $skip_keys = array(
            'url', 'sizes', 'filename', 'mime_type', 'ID', 'id', 'width',
            'height', 'filesize', 'icon', 'permalink', 'link', 'subtype',
            'type', 'status', 'menu_order', 'date', 'modified',
        );

        if (is_array($value)) {
            foreach ($value as $key => $sub_value) {
                if (is_string($key) && in_array($key, $skip_keys, true)) {
                    continue;
                }
                $this->collect_text_recursive($sub_value, $parts);
            }
        } elseif (is_string($value) && mb_strlen(trim($value)) > 2) {
            $parts[] = $value;
        }
    }

    /**
     * OpenAI Structured Outputs: dwingt een JSON-antwoord af dat exact aan
     * ons schema voldoet (elk gevraagd concept is string-of-null), zodat we
     * nooit vrije tekst hoeven te parsen of te gokken naar het formaat.
     */
    private function call_openai($text, array $concepts, $schema_type) {
        $api_key = PK_Schema_Settings::get_openai_api_key();

        if ($api_key === '') {
            return null;
        }

        $properties = array();
        foreach ($concepts as $concept) {
            $properties[$concept] = array(
                'type'        => array('string', 'null'),
                'description' => PK_Schema_Settings::FIELD_CONCEPTS[$schema_type][$concept],
            );
        }

        $body = array(
            'model'    => 'gpt-4o-mini',
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => 'Je krijgt de volledige tekstinhoud van een WordPress-post. Haal er alleen de gevraagde velden uit als de informatie er expliciet in staat. Verzin niets en leid niets af — geef null terug voor elk veld dat niet duidelijk in de tekst staat.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $text,
                ),
            ),
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'pk_schema_extraction',
                    'strict' => true,
                    'schema' => array(
                        'type'                 => 'object',
                        'properties'           => $properties,
                        'required'             => array_keys($properties),
                        'additionalProperties' => false,
                    ),
                ),
            ),
        );

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        $content = $result['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            return null;
        }

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            return null;
        }

        // Alleen echt gevonden waarden bewaren, null-antwoorden weglaten.
        return array_filter($parsed, function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
