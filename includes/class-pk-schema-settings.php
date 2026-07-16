<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instellingenpagina: welke post types krijgen automatisch Schema.org markup,
 * en welk Schema.org-type daarbij hoort. Dit is de hoofdpagina van de plugin
 * (de Data Inspector is een submenu).
 */
class PK_Schema_Settings {

    const OPTION_KEY      = 'pk_schema_enabled_post_types';
    const SCHEMA_TYPE_KEY = 'pk_schema_type_map';
    const FIELD_MAP_KEY   = 'pk_schema_field_map';
    const AI_ENABLED_KEY  = 'pk_schema_ai_enabled';
    const AI_API_KEY_OPTION = 'pk_schema_openai_api_key';

    /**
     * Schema-concepten die per Schema-type site-specifiek gemapt kunnen
     * worden aan een ACF-veld — dit zijn de dingen die de plugin niet
     * betrouwbaar automatisch kan raden (in tegenstelling tot bv. SEO-data
     * of WooCommerce-prijzen, die al via eigen API's/meta-keys komen).
     */
    const FIELD_CONCEPTS = array(
        'Product' => array(
            'sku'          => 'SKU / artikelnummer',
            'price'        => 'Prijs',
            'availability' => 'Beschikbaarheid / voorraadstatus',
            'brand'        => 'Merk',
        ),
        'JobPosting' => array(
            'valid_through'    => 'Sluitingsdatum',
            'employment_type'  => 'Dienstverband',
            'location'         => 'Locatie',
            'salary'           => 'Salaris(indicatie)',
            'work_hours'       => 'Uren per week / werktijden',
            'qualifications'   => 'Functie-eisen (vaak in een WYSIWYG/repeater — geschikt voor AI-herkenning)',
            'responsibilities' => 'Taken/verantwoordelijkheden (vaak in een WYSIWYG/repeater — geschikt voor AI-herkenning)',
        ),
        'Person' => array(
            'job_title' => 'Functietitel',
        ),
        'Review' => array(
            'reviewer_name' => 'Naam recensent',
            'rating'        => 'Rating / score',
        ),
        'FAQPage' => array(
            'answer' => 'Antwoord',
        ),
    );

    /**
     * Ondersteunde Schema.org-types voor de dropdown. Bewust een praktische
     * subset gericht op wat bij agency-klantsites voorkomt, niet de volledige
     * Schema.org-vocabulaire.
     */
    const SCHEMA_TYPES = array(
        'Article'      => 'Article (blogpost/nieuwsartikel)',
        'WebPage'      => 'WebPage (generieke pagina)',
        'Product'      => 'Product',
        'FAQPage'      => 'FAQPage',
        'JobPosting'   => 'JobPosting (vacature)',
        'Review'       => 'Review (testimonial/recensie)',
        'Service'      => 'Service (dienst)',
        'Event'        => 'Event',
        'Person'       => 'Person (bv. teamlid)',
        'LocalBusiness' => 'LocalBusiness',
        'Organization' => 'Organization',
        ''             => 'Geen — sla dit post type over',
    );

    public function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_pk_schema_save_settings', array($this, 'handle_save'));
    }

    public function register_menu() {
        add_menu_page(
            'Pageking Schema',
            'Schema Plugin',
            'administrator',
            'pk-schema-plugin',
            array($this, 'render_settings_page'),
            'dashicons-code-standards'
        );
    }

    /**
     * Publieke helpers zodat andere delen van de plugin (straks de schema-
     * generator) simpel kunnen checken of/hoe een post type actief staat.
     */
    public static function get_enabled_post_types() {
        return get_option(self::OPTION_KEY, array());
    }

    public static function is_enabled($post_type) {
        return in_array($post_type, self::get_enabled_post_types(), true);
    }

    public static function get_schema_type($post_type) {
        $map = get_option(self::SCHEMA_TYPE_KEY, array());
        return isset($map[$post_type]) ? $map[$post_type] : '';
    }

    /**
     * Site-specifieke veldmapping: welk ACF-veld hoort bij welk Schema-concept
     * (bv. 'salary' -> 'salarisindicatie') voor een gegeven post type. Geeft
     * '' terug als er niets gemapt is — de builder valt dan terug op zijn
     * eigen kandidatenlijst-gok.
     */
    public static function get_mapped_field($post_type, $concept) {
        $map = get_option(self::FIELD_MAP_KEY, array());
        return isset($map[$post_type][$concept]) ? $map[$post_type][$concept] : '';
    }

    /**
     * AI-herkenning (OpenAI) is alleen actief als de beheerder 'm expliciet
     * heeft aangezet ÉN er een API-key beschikbaar is. Bewust geen default-aan
     * — content van de klantsite gaat dan naar een externe partij.
     */
    public static function is_ai_enabled() {
        return (bool) get_option(self::AI_ENABLED_KEY, false) && self::get_openai_api_key() !== '';
    }

    /**
     * Een constante in wp-config.php overschrijft de optie — zelfde patroon
     * als PK_SCHEMA_UPDATE_CHANNEL, en veiliger dan de key in de database
     * te bewaren (de instellingenpagina blijft werken als simpel alternatief).
     */
    public static function get_openai_api_key() {
        if (defined('PK_SCHEMA_OPENAI_API_KEY') && PK_SCHEMA_OPENAI_API_KEY !== '') {
            return PK_SCHEMA_OPENAI_API_KEY;
        }

        return (string) get_option(self::AI_API_KEY_OPTION, '');
    }

    /**
     * Alle ACF-velden die geregistreerd staan voor een post type, als
     * [veldnaam => label], t.b.v. de mapping-dropdowns. Leeg als ACF niet
     * actief is of het post type geen ACF-veldgroepen heeft.
     */
    public static function get_acf_fields_for_post_type($post_type) {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return array();
        }

        $fields = array();

        foreach (acf_get_field_groups(array('post_type' => $post_type)) as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                $fields[$field['name']] = $field['label'];
            }
        }

        return $fields;
    }

    /**
     * Alle taxonomieën die aan een post type hangen, als [slug => label] —
     * t.b.v. de mapping-dropdowns. Data zit niet altijd in een los ACF-veld;
     * soms hangt het concept (bv. merk, locatie) juist als taxonomie-term
     * aan de post.
     */
    public static function get_taxonomies_for_post_type($post_type) {
        $taxonomies = array();

        foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
            $taxonomies[$taxonomy->name] = $taxonomy->label;
        }

        return $taxonomies;
    }

    /**
     * Slimme suggestie voor het Schema-type, puur als startpunt voor de
     * dropdown — de gebruiker kan dit altijd zelf overschrijven. Post type
     * namen verschillen per klantsite, dus dit is bewust een heuristiek op
     * basis van naam/label en beschikbare data, geen garantie.
     */
    public static function guess_schema_type($post_type_name) {
        if ($post_type_name === 'product' && class_exists('WooCommerce')) {
            return 'Product';
        }

        $haystack = strtolower($post_type_name);
        $post_type_object = get_post_type_object($post_type_name);
        if ($post_type_object) {
            $haystack .= ' ' . strtolower($post_type_object->labels->name);
        }

        $keyword_map = array(
            'product'                 => 'Product',
            'vacature'                => 'JobPosting',
            'job'                     => 'JobPosting',
            'baan'                    => 'JobPosting',
            'faq'                     => 'FAQPage',
            'vraag'                   => 'FAQPage',
            'review'                  => 'Review',
            'testimonial'             => 'Review',
            'beoordeling'             => 'Review',
            'event'                   => 'Event',
            'evenement'               => 'Event',
            'team'                    => 'Person',
            'medewerker'              => 'Person',
            'dienst'                  => 'Service',
        );

        foreach ($keyword_map as $keyword => $schema_type) {
            if (strpos($haystack, $keyword) !== false) {
                return $schema_type;
            }
        }

        if ($post_type_name === 'page') {
            return 'WebPage';
        }

        if ($post_type_name === 'post') {
            return 'Article';
        }

        return '';
    }

    public function render_settings_page() {
        if (!current_user_can('administrator')) {
            return;
        }

        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);

        $enabled = self::get_enabled_post_types();
        $schema_map = get_option(self::SCHEMA_TYPE_KEY, array());

        echo '<div class="wrap">';
        echo '<h1>Pageking Schema — Instellingen</h1>';

        if (isset($_GET['pk_schema_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>';
        }

        echo '<p>Selecteer voor welke post types de plugin automatisch content analyseert en Schema.org markup bouwt, en welk Schema-type daarbij hoort. Het voorgestelde type is een suggestie — pas het gerust aan.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pk_schema_save_settings" />';
        wp_nonce_field('pk_schema_save_settings');

        $conflict_checker = new PK_Schema_Conflict_Checker();

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:40px;">Actief</th>';
        echo '<th>Post type</th>';
        echo '<th>Slug</th>';
        echo '<th>Aantal gepubliceerde posts</th>';
        echo '<th>Schema-type</th>';
        echo '<th>Let op</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($post_types)) {
            echo '<tr><td colspan="6">Geen publieke post types gevonden.</td></tr>';
        }

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type->name);
            $published_count = isset($counts->publish) ? (int) $counts->publish : 0;
            $is_checked = in_array($post_type->name, $enabled, true);

            $current_schema_type = isset($schema_map[$post_type->name])
                ? $schema_map[$post_type->name]
                : self::guess_schema_type($post_type->name);

            $warning = $is_checked ? $conflict_checker->check($post_type->name) : null;

            printf(
                '<tr><td><input type="checkbox" name="pk_schema_post_types[]" value="%1$s" %2$s /></td><td>%3$s</td><td><code>%1$s</code></td><td>%4$d</td><td>%5$s</td><td>%6$s</td></tr>',
                esc_attr($post_type->name),
                checked($is_checked, true, false),
                esc_html($post_type->labels->name),
                $published_count,
                $this->render_schema_type_select($post_type->name, $current_schema_type),
                $warning ? '<span style="color:#b32d2e;">⚠ ' . esc_html($warning) . '</span>' : '—'
            );
        }

        echo '</tbody></table>';

        $this->render_field_mapping($post_types, $enabled, $schema_map);
        $this->render_ai_settings();

        submit_button('Opslaan');
        echo '</form>';
        echo '</div>';
    }

    /**
     * Toont per (actief) post type met een Schema-type dat concept-velden
     * kent (Product, JobPosting) een mini-tabel om ACF-velden te koppelen
     * aan die concepten. Wordt pas zichtbaar ná opslaan van het Schema-type,
     * omdat de dropdown-keuze hierboven niet live ververst zonder JS.
     */
    private function render_field_mapping($post_types, $enabled, $schema_map) {
        $relevant = array_filter($post_types, function ($post_type) use ($enabled, $schema_map) {
            return in_array($post_type->name, $enabled, true)
                && isset($schema_map[$post_type->name])
                && isset(self::FIELD_CONCEPTS[$schema_map[$post_type->name]]);
        });

        if (empty($relevant)) {
            return;
        }

        echo '<h2>Veldmapping</h2>';
        echo '<p>Voor deze post types kent het gekozen Schema-type velden die per site kunnen verschillen (bv. salaris, sluitingsdatum, merk). Dat kan in een ACF-veld zitten, maar net zo goed in een taxonomie-term. Koppel hieronder de juiste bron. Laat op "— automatisch —" staan om de ingebouwde gok te laten gelden.</p>';

        foreach ($relevant as $post_type) {
            $schema_type = $schema_map[$post_type->name];
            $concepts = self::FIELD_CONCEPTS[$schema_type];
            $available_fields = self::get_acf_fields_for_post_type($post_type->name);
            $available_taxonomies = self::get_taxonomies_for_post_type($post_type->name);

            echo '<h3>' . esc_html($post_type->labels->name) . ' <code>(' . esc_html($post_type->name) . ')</code> — ' . esc_html($schema_type) . '</h3>';

            if (empty($available_fields) && empty($available_taxonomies)) {
                echo '<p><em>Geen ACF-velden of taxonomieën gevonden voor dit post type.</em></p>';
                continue;
            }

            echo '<table class="wp-list-table widefat fixed striped" style="max-width:700px;margin-bottom:2em;">';
            echo '<thead><tr><th>Schema-concept</th><th>Bron</th></tr></thead><tbody>';

            foreach ($concepts as $concept => $label) {
                $current = self::get_mapped_field($post_type->name, $concept);
                $field_name = sprintf('pk_schema_field_map[%s][%s]', esc_attr($post_type->name), esc_attr($concept));

                echo '<tr><td>' . esc_html($label) . '</td><td><select name="' . $field_name . '">';
                echo '<option value="">— automatisch —</option>';

                if (!empty($available_fields)) {
                    echo '<optgroup label="ACF-velden">';
                    foreach ($available_fields as $field_key => $field_label) {
                        $value = 'acf:' . $field_key;
                        printf(
                            '<option value="%s" %s>%s (%s)</option>',
                            esc_attr($value),
                            selected($current, $value, false),
                            esc_html($field_label),
                            esc_html($field_key)
                        );
                    }
                    echo '</optgroup>';
                }

                if (!empty($available_taxonomies)) {
                    echo '<optgroup label="Taxonomieën">';
                    foreach ($available_taxonomies as $tax_key => $tax_label) {
                        $value = 'tax:' . $tax_key;
                        printf(
                            '<option value="%s" %s>%s (%s)</option>',
                            esc_attr($value),
                            selected($current, $value, false),
                            esc_html($tax_label),
                            esc_html($tax_key)
                        );
                    }
                    echo '</optgroup>';
                }

                echo '</select></td></tr>';
            }

            echo '</tbody></table>';
        }
    }

    /**
     * AI-herkenning: als een concept écht nergens gemapt of geraden kan
     * worden (bv. functie-eisen die in een WYSIWYG binnen een repeater
     * staan), kan OpenAI als laatste redmiddel de vrije tekst van de post
     * doorzoeken. Bewust standaard uit — content gaat dan naar een externe
     * partij, dus dit moet een bewuste keuze per site zijn.
     */
    private function render_ai_settings() {
        $enabled = (bool) get_option(self::AI_ENABLED_KEY, false);
        $has_constant_key = defined('PK_SCHEMA_OPENAI_API_KEY') && PK_SCHEMA_OPENAI_API_KEY !== '';
        $option_key = get_option(self::AI_API_KEY_OPTION, '');

        echo '<h2>AI-herkenning (optioneel)</h2>';
        echo '<p>Voor velden die nergens in een los ACF-veld of taxonomie te vinden zijn — bijvoorbeeld functie-eisen die als losse bullets in een WYSIWYG-veld binnen een repeater staan — kan de plugin als laatste redmiddel OpenAI de tekst van de post laten doorzoeken. <strong>Let op:</strong> hiermee gaat content van deze site naar OpenAI. Zet dit alleen aan als dat past bij het privacybeleid van deze klant.</p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Actief</th><td>';
        echo '<label><input type="checkbox" name="pk_schema_ai_enabled" value="1" ' . checked($enabled, true, false) . ' /> Gebruik OpenAI om ontbrekende velden uit vrije tekst te herkennen</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">OpenAI API-key</th><td>';
        if ($has_constant_key) {
            echo '<p><em>Ingesteld via de constante <code>PK_SCHEMA_OPENAI_API_KEY</code> in wp-config.php — het veld hieronder wordt genegeerd.</em></p>';
        } else {
            printf(
                '<input type="password" name="pk_schema_openai_api_key" value="%s" class="regular-text" autocomplete="off" />',
                esc_attr($option_key)
            );
            echo '<p class="description">Voor extra veiligheid kun je in plaats hiervan ook <code>define(\'PK_SCHEMA_OPENAI_API_KEY\', \'sk-...\');</code> in wp-config.php zetten — dat overschrijft dit veld.</p>';
        }
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    private function render_schema_type_select($post_type_name, $current_value) {
        $field_name = sprintf('pk_schema_type[%s]', esc_attr($post_type_name));

        $html = '<select name="' . $field_name . '">';
        foreach (self::SCHEMA_TYPES as $value => $label) {
            $html .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_value, $value, false),
                esc_html($label)
            );
        }
        $html .= '</select>';

        return $html;
    }

    public function handle_save() {
        if (!current_user_can('administrator')) {
            wp_die('Geen toegang.');
        }

        check_admin_referer('pk_schema_save_settings');

        // Alleen echt bestaande, publieke post types opslaan — voorkomt dat er
        // via een geknoeide request een willekeurige waarde wordt opgeslagen.
        $valid_post_types = array_keys(get_post_types(array('public' => true)));
        $valid_schema_types = array_keys(self::SCHEMA_TYPES);

        $selected = isset($_POST['pk_schema_post_types']) ? (array) $_POST['pk_schema_post_types'] : array();
        $selected = array_map('sanitize_key', $selected);
        $selected = array_values(array_intersect($selected, $valid_post_types));

        $schema_types_input = isset($_POST['pk_schema_type']) ? (array) $_POST['pk_schema_type'] : array();
        $schema_map = array();

        foreach ($schema_types_input as $post_type => $schema_type) {
            $post_type = sanitize_key($post_type);

            if (!in_array($post_type, $valid_post_types, true) || !in_array($schema_type, $valid_schema_types, true)) {
                continue;
            }

            if ($schema_type !== '') {
                $schema_map[$post_type] = $schema_type;
            }
        }

        $field_map_input = isset($_POST['pk_schema_field_map']) ? (array) $_POST['pk_schema_field_map'] : array();
        $field_map = array();

        foreach ($field_map_input as $post_type => $concepts) {
            $post_type = sanitize_key($post_type);

            if (!in_array($post_type, $valid_post_types, true) || !isset($schema_map[$post_type])) {
                continue;
            }

            $valid_concepts = isset(self::FIELD_CONCEPTS[$schema_map[$post_type]])
                ? array_keys(self::FIELD_CONCEPTS[$schema_map[$post_type]])
                : array();

            // Alleen echt bestaande ACF-velden/taxonomieën van dít post type
            // accepteren — voorkomt dat een geknoeide request een willekeurige
            // meta-key of taxonomie opslaat. sanitize_key() sloopt de ':' uit
            // 'acf:'/'tax:', dus hier bewust sanitize_text_field() + een
            // expliciete whitelist-check i.p.v. sanitize_key().
            $valid_acf_fields = array_keys(self::get_acf_fields_for_post_type($post_type));
            $valid_taxonomies = array_keys(self::get_taxonomies_for_post_type($post_type));

            foreach ((array) $concepts as $concept => $mapped_value) {
                $concept = sanitize_key($concept);
                $mapped_value = sanitize_text_field($mapped_value);

                if (!in_array($concept, $valid_concepts, true) || $mapped_value === '') {
                    continue;
                }

                if (strpos($mapped_value, 'tax:') === 0) {
                    $is_valid = in_array(substr($mapped_value, 4), $valid_taxonomies, true);
                } elseif (strpos($mapped_value, 'acf:') === 0) {
                    $is_valid = in_array(substr($mapped_value, 4), $valid_acf_fields, true);
                } else {
                    $is_valid = false;
                }

                if (!$is_valid) {
                    continue;
                }

                $field_map[$post_type][$concept] = $mapped_value;
            }
        }

        update_option(self::OPTION_KEY, $selected);
        update_option(self::SCHEMA_TYPE_KEY, $schema_map);
        update_option(self::FIELD_MAP_KEY, $field_map);

        update_option(self::AI_ENABLED_KEY, !empty($_POST['pk_schema_ai_enabled']));

        // Alleen opslaan als er geen wp-config-constante is — anders zou een
        // leeg formulierveld de constante-waarde in de DB stilletjes overschrijven.
        if (!defined('PK_SCHEMA_OPENAI_API_KEY') && isset($_POST['pk_schema_openai_api_key'])) {
            update_option(self::AI_API_KEY_OPTION, sanitize_text_field($_POST['pk_schema_openai_api_key']));
        }

        $redirect_url = add_query_arg('pk_schema_saved', '1', admin_url('admin.php?page=pk-schema-plugin'));
        wp_safe_redirect($redirect_url);
        exit;
    }
}
