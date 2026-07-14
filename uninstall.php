<?php
// WordPress roept dit bestand automatisch aan wanneer de plugin via
// wp-admin daadwerkelijk verwijderd wordt (niet bij deactiveren). Deze
// check voorkomt dat het bestand op een andere manier uitgevoerd kan worden.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = array(
    'pk_schema_enabled_post_types',
    'pk_schema_type_map',
    'pk_schema_field_map',
    'pk_schema_reviews_version',
    'pk_schema_faqpage_version',
    // Eigen state-optie van de Plugin Update Checker library.
    'external_updates-pk-schema-plugin',
);

foreach ($options as $option) {
    delete_option($option);

    if (is_multisite()) {
        delete_site_option($option);
    }
}

// De review/FAQPage-caches gebruiken dynamische transient-namen (post-ID +
// versienummer erin verwerkt), dus die kunnen niet één voor één op naam
// verwijderd worden — direct opruimen via de database i.p.v. delete_transient().
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pk\\_schema\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pk\\_schema\\_%'"
);
