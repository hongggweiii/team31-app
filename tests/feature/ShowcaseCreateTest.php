<?php

namespace Tests\Feature;

use App\Models\ShowcaseModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\TaxonomyFixtureTrait;

/**
 * @internal
 */
final class ShowcaseCreateTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxonomyFixtureTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadTaxonomy();

        // CSRF is covered by the framework; keep these requests token-free.
        config('Filters')->globals['before'] = [];
    }

    private function validInput(): array
    {
        return [
            'name'            => '  Hong Wei Tai ',
            'title'           => 'Financial Due Diligence',
            'years_experience' => ' 12 ',
            'credentials'     => ['Chartered Financial Analyst (CFA)', ' ', ' chartered financial analyst (cfa)', 'Certified Public Accountant (CPA)'],
            'headline'        => 'Fractional CFO | Strategy & Finance Expert',
            'about'           => '<p onclick="steal()">I drive transformation.</p><script>alert(1)</script>',
            'categories'      => [(string) $this->catId('Accountancy'), (string) $this->catId('Infocomm Technology')],
            'specialisations' => array_map('strval', [
                $this->specId('Accountancy', 'Tax'),
                $this->specId('Infocomm Technology', 'Data and Artificial Intelligence'),
                $this->specId('Accountancy', 'Tax'),
            ]),
            'skills'          => [
                $this->specId('Infocomm Technology', 'Data and Artificial Intelligence') => [(string) $this->skillId('Cloud Computing'), (string) $this->skillId('Data Analytics')],
            ],
            'availability'    => '  ',
            'rate'            => ' From S$3,000 per day ',
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
        $result->assertSee('Infocomm Technology');                 // category
        $result->assertSee('Project Management (Financial Services)'); // specialisation labelled as its search tag
        $result->assertSee('Stakeholder Management');              // a skill of it
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
        $this->assertSame('From S$3,000 per day', $showcase['rate']);
        $this->assertSame(12, (int) $showcase['years_experience']);
        $this->assertNull($showcase['availability']);
        $this->assertNull($showcase['how_i_work']);
        $this->assertNull($showcase['photo_path']);

        $column = fn (string $table, string $col): array => array_column(
            $this->db->table($table)->where('showcase_id', $id)->orderBy($col)->get()->getResultArray(),
            $col
        );
        $this->assertEqualsCanonicalizing(
            [$this->specId('Accountancy', 'Tax'), $this->specId('Infocomm Technology', 'Data and Artificial Intelligence')],
            array_map('intval', $column('showcase_specialisations', 'specialisation_id'))
        );
        $this->assertEqualsCanonicalizing(
            [$this->skillId('Cloud Computing'), $this->skillId('Data Analytics')],
            array_map('intval', $column('showcase_skills', 'skill_id'))
        );

        $credentials = $this->db->table('showcase_credentials')->orderBy('sort_order')->get()->getResultArray();
        $this->assertSame(['Chartered Financial Analyst (CFA)', 'Certified Public Accountant (CPA)'], array_column($credentials, 'description'));

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

    /**
     * Exactly what the browser posts when only the starred fields are filled in:
     * every optional repeatable section still sends one blank box.
     */
    public function testAcceptsOnlyTheRequiredFields(): void
    {
        $result = $this->actingAs(fake(UserModel::class))->post('create-profile', [
            'name'             => 'Hong Wei Tai',
            'title'            => 'Forensic Accountant',
            'years_experience' => '',
            'credentials'      => [''],
            'headline'         => 'Fractional CFO',
            'about'            => '<p>I drive transformation.</p>',
            'categories'       => [(string) $this->catId('Accountancy')],
            'specialisations'  => [(string) $this->specId('Accountancy', 'Management Accounting')],
            'availability'     => '',
            'rate'             => '',
            'services'         => [['title' => 'Due diligence', 'detail' => '<p><br></p>']],
            'achievements'     => ['<p><br></p>'],
            'skill_groups'     => [['name' => '']],
            'how_i_work'       => '',
            'terms'            => '1',
        ]);

        $result->assertSessionHas('message', 'Your showcase has been published.');
        $this->seeNumRecords(0, 'showcase_skill_groups', []);
    }

    public function testYearsOfExperienceIsOptional(): void
    {
        $this->actingAs(fake(UserModel::class))->post('create-profile', array_merge($this->validInput(), ['years_experience' => '']));

        $this->assertNull((new ShowcaseModel())->first()['years_experience']);
    }

    public static function provideInvalidInput(): iterable
    {
        $boxes = static fn (int $n, callable $box): array => array_map($box, range(1, $n));

        return [
            'negative years'                    => [['years_experience' => '-1']],
            'fractional years'                  => [['years_experience' => '2.5']],
            'over 60 years'                     => [['years_experience' => '61']],
            'credential too long'               => [['credentials' => [str_repeat('x', 256)]]],
            'too many credentials'              => [['credentials' => $boxes(7, static fn (int $i): string => "Credential {$i}")]],
            'too many services'                 => [['services' => $boxes(4, static fn (int $i): array => ['title' => "Service {$i}", 'detail' => ''])]],
            'too many achievements'             => [['achievements' => $boxes(6, static fn (int $i): string => "<p>Achievement {$i}</p>")]],
            'too many skill groups'             => [['skill_groups' => $boxes(5, static fn (int $i): array => ['name' => "Group {$i}", 'skills' => ['SQL']])]],
            'too many skills in a group'        => [['skill_groups' => [['name' => 'Data', 'skills' => $boxes(9, static fn (int $i): string => "Skill {$i}")]]]],
            'rate too long'                     => [['rate' => str_repeat('x', 256)]],
            'missing name'                      => [['name' => '   ']],
            'missing title'                     => [[], ['title']],
            'headline too long'                 => [['headline' => str_repeat('x', 256)]],
            'blank bio editor'                  => [['about' => '<p><br></p>']],
            'no categories'                     => [[], ['categories']],
            'no specialisations'                => [[], ['specialisations']],
            'unknown specialisation'            => [['specialisations' => ['999']]],
            'no services'                       => [[], ['services']],
            'service without title'             => [['services' => [['title' => '', 'detail' => '<p>x</p>']]]],
            'skill group without skills'        => [['skill_groups' => [['name' => 'Data', 'skills' => []]]]],
            'skills without group name'         => [['skill_groups' => [['name' => '', 'skills' => ['SQL']]]]],
            'group name too long'               => [['skill_groups' => [['name' => str_repeat('x', 101), 'skills' => ['SQL']]]]],
            'skill too long'                    => [['skill_groups' => [['name' => 'Data', 'skills' => [str_repeat('x', 51)]]]]],
            'terms not accepted'                => [[], ['terms']],
        ];
    }
}
