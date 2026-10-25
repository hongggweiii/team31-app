<?php

namespace Tests\Feature;

use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\TaxonomyFixtureTrait;

/**
 * @internal
 */
final class GigCreateTest extends CIUnitTestCase
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
            'gig_title'          => '  Interim CFO ',
            'gig_summary'        => 'Lead finance for six months.',
            'categories'         => [(string) $this->catId('Accountancy'), (string) $this->catId('Financial Services')],
            'specialisations'    => [(string) $this->specId('Accountancy', 'Assurance'), (string) $this->specId('Financial Services', 'Project Management')],
            'skills'             => [$this->specId('Accountancy', 'Assurance') => [(string) $this->skillId('Audit Compliance')]],
            'duration_weeks_min' => '4',
            'duration_weeks_max' => '6',
            'additional_notes'   => '  Budget around S$150/hr. ',
        ];
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        // Shield's auth service is shared, so drop any user left by earlier tests.
        auth()->logout();

        $this->get('create-gig')->assertRedirectTo(url_to('login'));
        $this->post('create-gig', $this->validInput())->assertRedirectTo(url_to('login'));
        $this->seeNumRecords(0, 'gigs', []);
    }

    public function testLoggedInUserSeesForm(): void
    {
        $result = $this->actingAs(fake(UserModel::class))->get('create-gig');

        $result->assertOK();
        $result->assertSee('Short Gig Description');
        $result->assertSee('Infocomm Technology');               // category
        $result->assertSee('Assurance (Accountancy)');           // specialisation labelled as its search tag
        $result->assertSee('Financial Statements Analysis');     // a skill of it
        $result->assertDontSee('Seniority');           // not part of schema v2
        $result->assertSee('Engagement Duration');
        $result->assertSee('Additional Notes');
    }

    public function testCreatesGigWithTaxonomyLinks(): void
    {
        $user = fake(UserModel::class);

        $result = $this->actingAs($user)->post('create-gig', $this->validInput());

        $gig = (new GigModel())->first();
        $result->assertRedirectTo(url_to('gig-matches', $gig['id']));
        $result->assertSessionHas('message', 'Your gig has been posted.');

        $this->assertSame((string) $user->id, (string) $gig['user_id']);
        $this->assertSame('Interim CFO', $gig['title']);
        $this->assertSame('Lead finance for six months.', $gig['summary']);
        $this->assertSame(4, (int) $gig['duration_weeks_min']);
        $this->assertSame(6, (int) $gig['duration_weeks_max']);
        $this->assertSame('Budget around S$150/hr.', $gig['additional_notes']);
        $this->assertEqualsCanonicalizing( // SQLite appends added columns, so order differs from MariaDB
            ['id', 'user_id', 'title', 'summary', 'duration_weeks_min', 'duration_weeks_max', 'additional_notes', 'status', 'created_at', 'updated_at'],
            array_keys($gig)
        );
        $this->seeNumRecords(2, 'gig_specialisations', ['gig_id' => $gig['id']]);
        $this->seeInDatabase('gig_specialisations', ['gig_id' => $gig['id'], 'specialisation_id' => $this->specId('Financial Services', 'Project Management')]);
        $this->seeInDatabase('gig_skills', [
            'gig_id' => $gig['id'], 'specialisation_id' => $this->specId('Accountancy', 'Assurance'), 'skill_id' => $this->skillId('Audit Compliance'),
        ]);
    }

    public function testAcceptsOnlyTheRequiredFields(): void
    {
        $input = $this->validInput();
        unset($input['duration_weeks_min'], $input['duration_weeks_max'], $input['additional_notes']);

        $this->actingAs(fake(UserModel::class))->post('create-gig', $input)->assertSessionHas('message', 'Your gig has been posted.');
        $this->seeNumRecords(1, 'gigs', []);
    }

    /**
     * @dataProvider provideOptionalFields
     */
    public function testSavesOptionalFields(array $override, ?int $min, ?int $max, ?string $notes): void
    {
        $this->actingAs(fake(UserModel::class))->post('create-gig', array_merge($this->validInput(), $override));

        $gig = (new GigModel())->first();
        $this->assertSame($min, $gig['duration_weeks_min'] === null ? null : (int) $gig['duration_weeks_min']);
        $this->assertSame($max, $gig['duration_weeks_max'] === null ? null : (int) $gig['duration_weeks_max']);
        $this->assertSame($notes, $gig['additional_notes']);
    }

    public static function provideOptionalFields(): iterable
    {
        return [
            'all blank'     => [['duration_weeks_min' => '', 'duration_weeks_max' => '', 'additional_notes' => '  '], null, null, null],
            'only minimum'  => [['duration_weeks_min' => '3', 'duration_weeks_max' => ''], 3, 3, 'Budget around S$150/hr.'],
            'only maximum'  => [['duration_weeks_min' => '', 'duration_weeks_max' => '8'], 8, 8, 'Budget around S$150/hr.'],
        ];
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

        $result = $this->actingAs(fake(UserModel::class))->post('create-gig', $input);

        $result->assertRedirect();
        $result->assertSessionMissing('message');
        $this->seeNumRecords(0, 'gigs', []);
    }

    public static function provideInvalidInput(): iterable
    {
        return [
            'missing title'                     => [['gig_title' => '']],
            'title too long'                    => [['gig_title' => str_repeat('x', 501)]],
            'missing description'               => [[], ['gig_summary']],
            'no categories'                     => [[], ['categories']],
            'no specialisations'                => [[], ['specialisations']],
            'unknown specialisation'            => [['specialisations' => ['999']]],
            'zero weeks'                        => [['duration_weeks_min' => '0']],
            'fractional weeks'                  => [['duration_weeks_max' => '4.5']],
            'over a year'                       => [['duration_weeks_max' => '53']],
            'maximum below minimum'             => [['duration_weeks_min' => '6', 'duration_weeks_max' => '4']],
            'notes too long'                    => [['additional_notes' => str_repeat('x', 2001)]],
        ];
    }
}
