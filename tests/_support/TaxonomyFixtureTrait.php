<?php

namespace Tests\Support;

use App\Libraries\Taxonomy;

/**
 * Loads a small SkillsFuture taxonomy into the sf_* tables:
 *
 *   Accountancy         Assurance (Audit Compliance, Financial Statements Analysis),
 *                       Management Accounting (no skills), Project Management (Stakeholder Management),
 *                       Tax (Tax Computation)
 *   Financial Services  Digital and Data Analytics (9 skills, one over the cap),
 *                       Project Management (Stakeholder Management) -- a name shared with Accountancy
 *   Infocomm Technology Data and Artificial Intelligence (Cloud Computing, Data Analytics)
 *
 * Ids are auto-increment, so look them up with catId() / specId() / skillId().
 * Call loadTaxonomy() from setUp() of a DatabaseTestTrait test.
 */
trait TaxonomyFixtureTrait
{
    /** Nine skills: one more than Taxonomy::MAX_SKILLS. */
    protected const ANALYTICS_SKILLS = [
        'Business Process Re-engineering', 'Cloud Computing', 'Customer Experience Management', 'Data Analytics',
        'Emerging Technology Synthesis', 'Product Design and Development', 'Risk Management', 'Stakeholder Management',
        'Technology Application',
    ];

    protected function loadTaxonomy(): void
    {
        (new Taxonomy($this->db))->import([
            ['name' => 'Accountancy', 'specialisations' => [
                ['name' => 'Assurance', 'skills' => ['Audit Compliance', 'Financial Statements Analysis']],
                ['name' => 'Management Accounting', 'skills' => []],
                ['name' => 'Project Management', 'skills' => ['Stakeholder Management']],
                ['name' => 'Tax', 'skills' => ['Tax Computation']],
            ]],
            ['name' => 'Financial Services', 'specialisations' => [
                ['name' => 'Digital and Data Analytics', 'skills' => self::ANALYTICS_SKILLS],
                ['name' => 'Project Management', 'skills' => ['Stakeholder Management']],
            ]],
            ['name' => 'Infocomm Technology', 'specialisations' => [
                ['name' => 'Data and Artificial Intelligence', 'skills' => ['Cloud Computing', 'Data Analytics']],
            ]],
        ]);
    }

    protected function catId(string $name): int
    {
        return (int) $this->db->table('sf_categories')->where('name', $name)->get()->getRow()->id;
    }

    protected function specId(string $category, string $name): int
    {
        return (int) $this->db->table('sf_specialisations')
            ->where(['category_id' => $this->catId($category), 'name' => $name])->get()->getRow()->id;
    }

    protected function skillId(string $name): int
    {
        return (int) $this->db->table('sf_skills')->where('name', $name)->get()->getRow()->id;
    }
}
