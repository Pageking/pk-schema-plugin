<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tijdelijke inspector-pagina: kies een post type en post, en bekijk de
 * volledige data-array die de collector eruit haalt. Dient om te bepalen
 * welke velden bruikbaar zijn voor Schema-generatie, voordat we de
 * automatische loop over alle posts/post types bouwen.
 */
class PK_Schema_Admin {

    private $data_collector;
    private $generator;
    private $validator;

    public function __construct() {
        $this->data_collector = new PK_Schema_Data_Collector();
        $this->generator = new PK_Schema_Generator();
        $this->validator = new PK_Schema_Validator();
        add_action('admin_menu', array($this, 'register_menu'));
        // TODO: tijdelijk t.b.v. testfase — weghalen zodra de inspector niet meer nodig is.
        add_action('admin_post_pk_schema_download', array($this, 'handle_download'));
    }

    public function register_menu() {
        add_submenu_page(
            'pk-schema-plugin',
            'Data Inspector',
            'Data Inspector',
            'administrator',
            'pk-schema-plugin-inspector',
            array($this, 'render_inspector_page')
        );
    }

    public function render_inspector_page() {
        if (!current_user_can('administrator')) {
            return;
        }

        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);

        $selected_type = isset($_GET['pk_post_type']) ? sanitize_key($_GET['pk_post_type']) : '';
        $selected_post = isset($_GET['pk_post_id']) ? absint($_GET['pk_post_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>Pageking Schema — Data Inspector</h1>';
        echo '<p>Kies een post type en post om te zien welke data de plugin eruit haalt.</p>';

        $this->render_picker_form($post_types, $selected_type, $selected_post);

        if ($selected_post) {
            $this->render_data_dump($selected_post);
        }

        echo '</div>';
    }

    private function render_picker_form($post_types, $selected_type, $selected_post) {
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="pk-schema-plugin-inspector" />';

        echo '<select name="pk_post_type" id="pk_post_type_select">';
        echo '<option value="">— kies post type —</option>';
        foreach ($post_types as $post_type) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr($post_type->name),
                selected($selected_type, $post_type->name, false),
                esc_html($post_type->labels->singular_name),
                esc_html($post_type->name)
            );
        }
        echo '</select> ';

        if ($selected_type) {
            $posts = get_posts(array(
                'post_type'      => $selected_type,
                'posts_per_page' => 20,
                'post_status'    => 'any',
                'orderby'        => 'modified',
                'order'          => 'DESC',
            ));

            echo '<select name="pk_post_id">';
            echo '<option value="">— kies post —</option>';
            foreach ($posts as $p) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    $p->ID,
                    selected($selected_post, $p->ID, false),
                    esc_html($p->post_title ?: '(geen titel) #' . $p->ID)
                );
            }
            echo '</select> ';
        }

        submit_button('Bekijk data', 'primary', '', false);
        echo '</form>';
    }

    private function render_data_dump($post_id) {
        $data = $this->data_collector->get_post_data($post_id);

        if (!$data) {
            echo '<p>Post niet gevonden.</p>';
            return;
        }

        $download_url = wp_nonce_url(
            add_query_arg(array(
                'action'  => 'pk_schema_download',
                'post_id' => $post_id,
            ), admin_url('admin-post.php')),
            'pk_schema_download_' . $post_id
        );

        echo '<h2>Resultaat voor post #' . esc_html($post_id) . '</h2>';
        echo '<p><a href="' . esc_url($download_url) . '" class="button button-secondary">Download als JSON</a></p>';

        $this->render_schema_validation($post_id);

        echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;overflow:auto;max-height:80vh;">';
        echo esc_html(print_r($data, true));
        echo '</pre>';
    }

    /**
     * Bouwt het Schema.org-schema voor deze post (als het post type actief
     * staat) en toetst het aan Google's gedocumenteerde verplichte/aanbevolen
     * velden — zodat je meteen ziet of de output ook echt rich-result-waardig is.
     */
    private function render_schema_validation($post_id) {
        $schema = $this->generator->build_for_post($post_id);

        echo '<h2>Schema-validatie</h2>';

        if (!$schema) {
            echo '<p><em>Geen schema gebouwd voor deze post — post type staat niet actief, heeft geen Schema-type, of de builder gaf niets terug.</em></p>';
            return;
        }

        $result = $this->validator->validate($schema);

        if (!$result['checked']) {
            echo '<p>' . esc_html($result['note']) . '</p>';
        } else {
            echo '<p><strong>Type:</strong> ' . esc_html($result['type']) . ' — ';
            echo $result['valid']
                ? '<span style="color:#008a20;">✓ voldoet aan Google\'s verplichte velden</span>'
                : '<span style="color:#b32d2e;">✗ mist verplichte velden</span>';
            echo '</p>';

            if (!empty($result['missing_required'])) {
                echo '<p style="color:#b32d2e;">Ontbrekende verplichte velden: <code>' . esc_html(implode('</code>, <code>', $result['missing_required'])) . '</code></p>';
            }

            if (!empty($result['missing_recommended'])) {
                echo '<p style="color:#996800;">Ontbrekende aanbevolen velden: <code>' . esc_html(implode('</code>, <code>', $result['missing_recommended'])) . '</code></p>';
            }
        }

        echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;overflow:auto;max-height:40vh;">';
        echo esc_html(wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo '</pre>';
    }

    /**
     * TODO: tijdelijk t.b.v. testfase — weghalen zodra de inspector niet meer nodig is.
     */
    public function handle_download() {
        if (!current_user_can('administrator')) {
            wp_die('Geen toegang.');
        }

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        check_admin_referer('pk_schema_download_' . $post_id);

        $data = $this->data_collector->get_post_data($post_id);

        if (!$data) {
            wp_die('Post niet gevonden.');
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="pk-schema-post-' . $post_id . '.json"');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
