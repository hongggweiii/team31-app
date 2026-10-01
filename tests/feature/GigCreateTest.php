<?php

namespace Tests\Feature;

use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class GigCreateTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The taxonomy tables come from the legacy DB and have no migration.
        $forge = Database::forge();

        foreach (['categories', 'specialities'] as $table) {
            $forge->addField([
                'id'     => ['type' => 'int', 'auto_increment' => true],
                'name'   => ['type' => 'varchar', 'constraint' => 255],
                'status' => ['type' => 'varchar', 'constraint' => 1, 'default' => '0'],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable($table, true);
            $this->db->table($table)->insertBatch([
                ['id' => 1, 'name' => 'Finance', 'status' => '1'],
                ['id' => 2, 'name' => 'Retired', 'status' => '0'],
            ]);
        }

        // CSRF is covered by the framework; keep these requests token-free.
        config('Filters')->globals['before'] = [];
    }

    protected function tearDown(): void
    {
        $forge = Database::forge();
        $forge->dropTable('categories', true);
        $forge->dropTable('specialities', true);

        parent::tearDown();
    }

    private function validInput(): array
    {
        return [
            'gig_title'       => 'Interim CFO',
            'gig_summary'     => 'Lead finance for six months.',
            'budget_min'      => '5000',
            'budget_max'      => '10000',
            'seniority'       => 'senior',
            'start_date'      => '2026-11-01',
            'categories'      => ['1'],
            'specialisations' => ['1'],
        ];
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->get('create-gig')->assertRedirectTo(url_to('login'));
        $this->post('create-gig', $this->validInput())->assertRedirectTo(url_to('login'));
        $this->dontSeeInDatabase('gigs', ['title' => 'Interim CFO']);
    }

    public function testLoggedInUserSeesForm(): void
    {
        $user = fake(UserModel::class);

        $result = $this->actingAs($user)->get('create-gig');

        $result->assertOK();
        $result->assertSee('Seniority Needed');
        $result->assertSee('Finance');
        $result->assertDontSee('Retired');
    }

    public function testCreatesGigWithTaxonomyLinks(): void
    {
        $user = fake(UserModel::class);

        $result = $this->actingAs($user)->post('create-gig', $this->validInput());

        $result->assertRedirectTo(url_to('gig-new'));
        $result->assertSessionHas('message');

        $gig = (new GigModel())->where('title', 'Interim CFO')->first();
        $this->assertNotNull($gig);
        $this->assertSame((string) $user->id, (string) $gig['user_id']);
        $this->assertSame('senior', $gig['seniority']);
        $this->assertSame('2026-11-01', $gig['start_date']);
        $this->seeInDatabase('gig_categories', ['gig_id' => $gig['id'], 'category_id' => 1]);
        $this->seeInDatabase('gig_specialities', ['gig_id' => $gig['id'], 'speciality_id' => 1]);
    }

    public function testRejectsInvalidInput(): void
    {
        $user = fake(UserModel::class);

        $cases = [
            'missing title'       => ['gig_title' => ''],
            'inactive category'   => ['categories' => ['2']],
            'no specialisation'   => ['specialisations' => null],
            'bad seniority'       => ['seniority' => 'Wizard'],
            'budget min over max' => ['budget_min' => '20000'],
        ];

        foreach ($cases as $name => $override) {
            $input = array_filter(array_merge($this->validInput(), $override), static fn ($v) => $v !== null);

            $this->actingAs($user)->post('create-gig', $input)->assertRedirect();
            $this->assertSame(0, $this->db->table('gigs')->countAllResults(), $name);
        }
    }
}
