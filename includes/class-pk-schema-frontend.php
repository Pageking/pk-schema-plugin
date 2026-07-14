<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Voert het gebouwde Schema.org JSON-LD uit in <head> op de frontend.
 */
class PK_Schema_Frontend {

    private $generator;

    public function __construct() {
        $this->generator = new PK_Schema_Generator();
        add_action('wp_head', array($this, 'output_schema'));
    }

    public function output_schema() {
        $schema = null;

        if (is_singular()) {
            $schema = $this->generator->build_for_post(get_queried_object_id());
        } elseif (is_post_type_archive()) {
            $post_type = get_query_var('post_type');
            if (is_array($post_type)) {
                $post_type = reset($post_type);
            }
            $schema = $this->generator->build_faqpage_for_archive($post_type);
        }

        if (!$schema) {
            return;
        }

        echo '<script type="application/ld+json">'
            . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";
    }
}
