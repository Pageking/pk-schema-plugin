<?php
if (!defined('ABSPATH')) {
    exit;
}

class PK_Schema_Builder_Article extends PK_Schema_Builder_Base {

    public function build($post, array $data) {
        $schema = array(
            '@type'            => 'Article',
            'headline'         => $data['core']['post_title'],
            'description'      => $this->get_description($data),
            'mainEntityOfPage' => $data['core']['permalink'],
            'author'           => array(
                '@type' => 'Person',
                'name'  => $data['core']['author']['name'],
            ),
        );

        $date_published = $this->to_iso8601($data['core']['post_date']);
        if ($date_published) {
            $schema['datePublished'] = $date_published;
        }

        $date_modified = $this->to_iso8601($data['core']['post_modified']);
        if ($date_modified) {
            $schema['dateModified'] = $date_modified;
        }

        $image = $this->get_image_url($data);
        if ($image) {
            $schema['image'] = $image;
        }

        $language = $this->get_language($data);
        if ($language) {
            $schema['inLanguage'] = $language;
        }

        return $schema;
    }
}
