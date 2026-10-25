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
use Tests\Support\LegacyTaxonomyTrait;

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
    use LegacyTaxonomyTrait;

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

        $this->createLegacyTaxonomy();
        $this->owner = fake(UserModel::class);
        $this->gigId = (new GigModel())->createWithTaxonomy(
            ['user_id' => $this->owner->id, 'title' => 'Interim CFO', 'summary' => 'Lead finance for six months.'],
            ['3', '1'],
            ['20', '10', '11'],
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

    protected function tearDown(): void
    {
        $this->dropLegacyTaxonomy();

        parent::tearDown();
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
                ['name' => 'Finance', 'group' => null, 'specialisations' => [
                    ['name' => 'Due Diligence', 'search_tag' => 'Due Diligence (Finance)', 'skills' => []],
                    ['name' => 'Valuation', 'search_tag' => 'Valuation (Finance)', 'skills' => []],
                ]],
                ['name' => 'Fund Raising', 'group' => null, 'specialisations' => [
                    ['name' => 'Seed Rounds', 'search_tag' => 'Seed Rounds (Fund Raising)', 'skills' => []],
                ]],
            ],
            'top_k' => 10,
        ]], $this->matcher->requests);
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
