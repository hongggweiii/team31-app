<?php

namespace Tests\Feature;

use App\Libraries\MatcherClient;
use App\Models\GigModel;
use CodeIgniter\Exceptions\PageNotFoundException;
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
 * The live matches page a hirer lands on after posting a gig. matcher/ is
 * replaced by a fake that records the request it would have been sent.
 *
 * @internal
 */
final class GigMatchesTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxonomyFixtureTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    private User $owner;
    private int $gigId;

    /**
     * Records requests; answers with $answer, or throws it when it is an exception.
     */
    private MatcherClient $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadTaxonomy();
        $this->owner = fake(UserModel::class);
        $this->gigId = (new GigModel())->createWithTaxonomy(
            ['user_id' => $this->owner->id, 'title' => 'Interim CFO', 'summary' => 'Lead finance for six months.'],
            [
                'specialisations' => [$this->specId('Financial Services', 'Project Management'), $this->specId('Accountancy', 'Tax'), $this->specId('Accountancy', 'Assurance')],
                'skills'          => [$this->specId('Accountancy', 'Assurance') => [$this->skillId('Audit Compliance')]],
            ],
        );

        $this->matcher = new class () extends MatcherClient {
            public array $requests = [];
            public array|RuntimeException $answer = [];

            public function match(array $request): array
            {
                $this->requests[] = $request;
                if ($this->answer instanceof RuntimeException) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
        Services::injectMock('matcherClient', $this->matcher);
    }

    private static function response(): array
    {
        return ['results' => [[
            'rank'        => 1,
            'provider_id' => 7,
            'score'       => 100.0,
            'raw_margin'  => 4.29,
            'provider'    => ['display_title' => 'Fractional CFO for growth-stage fintechs', 'industry' => null, 'bio_excerpt' => 'Ten years in finance.'],
            'signals'     => ['taxonomy' => ['tag_level' => 2.0]],
        ]], 'meta' => ['returned' => 1]];
    }

    public function testSendsTheRankersRequestForTheGig(): void
    {
        $this->matcher->answer = self::response();

        $this->actingAs($this->owner)->get("gigs/{$this->gigId}/matches");

        $this->assertSame([[
            'hire_id'          => $this->gigId,
            'hire_title'       => 'Interim CFO',
            'hire_description' => 'Lead finance for six months.',
            'industry'         => null,
            'taxonomy'         => [
                ['name' => 'Accountancy', 'group' => null, 'specialisations' => [
                    ['name' => 'Assurance', 'search_tag' => 'Assurance (Accountancy)', 'skills' => ['Audit Compliance']],
                    ['name' => 'Tax', 'search_tag' => 'Tax (Accountancy)', 'skills' => []],
                ]],
                ['name' => 'Financial Services', 'group' => null, 'specialisations' => [
                    ['name' => 'Project Management', 'search_tag' => 'Project Management (Financial Services)', 'skills' => []],
                ]],
            ],
            'top_k' => 10,
        ]], $this->matcher->requests);
    }

    public function testUsesTheGigPassedOnByCreate(): void
    {
        $this->matcher->answer = self::response();
        // Proves the page didn't read the gig back: the database now says otherwise.
        $this->db->table('gigs')->where('id', $this->gigId)->update(['title' => 'Changed in the database']);
        $passedOn = [
            'gig'      => ['id' => $this->gigId, 'user_id' => $this->owner->id, 'title' => 'Interim CFO', 'summary' => 'Lead finance for six months.'],
            'taxonomy' => [['name' => 'Accountancy', 'group' => null, 'specialisations' => []]],
        ];

        $this->actingAs($this->owner)
            ->withSession(['new_gig' => $passedOn, '__ci_vars' => ['new_gig' => 'new']])
            ->get("gigs/{$this->gigId}/matches");

        $this->assertSame('Interim CFO', $this->matcher->requests[0]['hire_title']);
        $this->assertSame($passedOn['taxonomy'], $this->matcher->requests[0]['taxonomy']);
    }

    public function testOwnerSeesTheMatches(): void
    {
        $this->matcher->answer = self::response();

        $result = $this->actingAs($this->owner)->get("gigs/{$this->gigId}/matches");

        $result->assertOK();
        $result->assertSee('Interim CFO');
        $result->assertSee('Fractional CFO for growth-stage fintechs');
        $result->assertSee('Ten years in finance.');
        $result->assertSee('Same specialisation');
    }

    public function testSaysSoWhenMatchingIsUnavailable(): void
    {
        $this->matcher->answer = new RuntimeException('Matcher returned HTTP 503: embedding-service unavailable', 503);

        $result = $this->actingAs($this->owner)->get("gigs/{$this->gigId}/matches");

        $result->assertOK();
        $result->assertSee('Matching is unavailable right now');
    }

    public function testSavesTheRunAndEachSuggestedShowcase(): void
    {
        $this->matcher->answer = self::response();
        $this->matcher->answer['meta'] += ['candidate_pool' => 50, 'machine' => 'CPU: Test 1',
            'timing_ms' => ['embed' => 1400.0, 'candidates' => 80.0, 'score' => 2.0, 'response' => 0.5, 'total' => 1482.5]];

        $this->actingAs($this->owner)->get("gigs/{$this->gigId}/matches");

        $run = $this->db->table('analytics_outputs')->get()->getResultArray();
        $this->assertCount(1, $run);
        $this->assertSame(['ok', 'CPU: Test 1', 1400.0, 1482.5], [$run[0]['status'], $run[0]['matcher_machine'],
            (float) $run[0]['matcher_embed_ms'], (float) $run[0]['matcher_total_ms']]);
        $this->seeInDatabase('run_outputs', ['analytics_output_id' => $run[0]['id'], 'gig_id' => $this->gigId,
            'rank' => 1, 'provider_id' => 7, 'display_title' => 'Fractional CFO for growth-stage fintechs', 'tag_level' => 2.0]);
    }

    public function testSavesAFailedRunWithoutShowcases(): void
    {
        $this->matcher->answer = new RuntimeException('Matcher returned HTTP 503: embedding-service unavailable', 503);

        $this->actingAs($this->owner)->get("gigs/{$this->gigId}/matches");

        $this->seeInDatabase('analytics_outputs', ['gig_id' => $this->gigId, 'status' => 'error',
            'error' => 'Matcher returned HTTP 503: embedding-service unavailable']);
        $this->seeNumRecords(0, 'run_outputs', []);
    }

    public function testOtherUsersCannotSeeAGigsMatches(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->actingAs(fake(UserModel::class))->get("gigs/{$this->gigId}/matches");
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        auth()->logout();

        $this->get("gigs/{$this->gigId}/matches")->assertRedirectTo(url_to('login'));
        $this->assertSame([], $this->matcher->requests);
    }
}
