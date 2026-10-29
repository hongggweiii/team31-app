<?php

use App\Libraries\Taxonomy;
use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\TaxonomyFixtureTrait;

/**
 * App\Libraries\Taxonomy: the picker tree, the schema v2 caps, saved tags in
 * the records' shape, and importing.
 *
 * @internal
 */
final class TaxonomyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TaxonomyFixtureTrait;

    protected $namespace = null; // App and Shield migrations: gigs.user_id references users
    protected $refresh   = true;

    private Taxonomy $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadTaxonomy();
        $this->taxonomy = new Taxonomy($this->db);
    }

    public function testTreeNestsSpecialisationsAndSkillsByName(): void
    {
        $tree = $this->taxonomy->tree();

        $this->assertSame(['Accountancy', 'Financial Services', 'Infocomm Technology'], array_column($tree, 'name'));
        $this->assertSame(['Assurance', 'Management Accounting', 'Project Management', 'Tax'], array_column($tree[0]['specialisations'], 'name'));
        $this->assertSame('Assurance (Accountancy)', $tree[0]['specialisations'][0]['search_tag']);
        $this->assertSame(['Audit Compliance', 'Financial Statements Analysis'], array_values($tree[0]['specialisations'][0]['skills']));
        $this->assertSame([], $tree[0]['specialisations'][1]['skills']);
        $this->assertSame('Project Management (Financial Services)', $tree[1]['specialisations'][1]['search_tag']);
    }

    public function testAcceptsAValidSelection(): void
    {
        $assurance = $this->specId('Accountancy', 'Assurance');
        $analytics = $this->specId('Financial Services', 'Digital and Data Analytics');

        $result = $this->taxonomy->check(
            [(string) $this->catId('Accountancy'), (string) $this->catId('Financial Services')],
            [(string) $assurance, (string) $analytics, (string) $assurance],
            [
                $assurance => [(string) $this->skillId('Audit Compliance')],
                $analytics => [],
                // A specialisation that wasn't ticked: ignored.
                $this->specId('Accountancy', 'Tax') => [(string) $this->skillId('Tax Computation')],
            ],
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            'specialisations' => [$assurance, $analytics],
            'skills'          => [$assurance => [$this->skillId('Audit Compliance')]],
        ], $result['selection']);
    }

    /**
     * @dataProvider provideInvalidSelections
     */
    public function testRejectsInvalidSelections(Closure $selection, string $field): void
    {
        [$categories, $specialisations, $skills] = $selection($this);

        $this->assertArrayHasKey($field, $this->taxonomy->check($categories, $specialisations, $skills)['errors']);
    }

    public static function provideInvalidSelections(): iterable
    {
        return [
            'no categories' => [static fn (self $t): array => [[], [$t->specId('Accountancy', 'Tax')], []], 'categories'],
            'three categories' => [static fn (self $t): array => [
                [$t->catId('Accountancy'), $t->catId('Financial Services'), $t->catId('Infocomm Technology')],
                [$t->specId('Accountancy', 'Tax'), $t->specId('Financial Services', 'Project Management'), $t->specId('Infocomm Technology', 'Data and Artificial Intelligence')],
                [],
            ], 'categories'],
            'unknown category' => [static fn (self $t): array => [[999], [$t->specId('Accountancy', 'Tax')], []], 'categories'],
            'no specialisations' => [static fn (self $t): array => [[$t->catId('Accountancy')], [], []], 'specialisations'],
            'unknown specialisation' => [static fn (self $t): array => [[$t->catId('Accountancy')], [999], []], 'specialisations'],
            'specialisation outside the categories' => [static fn (self $t): array => [
                [$t->catId('Accountancy')], [$t->specId('Accountancy', 'Tax'), $t->specId('Financial Services', 'Project Management')], [],
            ], 'specialisations'],
            'category without a specialisation' => [static fn (self $t): array => [
                [$t->catId('Accountancy'), $t->catId('Financial Services')], [$t->specId('Accountancy', 'Tax')], [],
            ], 'specialisations'],
            'four specialisations in one category' => [static fn (self $t): array => [
                [$t->catId('Accountancy')],
                array_map(static fn (string $s): int => $t->specId('Accountancy', $s), ['Assurance', 'Management Accounting', 'Project Management', 'Tax']),
                [],
            ], 'specialisations'],
            'skill not linked to the specialisation' => [static fn (self $t): array => [
                [$t->catId('Accountancy')], [$t->specId('Accountancy', 'Tax')], [$t->specId('Accountancy', 'Tax') => [$t->skillId('Audit Compliance')]],
            ], 'skills'],
            'nine skills' => [static fn (self $t): array => [
                [$t->catId('Financial Services')],
                [$t->specId('Financial Services', 'Digital and Data Analytics')],
                [$t->specId('Financial Services', 'Digital and Data Analytics') => array_map($t->skillId(...), self::ANALYTICS_SKILLS)],
            ], 'skills'],
        ];
    }

    public function testTagsAreInTheRecordsShape(): void
    {
        $gigs  = new GigModel();
        $gigId = $gigs->createWithTaxonomy(
            ['user_id' => fake(UserModel::class)->id, 'title' => 'Audit', 'summary' => 'Year-end audit.'],
            [
                'specialisations' => [$this->specId('Financial Services', 'Project Management'), $this->specId('Accountancy', 'Tax'), $this->specId('Accountancy', 'Assurance')],
                'skills'          => [$this->specId('Accountancy', 'Assurance') => [$this->skillId('Financial Statements Analysis'), $this->skillId('Audit Compliance')]],
            ],
        );

        $this->assertSame([
            ['name' => 'Accountancy', 'group' => null, 'specialisations' => [
                ['name' => 'Assurance', 'search_tag' => 'Assurance (Accountancy)', 'skills' => ['Audit Compliance', 'Financial Statements Analysis']],
                ['name' => 'Tax', 'search_tag' => 'Tax (Accountancy)', 'skills' => []],
            ]],
            ['name' => 'Financial Services', 'group' => null, 'specialisations' => [
                ['name' => 'Project Management', 'search_tag' => 'Project Management (Financial Services)', 'skills' => []],
            ]],
        ], $gigs->rankerTaxonomy($gigId));
    }

    public function testCachedTreeIsClearedByImport(): void
    {
        $this->taxonomy->forgetTree();
        $this->assertSame($this->taxonomy->tree(), $this->taxonomy->cachedTree());

        $this->taxonomy->import([['name' => 'Agriculture', 'specialisations' => [['name' => 'Agronomy', 'skills' => []]]]]);

        $this->assertContains('Agriculture', array_column($this->taxonomy->cachedTree(), 'name'));
    }

    public function testImportIsAdditiveAndFillsInGroups(): void
    {
        $before = $this->taxonomy->tree();

        $added = $this->taxonomy->import([
            ['name' => 'Accountancy', 'group' => 'Function', 'specialisations' => [
                ['name' => 'Assurance', 'skills' => ['Audit Compliance', 'Forensic Accounting']],
            ]],
        ]);

        $this->assertSame(['categories' => 0, 'specialisations' => 0, 'skills' => 1, 'links' => 1], $added);
        $this->assertSame('Function', $this->db->table('sf_categories')->where('name', 'Accountancy')->get()->getRow()->group_name);
        $this->assertCount(count($before[0]['specialisations']), $this->taxonomy->tree()[0]['specialisations']); // nothing removed
    }
}
