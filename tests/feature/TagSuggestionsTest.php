<?php

namespace Tests\Feature;

use App\Libraries\TaxonomyClient;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\TaxonomyFixtureTrait;

/**
 * "Suggest tags with AI" on the gig and showcase forms, and linking the
 * suggested pending terms to the gig / showcase when it is saved.
 * taxonomy-service/ is replaced by a fake that records its calls.
 *
 * @internal
 */
final class TagSuggestionsTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxonomyFixtureTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    private User $user;

    /**
     * calls: [method, ...args]; answers: method => array or RuntimeException.
     */
    private TaxonomyClient $service;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF is covered by the framework; keep these requests token-free.
        config('Filters')->globals['before'] = [];

        $this->loadTaxonomy();
        $this->user    = fake(UserModel::class);
        $this->service = new class () extends TaxonomyClient {
            public array $calls   = [];
            public array $answers = [];

            public function suggest(string $ownerType, ?int $ownerId, ?int $userId, string $title, string $text): array
            {
                return $this->answer(__FUNCTION__, func_get_args());
            }

            public function attach(array $proposalIds, string $ownerType, int $ownerId, ?int $userId): array
            {
                return $this->answer(__FUNCTION__, func_get_args());
            }

            private function answer(string $method, array $args): array
            {
                $this->calls[] = [$method, ...$args];
                $answer        = $this->answers[$method] ?? [];
                if ($answer instanceof RuntimeException) {
                    throw $answer;
                }

                return $answer;
            }
        };
        Services::injectMock('taxonomyClient', $this->service);
    }

    private const TAGS = [['id' => 2, 'name' => 'Financial Services', 'group' => null, 'specialisations' => [
        ['id' => 5, 'name' => 'Project Management', 'search_tag' => 'Project Management (Financial Services)', 'confidence' => 0.9, 'skills' => []],
    ]]];

    public function testSuggestsFromTheGigFormAsFilledInSoFar(): void
    {
        $this->service->answers['suggest'] = ['model' => 'gpt-4o-mini', 'tags' => self::TAGS, 'pending' => [
            ['id' => 31, 'proposed_name' => 'Sanctions Screening', 'kind' => 'skill', 'status' => 'pending_review'],
        ]];

        $result = $this->actingAs($this->user)->post('tag-suggestions', [
            'type' => 'gig', 'gig_title' => ' AML review ', 'gig_summary' => 'Review our AML framework.', 'additional_notes' => 'MAS Notice 626.',
        ]);

        $result->assertOK();
        $this->assertSame([['suggest', 'gig', null, (int) $this->user->id, 'AML review', "Review our AML framework.\nMAS Notice 626."]], $this->service->calls);
        $body = json_decode($result->getJSON(), true);
        $this->assertSame(self::TAGS, $body['tags']);
        $this->assertSame([['id' => 31, 'name' => 'Sanctions Screening', 'kind' => 'skill']], $body['pending']);
        $this->assertSame(csrf_token(), $body['csrf']['name']);
    }

    public function testReadsAShowcaseAsItIsEmbedded(): void
    {
        $this->service->answers['suggest'] = ['model' => 'gpt-4o-mini', 'tags' => [], 'pending' => []];

        $this->actingAs($this->user)->post('tag-suggestions', [
            'type'         => 'showcase',
            'title'        => 'Crypto Compliance Consultant',
            'headline'     => 'Crypto compliance lead',
            'about'        => '<p>Former <strong>DPT</strong> compliance officer.</p>',
            'services'     => [['title' => 'Travel Rule implementation', 'detail' => '<p>VASP messaging.</p>'], ['title' => 'MAS licensing', 'detail' => '']],
            'achievements' => ['<p>Two exchanges licensed.</p>'],
        ])->assertOK();

        $this->assertSame('Crypto Compliance Consultant', $this->service->calls[0][4]);
        $this->assertSame('Crypto compliance lead Former DPT compliance officer. Travel Rule implementation; MAS licensing VASP messaging. Two exchanges licensed.',
            $this->service->calls[0][5]);
    }

    public function testAsksForTextFirst(): void
    {
        $result = $this->actingAs($this->user)->post('tag-suggestions', ['type' => 'gig', 'gig_title' => 'AML review', 'gig_summary' => '  ']);

        $result->assertStatus(422);
        $this->assertSame('Write the gig description first, then ask for suggestions.', json_decode($result->getJSON(), true)['error']);
        $this->assertSame([], $this->service->calls);
    }

    public function testSaysSoWhenTheServiceIsDown(): void
    {
        $this->service->answers['suggest'] = new RuntimeException('AI tag suggestion is unavailable: Request timed out.', 503);

        $result = $this->actingAs($this->user)->post('tag-suggestions', ['type' => 'gig', 'gig_title' => 'AML review', 'gig_summary' => 'Review it.']);

        $result->assertStatus(503);
        $this->assertSame('Suggestions are unavailable right now.', json_decode($result->getJSON(), true)['error']);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        auth()->logout();

        $this->post('tag-suggestions', ['type' => 'gig', 'gig_summary' => 'x'])->assertRedirectTo(url_to('login'));
        $this->assertSame([], $this->service->calls);
    }

    public function testTheFormsHaveTheButton(): void
    {
        $this->actingAs($this->user)->get('create-gig')->assertSee('Suggest tags with AI', 'button');
        $this->actingAs($this->user)->get('create-profile')->assertSee('Suggest tags with AI', 'button');
    }

    public function testSavingAGigLinksItsSuggestedTerms(): void
    {
        $this->service->answers['attach'] = ['attached' => [31, 32]];

        $this->actingAs($this->user)->post('create-gig', [
            'gig_title'       => 'AML review',
            'gig_summary'     => 'Review our AML framework.',
            'categories'      => [(string) $this->catId('Financial Services')],
            'specialisations' => [(string) $this->specId('Financial Services', 'Project Management')],
            'proposals'       => ['31', '32'],
        ])->assertSessionHas('message', 'Your gig has been posted.');

        $gigId = (int) $this->db->table('gigs')->where('title', 'AML review')->get()->getRow()->id;
        $this->assertSame([['attach', [31, 32], 'gig', $gigId, (int) $this->user->id]], $this->service->calls);
    }

    public function testAGigIsSavedEvenIfLinkingFails(): void
    {
        $this->service->answers['attach'] = new RuntimeException('Connection refused');

        $this->actingAs($this->user)->post('create-gig', [
            'gig_title'       => 'AML review',
            'gig_summary'     => 'Review our AML framework.',
            'categories'      => [(string) $this->catId('Financial Services')],
            'specialisations' => [(string) $this->specId('Financial Services', 'Project Management')],
            'proposals'       => ['31'],
        ])->assertSessionHas('message', 'Your gig has been posted.');

        $this->seeInDatabase('gigs', ['title' => 'AML review']);
    }
}
