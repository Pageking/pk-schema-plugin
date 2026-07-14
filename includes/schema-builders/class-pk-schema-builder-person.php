<?php
if (!defined('ABSPATH')) {
    exit;
}

class PK_Schema_Builder_Person extends PK_Schema_Builder_Base {

    public function build($post, array $data) {
        $schema = array(
            '@type' => 'Person',
            'name'  => $data['core']['post_title'],
            'url'   => $data['core']['permalink'],
        );

        $image = $this->get_image_url($data);
        if ($image) {
            $schema['image'] = $image;
        }

        $job_title = $this->resolve_concept($post, $data, 'job_title', array('functie', 'job_title', 'jobtitle'));
        if ($job_title) {
            $schema['jobTitle'] = $job_title;
        }

        return $schema;
    }
}
