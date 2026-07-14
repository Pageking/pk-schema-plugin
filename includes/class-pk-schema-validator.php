<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controleert gebouwde schema-arrays tegen Google's eigen gedocumenteerde
 * verplichte/aanbevolen velden per type (Search Central richtlijnen). Dit
 * werkt volledig lokaal — geen publiek bereikbare URL nodig, in tegenstelling
 * tot Google's eigen Rich Results Test.
 *
 * Alleen de types waar Google specifieke rich-result-richtlijnen voor
 * publiceert (Article, Product, JobPosting, FAQPage) worden hier strikt
 * gecontroleerd. Voor de overige types (WebPage, Service, Person,
 * Organization) bestaat geen apart Google rich-result-format — die geven we
 * terug als "niet gecontroleerd" i.p.v. een schijnzekerheid te suggereren.
 */
class PK_Schema_Validator {

    const RULES = array(
        'Article' => array(
            'required'    => array('headline', 'image', 'author', 'datePublished'),
            'recommended' => array('dateModified'),
        ),
        'Product' => array(
            'required'     => array('name'),
            'required_any' => array('offers', 'review', 'aggregateRating'),
            'recommended'  => array('image', 'description', 'sku', 'brand'),
        ),
        'JobPosting' => array(
            'required'     => array('title', 'description', 'datePosted', 'hiringOrganization'),
            'required_any' => array('jobLocation', 'jobLocationType'),
            'recommended'  => array('validThrough', 'employmentType', 'baseSalary'),
        ),
        'FAQPage' => array(
            'required'    => array('mainEntity'),
            'recommended' => array(),
        ),
    );

    /**
     * @return array{type: string|null, checked: bool, valid?: bool, missing_required?: array, missing_recommended?: array, note?: string}
     */
    public function validate(array $schema) {
        $type = isset($schema['@type']) ? $schema['@type'] : null;

        if (!$type || !isset(self::RULES[$type])) {
            return array(
                'type'    => $type,
                'checked' => false,
                'note'    => 'Google publiceert geen specifieke rich-result-richtlijnen voor dit type — alleen aanwezigheid van basisvelden wordt door de builder zelf gegarandeerd, niet hier apart getoetst.',
            );
        }

        $rules = self::RULES[$type];
        $missing_required = array();
        $missing_recommended = array();

        foreach ($rules['required'] ?? array() as $field) {
            if (empty($schema[$field])) {
                $missing_required[] = $field;
            }
        }

        if (!empty($rules['required_any'])) {
            $has_any = false;

            foreach ($rules['required_any'] as $field) {
                if (!empty($schema[$field])) {
                    $has_any = true;
                    break;
                }
            }

            if (!$has_any) {
                $missing_required[] = 'minstens één van: ' . implode(', ', $rules['required_any']);
            }
        }

        foreach ($rules['recommended'] ?? array() as $field) {
            if (empty($schema[$field])) {
                $missing_recommended[] = $field;
            }
        }

        return array(
            'type'                => $type,
            'checked'             => true,
            'valid'               => empty($missing_required),
            'missing_required'    => $missing_required,
            'missing_recommended' => $missing_recommended,
        );
    }
}
