<?php

namespace Tests\Feature;

use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The matcher dashboard: admins only, built from the saved runs.
 *
 * @internal
 */
final class DashboardTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    public function testAdminSeesTheSavedRuns(): void
    {
        $admin = fake(UserModel::class);
        $admin->addGroup('admin');
        $gigId = (new GigModel())->insert(['user_id' => $admin->id, 'title' => 'Interim CFO', 'summary' => 'Lead finance.']);
        $this->db->table('analytics_outputs')->insert(['gig_id' => $gigId, 'status' => 'ok', 'matcher_machine' => 'CPU: Test 1',
            'matcher_total_ms' => 800, 'action_total_ms' => 4200, 'page_total_ms' => 1800, 'created_at' => '2026-10-29 12:00:00']);
        $this->db->table('run_outputs')->insert(['analytics_output_id' => $this->db->insertID(), 'gig_id' => $gigId, 'rank' => 1,
            'provider_id' => 7, 'score' => 100, 'raw_margin' => 4.3, 'display_title' => 'Fractional CFO', 'tag_level' => 3]);

        $result = $this->actingAs($admin)->get('admin/dashboard');

        $result->assertOK();
        $result->assertSee('Matcher Dashboard');
        $result->assertSee('CPU: Test 1');
        $result->assertSee('Interim CFO');
        $result->assertSee('Fractional CFO');
        $result->assertSee('Submission');
    }

    public function testRunsTableShowsTenRunsAPage(): void
    {
        $admin = fake(UserModel::class);
        $admin->addGroup('admin');
        $gigs = new GigModel();

        foreach (range(1, 12) as $n) {
            $gigId = $gigs->insert(['user_id' => $admin->id, 'title' => "Gig number {$n}", 'summary' => 'Test.']);
            $this->db->table('analytics_outputs')->insert(['gig_id' => $gigId, 'status' => 'ok', 'created_at' => '2026-10-29 12:00:00']);
        }

        $result = $this->actingAs($admin)->get('admin/dashboard', ['page' => 2]);

        $result->assertOK();
        $result->assertSee('Runs 11');
        $result->assertSee('Gig number 2');
        $result->assertDontSee('Gig number 3<');
    }

    public function testRevisitShowsOnlyTheMatchesPage(): void
    {
        $admin = fake(UserModel::class);
        $admin->addGroup('admin');
        $gigId = (new GigModel())->insert(['user_id' => $admin->id, 'title' => 'Interim CFO', 'summary' => 'Lead finance.']);
        $this->db->table('analytics_outputs')->insert(['gig_id' => $gigId, 'status' => 'ok', 'matcher_total_ms' => 800,
            'page_total_ms' => 1800, 'created_at' => '2026-10-29 12:00:00']);

        $result = $this->actingAs($admin)->get('admin/dashboard');

        $result->assertSee('Refresh');
        $result->assertSee('Matches page');
        $result->assertDontSee('Save gig');
        $result->assertSee('1.8 s');
    }

    public function testOtherUsersAreTurnedAway(): void
    {
        $result = $this->actingAs(fake(UserModel::class))->get('admin/dashboard');

        $result->assertRedirect();
        $result->assertDontSee('Matcher Dashboard');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        auth()->logout();

        $this->get('admin/dashboard')->assertRedirectTo(url_to('login'));
    }
}
