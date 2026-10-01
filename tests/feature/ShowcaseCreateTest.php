<?php

namespace Tests\Feature;

use App\Models\ShowcaseModel;
use App\Models\SpecialityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\LegacyTaxonomyTrait;

/**
 * @internal
 */
final class ShowcaseCreateTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use LegacyTaxonomyTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyTaxonomy();

        // CSRF is covered by the framework; keep these requests token-free.
        config('Filters')->globals['before'] = [];
    }

    protected function tearDown(): void
    {
        $this->dropLegacyTaxonomy();

        parent::tearDown();
    }

    private function validInput(): array
    {
        return [
            'name'            => '  Hong Wei Tai ',
            'title'           => 'Financial Due Diligence',
            'credentials'     => '<p>CFA, <strong>CPA</strong></p>',
            'headline'        => 'Fractional CFO | Strategy & Finance Expert',
            'about'           => '<p onclick="steal()">I drive transformation.</p><script>alert(1)</script>',
            'categories'      => ['1', '3'],
            'specialisations' => ['10', '20', '10'],
            'availability'    => '<p><br></p>',
            'rate'            => '<p>S$250 per hour</p>',
            'services'        => [
                ['title' => 'Due diligence', 'detail' => '<p>Buy-side reviews</p>'],
                ['title' => 'Valuation', 'detail' => '<p><br></p>'],
            ],
            'achievements'    => ['<p>Advised banks</p>', '<p><br></p>', '<p>Led a S$50m raise</p>'],
            'skill_groups'    => [
                ['name' => 'Data & Analytics', 'skills' => ['Python', ' python ', 'Tableau']],
                ['name' => '', 'skills' => []], // untouched box, dropped
            ],
            'how_i_work'      => '',
            'terms'           => '1',
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
        $result = $this->actingAs(fake(UserModel::class))->get('create-profile');

        $result->assertOK();
        $result->assertSee('Create your Showcase');
        $result->assertSee('Industry');                 // category group heading
        $result->assertSee('Due Diligence (Finance)');  // specialisation labelled as its search tag
        $result->assertDontSee('Retired');              // inactive category
        $result->assertDontSee('Old Audit');            // inactive specialisation
    }

    public function testCreatesShowcaseWithChildren(): void
    {
        $user = fake(UserModel::class);

        $result = $this->actingAs($user)->post('create-profile', $this->validInput());

        $result->assertRedirectTo(url_to('showcase-new'));
        $result->assertSessionHas('message', 'Your showcase has been published.');

        $showcase = (new ShowcaseModel())->first();
        $id       = $showcase['id'];
        $this->assertSame((int) $user->id, (int) $showcase['user_id']);
        $this->assertSame('Hong Wei Tai', $showcase['name']);
        $this->assertSame('<p>I drive transformation.</p>', $showcase['about']);
        $this->assertSame('<p>S$250 per hour</p>', $showcase['rate']);
        $this->assertNull($showcase['availability']);
        $this->assertNull($showcase['how_i_work']);
        $this->assertNull($showcase['photo_path']);

        $column = fn (string $table, string $col): array => array_column(
            $this->db->table($table)->where('showcase_id', $id)->orderBy($col)->get()->getResultArray(),
            $col
        );
        $this->assertSame([1, 3], array_map('intval', $column('showcase_categories', 'category_id')));
        $this->assertSame([10, 20], array_map('intval', $column('showcase_specialities', 'speciality_id')));

        $services = $this->db->table('showcase_services')->orderBy('sort_order')->get()->getResultArray();
        $this->assertSame(['Due diligence', 'Valuation'], array_column($services, 'title'));
        $this->assertSame('<p>Buy-side reviews</p>', $services[0]['description']);
        $this->assertNull($services[1]['description']);

        $achievements = $this->db->table('showcase_achievements')->orderBy('sort_order')->get()->getResultArray();
        $this->assertSame(['<p>Advised banks</p>', '<p>Led a S$50m raise</p>'], array_column($achievements, 'description'));

        $groups = $this->db->table('showcase_skill_groups')->get()->getResultArray();
        $this->assertCount(1, $groups);
        $this->assertSame('Data & Analytics', $groups[0]['name']);
        $this->assertSame(['Python', 'Tableau'], json_decode($groups[0]['skills'], true));
    }

    public function testSearchTagsAreSpecialisationWithCategory(): void
    {
        $this->assertSame(
            ['Due Diligence (Finance)', 'Seed Rounds (Fund Raising)'],
            (new SpecialityModel())->searchTags([20, 10])
        );
    }

    /**
     * @dataProvider provideInvalidInput
     */
    public function testRejectsInvalidInput(array $override, array $remove = []): void
    {
        $input = array_merge($this->validInput(), $override);
        foreach ($remove as $field) {
            unset($input[$field]);
        }

        $result = $this->actingAs(fake(UserModel::class))->post('create-profile', $input);

        $result->assertRedirect();
        $result->assertSessionMissing('message');
        $this->seeNumRecords(0, 'showcases', []);
    }

    public static function provideInvalidInput(): iterable
    {
        return [
            'missing name'                      => [['name' => '   ']],
            'missing title'                     => [[], ['title']],
            'headline too long'                 => [['headline' => str_repeat('x', 256)]],
            'blank bio editor'                  => [['about' => '<p><br></p>']],
            'no categories'                     => [[], ['categories']],
            'inactive category'                 => [['categories' => ['1', '2', '3']]],
            'no specialisations'                => [[], ['specialisations']],
            'inactive specialisation'           => [['specialisations' => ['10', '12']]],
            'specialisation outside categories' => [['categories' => ['1'], 'specialisations' => ['10', '20']]],
            'no services'                       => [[], ['services']],
            'service without title'             => [['services' => [['title' => '', 'detail' => '<p>x</p>']]]],
            'skill group without skills'        => [['skill_groups' => [['name' => 'Data', 'skills' => []]]]],
            'skills without group name'         => [['skill_groups' => [['name' => '', 'skills' => ['SQL']]]]],
            'skill too long'                    => [['skill_groups' => [['name' => 'Data', 'skills' => [str_repeat('x', 51)]]]]],
            'terms not accepted'                => [[], ['terms']],
        ];
    }
}
