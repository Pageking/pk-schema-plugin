<?php
/**
 * Plugin Name: Pageking Schema Plugin
 * Plugin URI: https://pageking.nl
 * Description: Analyseert content per post type en bouwt automatisch Schema.org markup voor zoekmachines.
 * Version: 0.1.0
 * Author: Pageking
 * Author URI: https://pageking.nl
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: pk-schema-plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PK_SCHEMA_VERSION', '0.1.0');
define('PK_SCHEMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PK_SCHEMA_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Update-channel: standaard 'main' (clean/stable). Op test-sites kan dit
// per site overschreven worden met: define('PK_SCHEMA_UPDATE_CHANNEL', 'develop');
// in wp-config.php, zonder dat de plugin-code zelf hoeft te wijzigen.
if (!defined('PK_SCHEMA_UPDATE_CHANNEL')) {
    define('PK_SCHEMA_UPDATE_CHANNEL', 'main');
}

// Plugin Update Checker
require PK_SCHEMA_PLUGIN_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$pkSchemaUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Pageking/pk-schema-plugin/',
    __FILE__,
    'pk-schema-plugin'
);
$pkSchemaUpdateChecker->setBranch(PK_SCHEMA_UPDATE_CHANNEL);

// Repo is public; geen authentication nodig

// Classes
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-data-collector.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-conflict-checker.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-settings.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-admin.php';

// Schema builders (elk Schema.org-type heeft zijn eigen builder-klasse)
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-base.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-article.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-webpage.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-product.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-jobposting.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-service.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-person.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/schema-builders/class-pk-schema-builder-organization.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-generator.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-frontend.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-cache.php';
require_once PK_SCHEMA_PLUGIN_PATH . 'includes/class-pk-schema-validator.php';

// Initialize the plugin
function pk_schema_init() {
    new PK_Schema_Settings();
    new PK_Schema_Admin();
    new PK_Schema_Frontend();
    new PK_Schema_Cache();
}
add_action('plugins_loaded', 'pk_schema_init');
