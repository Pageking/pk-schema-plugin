<?php
if (!defined('ABSPATH')) {
    exit;
}

class PK_Schema_Builder_Service extends PK_Schema_Builder_Base {

    public function build($post, array $data) {
        return array(
            '@type'       => 'Service',
            'name'        => $data['core']['post_title'],
            'description' => $this->get_description($data),
            'url'         => $data['core']['permalink'],
            'provider'    => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
            ),
        );
    }
}
