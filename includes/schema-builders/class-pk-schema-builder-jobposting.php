<?php
if (!defined('ABSPATH')) {
    exit;
}

class PK_Schema_Builder_JobPosting extends PK_Schema_Builder_Base {

    public function build($post, array $data) {
        $description = !empty($data['core']['post_content']) ? $data['core']['post_content'] : $this->get_description($data);

        $schema = array(
            '@type'              => 'JobPosting',
            'title'              => $data['core']['post_title'],
            'description'        => $description,
            'hiringOrganization' => array(
                '@type'  => 'Organization',
                'name'   => get_bloginfo('name'),
                'sameAs' => home_url('/'),
            ),
        );

        $date_posted = $this->to_iso8601($data['core']['post_date']);
        if ($date_posted) {
            $schema['datePosted'] = $date_posted;
        }

        $valid_through = $this->resolve_concept($post, $data, 'valid_through', array('sluitingsdatum', 'closing_date', 'valid_through'));
        if ($valid_through) {
            $schema['validThrough'] = $this->format_acf_date($valid_through);
        }

        $employment_type = $this->map_employment_type(
            $this->resolve_concept($post, $data, 'employment_type', array('dienstverband', 'employment_type'))
        );
        if ($employment_type) {
            $schema['employmentType'] = $employment_type;
        }

        $location = $this->resolve_concept($post, $data, 'location', array('locatie', 'location'));
        if ($location) {
            $schema['jobLocation'] = array(
                '@type'   => 'Place',
                'address' => array(
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $location,
                ),
            );
        }

        $salary_raw = $this->resolve_concept($post, $data, 'salary', array('salaris', 'salarisindicatie', 'salary'));
        if ($salary_raw) {
            $base_salary = $this->parse_salary($salary_raw);
            if ($base_salary) {
                $schema['baseSalary'] = $base_salary;
            }
        }

        $work_hours = $this->resolve_concept($post, $data, 'work_hours', array('uren_per_week', 'werkuren', 'work_hours'));
        if ($work_hours) {
            // Schema.org's workHours is vrije tekst — anders dan baseSalary hoeft
            // dit niet numeriek gestructureerd te worden.
            $schema['workHours'] = is_numeric($work_hours)
                ? sprintf('%s uur per week', $work_hours)
                : (string) $work_hours;
        }

        // Deze twee hebben zelden een voor de hand liggende ACF-veldnaam om te
        // gokken — functie-eisen/taken staan doorgaans als losse bullets in een
        // WYSIWYG-veld (vaak binnen een repeater), niet in een los tekstveld.
        // Dat maakt ze de belangrijkste kandidaten voor de AI-herkenningslaag.
        $qualifications = $this->resolve_concept($post, $data, 'qualifications', array('functie_eisen', 'eisen', 'qualifications'));
        if ($qualifications) {
            $schema['qualifications'] = $this->clean_text($qualifications);
        }

        $responsibilities = $this->resolve_concept($post, $data, 'responsibilities', array('taken', 'verantwoordelijkheden', 'responsibilities'));
        if ($responsibilities) {
            $schema['responsibilities'] = $this->clean_text($responsibilities);
        }

        return $schema;
    }

    /**
     * ACF datepicker-waardes komen als 'Ymd' (bv. '20260815') binnen via de
     * collector — Schema.org verwacht ISO 8601 ('2026-08-15').
     */
    private function format_acf_date($value) {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $value;
    }

    private function map_employment_type($value) {
        $map = array(
            'fulltime' => 'FULL_TIME',
            'parttime' => 'PART_TIME',
            'stage'    => 'INTERN',
            'zzp'      => 'CONTRACTOR',
        );

        return isset($map[$value]) ? $map[$value] : null;
    }

    /**
     * Salarisindicatie-velden zijn vaak vrije tekst (bv. '€ 3.500 - € 4.500 per
     * maand'), niet gestructureerd. We proberen er best-effort een geldig
     * MonetaryAmount uit te halen; lukt dat niet, dan laten we baseSalary
     * gewoon weg — ongeldige structured data is erger dan geen data.
     */
    private function parse_salary($raw) {
        // Duizendtal-punten weghalen zodat '3.500' -> 3500 wordt, niet 3.5.
        $normalized = preg_replace('/(?<=\d)\.(?=\d{3}\b)/', '', $raw);
        preg_match_all('/\d+(?:[.,]\d+)?/', $normalized, $matches);

        $numbers = array_map(function ($n) {
            return (float) str_replace(',', '.', $n);
        }, $matches[0]);

        if (empty($numbers)) {
            return null;
        }

        $unit_text = (stripos($raw, 'jaar') !== false || stripos($raw, 'year') !== false) ? 'YEAR' : 'MONTH';

        if (count($numbers) >= 2) {
            return array(
                '@type'    => 'MonetaryAmount',
                'currency' => 'EUR',
                'value'    => array(
                    '@type'    => 'QuantitativeValue',
                    'minValue' => min($numbers[0], $numbers[1]),
                    'maxValue' => max($numbers[0], $numbers[1]),
                    'unitText' => $unit_text,
                ),
            );
        }

        return array(
            '@type'    => 'MonetaryAmount',
            'currency' => 'EUR',
            'value'    => array(
                '@type'    => 'QuantitativeValue',
                'value'    => $numbers[0],
                'unitText' => $unit_text,
            ),
        );
    }
}
