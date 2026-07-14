<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detecteert of de actieve SEO-plugin mogelijk óók zijn eigen JSON-LD schema
 * uitvoert voor een post type dat wij beheren — om dubbele/conflicterende
 * structured data op dezelfde pagina te voorkomen. We forceren niets uit bij
 * de andere plugin (dat is niet aan ons); we signaleren het alleen duidelijk
 * in de instellingenpagina zodat de beheerder het bewust kan uitzetten.
 */
class PK_Schema_Conflict_Checker {

    /**
     * @return string|null Waarschuwingstekst, of null als er geen (bekend) risico is.
     */
    public function check($post_type) {
        if (defined('SEOPRESS_VERSION') && function_exists('get_posts')) {
            $warning = $this->check_seopress($post_type);
            if ($warning) {
                return $warning;
            }
        }

        if (defined('WPSEO_VERSION')) {
            return $this->check_yoast($post_type);
        }

        if (defined('RANK_MATH_VERSION')) {
            return $this->check_rankmath($post_type);
        }

        return null;
    }

    /**
     * SEOPress Pro slaat het gekozen Schema-type per post op in
     * '_seopress_pro_rich_snippets_type' — als dat veld bij posts van dit
     * post type is ingevuld, genereert SEOPress daar zelf ook JSON-LD voor.
     */
    private function check_seopress($post_type) {
        if (!defined('SEOPRESS_PRO_VERSION')) {
            return null;
        }

        $posts_with_schema = get_posts(array(
            'post_type'      => $post_type,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'suppress_filters' => true,
            'meta_query'     => array(
                array(
                    'key'     => '_seopress_pro_rich_snippets_type',
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
            'fields' => 'ids',
        ));

        if (empty($posts_with_schema)) {
            return null;
        }

        return sprintf(
            'SEOPress Pro heeft voor minstens één post van dit type ook een eigen Schema-type ingesteld (Rich Snippets). Zet dat in SEOPress uit voor dit post type om dubbele JSON-LD te voorkomen.'
        );
    }

    /**
     * Yoast genereert standaard automatisch een schema-graph (WebPage voor
     * pagina's, Article voor 'post') zonder dat er per post iets voor gekozen
     * hoeft te worden — dus als wij hetzelfde type bouwen, overlapt het altijd,
     * niet alleen als er iets specifieks is ingesteld.
     */
    private function check_yoast($post_type) {
        $schema_type = PK_Schema_Settings::get_schema_type($post_type);

        if (in_array($schema_type, array('Article', 'WebPage'), true)) {
            return 'Yoast SEO genereert standaard automatisch zijn eigen Article/WebPage-schema voor dit soort content. Overweeg dat in Yoast uit te zetten, of laat dit post type hier juist over aan Yoast.';
        }

        return null;
    }

    /**
     * RankMath heeft een eigen Schema Generator die per post handmatig een
     * type kan toewijzen. We hebben geen live RankMath-installatie kunnen
     * verifiëren voor het exacte meta-key-formaat — daarom hier bewust een
     * algemene waarschuwing i.p.v. een (mogelijk onjuiste) precieze check.
     */
    private function check_rankmath($post_type) {
        return 'RankMath heeft een eigen Schema Generator die mogelijk ook schema voor dit post type genereert. Controleer dit handmatig in RankMath — we hebben deze check niet tegen een live RankMath-installatie kunnen verifiëren.';
    }
}
