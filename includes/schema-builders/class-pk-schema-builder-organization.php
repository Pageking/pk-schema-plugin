<?php
if (!defined('ABSPATH')) {
    exit;
}

class PK_Schema_Builder_Organization extends PK_Schema_Builder_Base {

    public function build($post, array $data) {
        $schema = array(
            '@type' => 'Organization',
            'name'  => $data['core']['post_title'],
            'url'   => $data['core']['permalink'],
        );

        $image = $this->get_image_url($data);
        if ($image) {
            $schema['logo'] = $image;
        }

        return $schema;
    }
}
