<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versie-stempels voor de caching van de review/FAQPage-aggregatie in
 * PK_Schema_Generator. In plaats van precies bij te houden welk product
 * welke review raakt (complex, foutgevoelig bij wijzigende koppelingen),
 * verhogen we simpelweg een versienummer zodra er iets wijzigt aan een post
 * van een Review- of FAQPage-geconfigureerd post type — dat maakt alle
 * bestaande transients voor die groep in één klap ongeldig.
 */
class PK_Schema_Cache {

    const REVIEWS_VERSION_OPTION = 'pk_schema_reviews_version';
    const FAQPAGE_VERSION_OPTION = 'pk_schema_faqpage_version';

    public function __construct() {
        add_action('save_post', array($this, 'maybe_invalidate'));
        add_action('before_delete_post', array($this, 'maybe_invalidate'));
    }

    public function maybe_invalidate($post_id) {
        $post_type = get_post_type($post_id);

        if (!$post_type) {
            return;
        }

        $schema_type = PK_Schema_Settings::get_schema_type($post_type);

        if ($schema_type === 'Review') {
            self::bump(self::REVIEWS_VERSION_OPTION);
        }

        if ($schema_type === 'FAQPage') {
            self::bump(self::FAQPAGE_VERSION_OPTION);
        }
    }

    public static function get_version($option) {
        return (int) get_option($option, 1);
    }

    private static function bump($option) {
        update_option($option, self::get_version($option) + 1);
    }
}
