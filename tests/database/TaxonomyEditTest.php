<?php

use App\Libraries\Taxonomy;
use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\TaxonomyFixtureTrait;

/**
 * App\Libraries\Taxonomy's edits for the taxonomy dashboard: add, rename,
 * remove only what nothing uses, and usage counts.
 *
 * @internal
 */
final class TaxonomyEditTest extends CIUnitTestCase
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

    public function testAddsACategoryASpecialisationAndASkill(): void
    {
        $category = $this->taxonomy->add('category', '  Legal   Services ');
        $spec     = $this->taxonomy->add('specialisation', 'Contracts', $category);
        $this->taxonomy->add('skill', 'Contract Drafting', $spec);

        $tree = array_column($this->taxonomy->tree(), null, 'name');
        $this->assertSame('Contracts', $tree['Legal Services']['specialisations'][0]['name']);
        $this->assertSame(['Contract Drafting'], array_values($tree['Legal Services']['specialisations'][0]['skills']));
    }

    public function testLinksAnExistingSkillInsteadOfDuplicatingIt(): void
    {
        $skills = $this->db->table('sf_skills')->countAllResults();

        $id = $this->taxonomy->add('skill', 'data analytics', $this->specId('Accountancy', 'Tax'));

        $this->assertSame($this->skillId('Data Analytics'), $id);
        $this->assertSame($skills, $this->db->table('sf_skills')->countAllResults());
        $this->seeInDatabase('sf_specialisation_skills', ['specialisation_id' => $this->specId('Accountancy', 'Tax'), 'skill_id' => $id]);
    }

    /**
     * @dataProvider provideRefusedAdds
     */
    public function testRefusesAnAddThatClashesOrHasNoParent(string $kind, string $name, ?array $parent, string $message): void
    {
        $parentId = match ($parent[0] ?? null) {
            'category'       => $this->catId($parent[1]),
            'specialisation' => $this->specId($parent[1], $parent[2]),
            default          => null,
        };

        try {
            $this->taxonomy->add($kind, $name, $parentId);
            $this->fail('Expected the add to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame(0, $this->db->transDepth, 'a refused edit leaves no transaction open');
    }

    public static function provideRefusedAdds(): iterable
    {
        yield 'category, any case' => ['category', 'accountancy', null, 'exists already'];
        yield 'specialisation in its category' => ['specialisation', 'ASSURANCE', ['category', 'Accountancy'], 'exists already'];
        yield 'skill already linked' => ['skill', 'Audit Compliance', ['specialisation', 'Accountancy', 'Assurance'], 'already a skill of that specialisation'];
        yield 'specialisation without a category' => ['specialisation', 'Contracts', null, 'Choose a category'];
        yield 'blank name' => ['category', '   ', null, 'name of 1 to 150 characters'];
    }

    public function testRenamesWithinTheSameUniqueness(): void
    {
        $this->taxonomy->rename('skill', $this->skillId('Tax Computation'), 'Tax Computations');
        $this->seeInDatabase('sf_skills', ['name' => 'Tax Computations']);

        // The same specialisation name may sit under another category, not the same one.
        $this->taxonomy->rename('specialisation', $this->specId('Accountancy', 'Tax'), 'Digital and Data Analytics');
        $this->expectExceptionMessage('exists already');
        $this->taxonomy->rename('specialisation', $this->specId('Accountancy', 'Assurance'), 'project management');
    }

    public function testRemovesOnlyWhatNothingUses(): void
    {
        $tax = $this->specId('Accountancy', 'Tax');
        (new GigModel())->createWithTaxonomy(
            ['user_id' => fake(UserModel::class)->id, 'title' => 'Year-end tax', 'summary' => 'Prepare our corporate tax computation.'],
            ['specialisations' => [$tax], 'skills' => [$tax => [$this->skillId('Tax Computation')]]],
        );

        $refusals = [
            ['specialisation', $tax, null, 'used by 1 gig(s)'],
            ['skill', $this->skillId('Tax Computation'), $tax, 'used by 1 gig(s)'],
            ['category', $this->catId('Accountancy'), null, 'Remove its specialisations first'],
        ];
        foreach ($refusals as [$kind, $id, $spec, $message]) {
            try {
                $this->taxonomy->remove($kind, $id, $spec);
                $this->fail("Expected removing the {$kind} to be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->taxonomy->remove('specialisation', $this->specId('Accountancy', 'Management Accounting'));
        $this->dontSeeInDatabase('sf_specialisations', ['name' => 'Management Accounting']);

        // Linked only under Assurance: the skill goes. Cloud Computing is also under Digital and Data Analytics: it stays.
        $analysis = $this->skillId('Financial Statements Analysis');
        $this->taxonomy->remove('skill', $analysis, $this->specId('Accountancy', 'Assurance'));
        $this->dontSeeInDatabase('sf_skills', ['id' => $analysis]);
        $cloud = $this->skillId('Cloud Computing');
        $this->taxonomy->remove('skill', $cloud, $this->specId('Infocomm Technology', 'Data and Artificial Intelligence'));
        $this->seeInDatabase('sf_skills', ['id' => $cloud]);
        $this->dontSeeInDatabase('sf_specialisation_skills', ['specialisation_id' => $this->specId('Infocomm Technology', 'Data and Artificial Intelligence'), 'skill_id' => $cloud]);
    }

    public function testAPendingProposalUnderASpecialisationBlocksItsRemoval(): void
    {
        $spec = $this->specId('Accountancy', 'Management Accounting');
        $this->db->table('taxonomy_proposals')->insert([
            'proposed_name' => 'Activity-Based Costing', 'kind' => 'skill', 'category_id' => $this->catId('Accountancy'),
            'specialisation_id' => $spec, 'submitted_by_source' => 'ai_suggestion',
        ]);

        $this->expectExceptionMessage('Pending proposals are waiting under it');
        $this->taxonomy->remove('specialisation', $spec);
    }

    public function testCountsUsageAcrossGigsAndShowcases(): void
    {
        $assurance = $this->specId('Accountancy', 'Assurance');
        $audit     = $this->skillId('Audit Compliance');
        $userId    = fake(UserModel::class)->id;
        foreach (['First audit', 'Second audit'] as $title) {
            (new GigModel())->createWithTaxonomy(['user_id' => $userId, 'title' => $title, 'summary' => 'Audit us.'],
                ['specialisations' => [$assurance], 'skills' => [$assurance => [$audit]]]);
        }

        $usage = $this->taxonomy->usage();

        $this->assertSame([$assurance => 2], $usage['specialisations']);
        $this->assertSame(["{$assurance}:{$audit}" => 2], $usage['skills']);
    }
}
