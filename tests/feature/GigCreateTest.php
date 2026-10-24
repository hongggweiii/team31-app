<?php

namespace Tests\Feature;

use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\LegacyTaxonomyTrait;

/**
 * @internal
 */
final class GigCreateTest extends CIUnitTestCase
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
            'gig_title'       => '  Interim CFO ',
            'gig_summary'     => 'Lead finance for six months.',
            'categories'      => ['1', '3'],
            'specialisations' => ['10', '20'],
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
        $result->assertSee('Industry');                // category group heading
        $result->assertSee('Due Diligence (Finance)'); // specialisation labelled as its search tag
        $result->assertDontSee('Retired');
        $result->assertDontSee('Seniority');           // ranker-only fields are gone
        $result->assertDontSee('Budget');
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
        $this->assertSame(['id', 'user_id', 'title', 'summary', 'status', 'created_at', 'updated_at'], array_keys($gig));
        $this->seeInDatabase('gig_categories', ['gig_id' => $gig['id'], 'category_id' => 1]);
        $this->seeInDatabase('gig_categories', ['gig_id' => $gig['id'], 'category_id' => 3]);
        $this->seeInDatabase('gig_specialities', ['gig_id' => $gig['id'], 'speciality_id' => 10]);
        $this->seeInDatabase('gig_specialities', ['gig_id' => $gig['id'], 'speciality_id' => 20]);
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
            'inactive category'                 => [['categories' => ['1', '2', '3']]],
            'no specialisations'                => [[], ['specialisations']],
            'inactive specialisation'           => [['specialisations' => ['10', '12']]],
            'specialisation outside categories' => [['categories' => ['1'], 'specialisations' => ['10', '20']]],
        ];
    }
}
