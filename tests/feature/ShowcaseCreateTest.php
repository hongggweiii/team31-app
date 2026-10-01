<?php

namespace Tests\Feature;

use App\Models\ShowcaseModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class ShowcaseCreateTest extends CIUnitTestCase
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
        // Category 1 has specialisations 10 and 11; category 3 has none.
        $forge = Database::forge();

        $forge->addField([
            'id'     => ['type' => 'int', 'auto_increment' => true],
            'name'   => ['type' => 'varchar', 'constraint' => 255],
            'status' => ['type' => 'varchar', 'constraint' => 1, 'default' => '0'],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('categories', true);
        $this->db->table('categories')->insertBatch([
            ['id' => 1, 'name' => 'Finance', 'status' => '1'],
            ['id' => 2, 'name' => 'Retired', 'status' => '0'],
            ['id' => 3, 'name' => 'Fund Raising', 'status' => '1'],
        ]);

        $forge->addField([
            'id'          => ['type' => 'int', 'auto_increment' => true],
            'name'        => ['type' => 'varchar', 'constraint' => 255],
            'category_id' => ['type' => 'int'],
            'status'      => ['type' => 'varchar', 'constraint' => 1, 'default' => '0'],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('specialities', true);
        $this->db->table('specialities')->insertBatch([
            ['id' => 10, 'name' => 'Due Diligence', 'category_id' => 1, 'status' => '1'],
            ['id' => 11, 'name' => 'Valuation', 'category_id' => 1, 'status' => '1'],
            ['id' => 12, 'name' => 'Old Audit', 'category_id' => 1, 'status' => '0'],
        ]);

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
            'service_category'      => '1',
            'specialisation'        => '10',
            'title'                 => 'Financial Due Diligence',
            'years'                 => '15',
            'seniority'             => 'expert',
            'credentials'           => '<p>CFA, <strong>CPA</strong></p>',
            'relevant_achievements' => '<ol><li data-list="bullet">Advised banks</li></ol>',
            'headline'              => 'Fractional CFO | Strategy & Finance Expert',
            'about'                 => '<p onclick="steal()">I drive transformation.</p><script>alert(1)</script>',
            'services'              => [
                ['title' => 'Due diligence', 'detail' => '<p>Buy-side reviews</p>'],
                ['title' => 'Valuation', 'detail' => '<p><br></p>'],
            ],
            'how_i_work'            => '<p>Remote first.</p>',
            'locations'             => ['Virtual Only', 'Raffles Place, Marina, Cecil'],
            'available_from'        => '2026-11-19',
            'capacity'              => '2',
            'availability'          => '',
            'rate_per_hour'         => '215',
            'rate_notes'            => '',
            'search_tags'           => ['M&A', ' m&a ', 'valuation'],
            'terms'                 => '1',
        ];
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        // Shield's auth service is shared, so drop any user left by earlier tests.
        auth()->logout();

        $this->get('create-profile')->assertRedirectTo('/login');
    }

    public function testLoggedInUserSeesForm(): void
    {
        $user = fake(UserModel::class);

        $result = $this->actingAs($user)->get('create-profile');

        $result->assertOK();
        $result->assertSee('Create your Showcase');
        $result->assertSee('Due Diligence'); // specialisation map
        $result->assertDontSee('Retired');   // inactive category
        $result->assertDontSee('family_name');
    }

    public function testCreatesShowcaseWithServices(): void
    {
        $user = fake(UserModel::class);

        $result = $this->actingAs($user)->post('create-profile', $this->validInput());

        $result->assertRedirectTo(url_to('showcase-new'));
        $result->assertSessionHas('message', 'Your showcase has been published.');

        $showcase = (new ShowcaseModel())->first();
        $this->assertSame((int) $user->id, (int) $showcase['user_id']);
        $this->assertSame(1, (int) $showcase['category_id']);
        $this->assertSame(10, (int) $showcase['speciality_id']);
        $this->assertSame('expert', $showcase['seniority']);
        $this->assertSame(215, (int) $showcase['rate_per_hour']);
        $this->assertSame('2026-11-19', $showcase['available_from']);
        $this->assertFalse($showcase['available_now']);
        $this->assertSame(['Virtual Only', 'Raffles Place, Marina, Cecil'], $showcase['locations']);
        $this->assertSame(['M&A', 'valuation'], $showcase['search_tags']);
        $this->assertSame('<p>I drive transformation.</p>', $showcase['about']);
        $this->assertNull($showcase['availability_notes']);

        $services = $this->db->table('showcase_services')->orderBy('sort_order')->get()->getResultArray();
        $this->assertSame(['Due diligence', 'Valuation'], array_column($services, 'title'));
        $this->assertSame('<p>Buy-side reviews</p>', $services[0]['description']);
        $this->assertNull($services[1]['description']);
    }

    public function testAvailableNowAndCategoryWithoutSpecialisations(): void
    {
        $user  = fake(UserModel::class);
        $input = ['service_category' => '3', 'available_now' => '1'] + $this->validInput();
        unset($input['specialisation'], $input['available_from']);

        $this->actingAs($user)->post('create-profile', $input)->assertRedirectTo(url_to('showcase-new'));

        $showcase = (new ShowcaseModel())->first();
        $this->assertTrue($showcase['available_now']);
        $this->assertNull($showcase['available_from']);
        $this->assertNull($showcase['speciality_id']);
    }

    /**
     * @dataProvider provideInvalidInput
     */
    public function testRejectsInvalidInput(array $override, array $remove = []): void
    {
        $user  = fake(UserModel::class);
        $input = array_merge($this->validInput(), $override);
        foreach ($remove as $field) {
            unset($input[$field]);
        }

        $result = $this->actingAs($user)->post('create-profile', $input);

        $result->assertRedirect();
        $result->assertSessionMissing('message');
        $this->seeNumRecords(0, 'showcases', []);
    }

    public static function provideInvalidInput(): iterable
    {
        return [
            'inactive category'             => [['service_category' => '2']],
            'specialisation of other cat'   => [['specialisation' => '99']],
            'inactive specialisation'       => [['specialisation' => '12']],
            'missing specialisation'        => [[], ['specialisation']],
            'blank credentials editor'      => [['credentials' => '<p><br></p>']],
            'only a script in bio'          => [['about' => '<script>alert(1)</script>']],
            'bad seniority'                 => [['seniority' => 'Executive']],
            'zero rate'                     => [['rate_per_hour' => '0']],
            'decimal rate'                  => [['rate_per_hour' => '99.5']],
            'capacity above 5'              => [['capacity' => '6']],
            'no date and not available now' => [[], ['available_from']],
            'no services'                   => [[], ['services']],
            'service without title'         => [['services' => [['title' => '', 'detail' => '<p>x</p>']]]],
            'unknown location'              => [['locations' => ['Mars']]],
            'terms not accepted'            => [[], ['terms']],
        ];
    }
}
