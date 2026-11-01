<?php

namespace Tests\Feature;

use App\Libraries\TaxonomyClient;
use App\Models\GigModel;
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
 * The taxonomy dashboard. taxonomy-service/ is replaced by a fake that
 * records its calls and answers from $answers.
 *
 * @internal
 */
final class TaxonomyDashboardTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxonomyFixtureTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    private User $user;
    private int $gigId;

    /**
     * calls: [method, ...args]; answers: method => array, RuntimeException, or callable(...args).
     */
    private TaxonomyClient $service;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF is covered by the framework; keep these requests token-free.
        config('Filters')->globals['before'] = [];

        $this->loadTaxonomy();
        $this->user  = fake(UserModel::class);
        $this->gigId = (new GigModel())->createWithTaxonomy(
            ['user_id' => $this->user->id, 'title' => 'AML review', 'summary' => 'Review our AML framework.', 'additional_notes' => 'MAS Notice 626.'],
            ['specialisations' => [$this->specId('Accountancy', 'Assurance')], 'skills' => []],
        );

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

            public function proposals(string $status = 'pending_review'): array
            {
                return $this->answer(__FUNCTION__, func_get_args());
            }

            public function approve(int $proposalId, ?int $adminUserId): array
            {
                return $this->answer(__FUNCTION__, func_get_args());
            }

            public function reject(int $proposalId, ?int $adminUserId, string $reason): array
            {
                return $this->answer(__FUNCTION__, func_get_args());
            }

            public function refresh(): array
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

                return is_callable($answer) ? $answer(...$args) : $answer;
            }
        };
        Services::injectMock('taxonomyClient', $this->service);
    }

    private static function proposal(array $override = []): array
    {
        return $override + [
            'id' => 7, 'proposed_name' => 'Sanctions Screening', 'kind' => 'skill', 'category' => 'Financial Services',
            'specialisation' => 'Regulatory Compliance', 'status' => 'pending_review', 'submitted_by_source' => 'ai_suggestion',
            'submitted_by_user' => 3, 'confidence' => 0.8, 'owner_type' => 'gig', 'owner_id' => 12, 'auto_flag' => 'fuzzy',
            'closest_match_table' => 'taxonomy_proposals', 'closest_match_name' => 'Sanction Screening', 'similarity_score' => 95,
            'duplicates' => [9], 'actioned_at' => null, 'admin_reason' => null, 'approved_item_id' => null,
        ];
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        auth()->logout();

        $this->get('taxonomy-dashboard')->assertRedirectTo(url_to('login'));
        $this->get('taxonomy-dashboard/taxonomy')->assertRedirectTo(url_to('login'));
        $this->assertSame([], $this->service->calls);
    }

    public function testListsPendingTermsWithTheirDuplicateCheck(): void
    {
        $this->service->answers['proposals'] = [self::proposal()];

        $result = $this->actingAs($this->user)->get('taxonomy-dashboard');

        $result->assertOK();
        $this->assertSame([['proposals', 'pending_review']], $this->service->calls);
        $result->assertSee('Sanctions Screening');
        $result->assertSee('Financial Services › Regulatory Compliance', 'td');
        $result->assertSee('80% confident');
        $result->assertSee('Near-identical');
        $result->assertSee('pending term “Sanction Screening”, 95% similar', 'span');
        $result->assertSee('Same term as #9: decided together');
    }

    public function testShowsReviewedTerms(): void
    {
        $this->service->answers['proposals'] = [self::proposal(['status' => 'rejected', 'actioned_at' => '2026-10-31 09:00:00', 'admin_reason' => 'Too vague'])];

        $result = $this->actingAs($this->user)->get('taxonomy-dashboard?status=rejected');

        $this->assertSame([['proposals', 'rejected']], $this->service->calls);
        $result->assertSee('Sanctions Screening');
        $result->assertDontSee('>Approve</button>');
    }

    public function testSaysSoWhenTheServiceIsDown(): void
    {
        $this->service->answers['proposals'] = new RuntimeException('Connection refused');

        $result = $this->actingAs($this->user)->get('taxonomy-dashboard');

        $result->assertOK();
        $result->assertSee('The taxonomy service is unavailable');
    }

    public function testApprovesOneTermAndReportsWhereItCouldNotBeTagged(): void
    {
        $this->service->answers['approve'] = [
            'approved' => [7, 9], 'item' => ['table' => 'sf_skills', 'id' => 40, 'name' => 'Sanctions Screening'],
            'attached' => [], 'skipped' => [['proposal_id' => 9, 'owner_type' => 'gig', 'owner_id' => 3, 'reason' => 'the gig no longer has that specialisation']],
        ];

        $result = $this->actingAs($this->user)->post('taxonomy-dashboard/review', ['approve' => '7']);

        $this->assertSame([['approve', 7, $this->user->id]], $this->service->calls);
        $result->assertSessionHas('message', 'Approved 2 proposal(s).');
        $result->assertSessionHas('notes', ['"Sanctions Screening" was not added to gig #3: the gig no longer has that specialisation.']);
    }

    public function testRejectsATermAndReportsWhoWasNotified(): void
    {
        $this->service->answers['reject'] = ['rejected' => [3, 4], 'detached' => [], 'notified' => [['user_id' => 5], ['user_id' => 6]]];

        $result = $this->actingAs($this->user)->post('taxonomy-dashboard/review', ['reject' => '3']);

        $this->assertSame([['reject', 3, $this->user->id, '']], $this->service->calls);
        $result->assertSessionHas('message', 'Rejected 2 proposal(s).');
        $result->assertSessionHas('notes', ['Notified user(s) 5, 6.']);
    }

    public function testAlreadyReviewedIsNotAnError(): void
    {
        // Rejected together with an earlier row holding the same term: the service answers 409.
        $this->service->answers['reject'] = new RuntimeException('Proposal 4 is already rejected', 409);

        $this->actingAs($this->user)->post('taxonomy-dashboard/review', ['reject' => '4'])->assertSessionHas('errors', []);
    }

    public function testReviewNeedsAProposal(): void
    {
        $result = $this->actingAs($this->user)->post('taxonomy-dashboard/review', []);

        $this->assertSame([], $this->service->calls);
        $result->assertSessionHas('errors', ['Choose a proposal to approve or reject.']);
    }

    public function testShowsTheTaxonomyWithUsage(): void
    {
        $result = $this->actingAs($this->user)->get('taxonomy-dashboard/taxonomy');

        $result->assertOK();
        $result->assertSee('Digital and Data Analytics');
        $result->assertSee('Audit Compliance');
        $result->assertSee('Assurance1', 'button'); // used by the gig from setUp()
    }

    public function testEditsTheTaxonomyThenHasPendingTermsRechecked(): void
    {
        $result = $this->actingAs($this->user)->post('taxonomy-dashboard/taxonomy', [
            'action' => 'add', 'kind' => 'skill', 'name' => 'Sanctions Screening', 'parent_id' => (string) $this->specId('Accountancy', 'Tax'),
        ]);

        $result->assertSessionHas('message', 'Added.');
        $this->seeInDatabase('sf_skills', ['name' => 'Sanctions Screening']);
        $this->assertSame([['refresh']], $this->service->calls);
    }

    public function testARefusedEditIsReportedAndNothingIsRechecked(): void
    {
        $result = $this->actingAs($this->user)->post('taxonomy-dashboard/taxonomy', [
            'action' => 'remove', 'kind' => 'specialisation', 'id' => (string) $this->specId('Accountancy', 'Assurance'),
        ]);

        $result->assertSessionHas('errors', ['It is used by 1 gig(s) / showcase(s).']);
        $this->seeInDatabase('sf_specialisations', ['name' => 'Assurance']);
        $this->assertSame([], $this->service->calls);
    }

    public function testAnEditIsKeptWhenTheServiceCannotRecheck(): void
    {
        $this->service->answers['refresh'] = new RuntimeException('Connection refused');

        $result = $this->actingAs($this->user)->post('taxonomy-dashboard/taxonomy', [
            'action' => 'rename', 'kind' => 'category', 'id' => (string) $this->catId('Accountancy'), 'name' => 'Accounting',
        ]);

        $this->seeInDatabase('sf_categories', ['name' => 'Accounting']);
        $result->assertSessionHas('message', 'Renamed.');
        $result->assertSessionHas('errors', ['Saved, but the taxonomy service could not re-check pending proposals: Connection refused']);
    }
}
