<?php

use App\Commands\EmbedAuto;
use App\Libraries\EmbeddingStore;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * embed:auto's two thresholds and what counts as a showcase waiting to be
 * embedded.
 *
 * @internal
 */
final class EmbedAutoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null; // App and Shield migrations: showcases.user_id references users
    protected $refresh   = true;

    /**
     * @dataProvider provideThresholds
     */
    public function testRunsWhenEitherThresholdIsReached(int $count, ?int $oldestAge, bool $runs): void
    {
        $this->assertSame($runs, EmbedAuto::reason($count, $oldestAge, 20, 15) !== null);
    }

    public static function provideThresholds(): iterable
    {
        return [
            'nothing waiting'                   => [0, null, false],
            'below both'                        => [5, 14 * 60, false],
            'size reached'                      => [20, 60, true],
            'time reached'                      => [1, 15 * 60, true],
            'both reached'                      => [25, 30 * 60, true],
        ];
    }

    public function testCountsPublishedShowcasesWithoutAnUpToDateVector(): void
    {
        $user = fake(UserModel::class)->id;
        $add  = function (string $status, string $updated) use ($user): int {
            $this->db->table('showcases')->insert([
                'user_id' => $user, 'name' => 'N', 'title' => 'T', 'headline' => 'H', 'about' => '<p>A</p>',
                'status' => $status, 'created_at' => $updated, 'updated_at' => $updated,
            ]);

            return $this->db->insertID();
        };
        $embed = function (int $id, string $at): void {
            $this->db->table('showcase_embeddings')->insert([
                'showcase_id' => $id, 'model' => 'm', 'text_hash' => str_repeat('a', 64), 'embedding' => '[0.5]', 'created_at' => $at, 'updated_at' => $at,
            ]);
        };

        $new    = $add('published', '2026-10-26 09:00:00');                          // never embedded: waiting (oldest)
        $edited = $add('published', '2026-10-26 09:30:00');                          // edited after its vector: waiting
        $embed($edited, '2026-10-26 09:20:00');
        $embed($add('published', '2026-10-26 09:10:00'), '2026-10-26 09:15:00');     // up to date
        $add('unpublished', '2026-10-26 08:00:00');                                  // not published: ignored

        $this->assertSame(['count' => 2, 'oldest' => '2026-10-26 09:00:00', 'ids' => [$new, $edited]], EmbedAuto::pending($this->db));
    }

    public function testATouchedVectorIsNoLongerWaiting(): void
    {
        $user = fake(UserModel::class)->id;
        $this->db->table('showcases')->insert([
            'user_id' => $user, 'name' => 'N', 'title' => 'T', 'headline' => 'H', 'about' => '<p>A</p>',
            'status' => 'published', 'created_at' => '2026-10-26 09:00:00', 'updated_at' => '2026-10-26 09:30:00',
        ]);
        $id = $this->db->insertID();
        $this->db->table('showcase_embeddings')->insert([
            'showcase_id' => $id, 'model' => 'm', 'text_hash' => str_repeat('a', 64), 'embedding' => '[0.5]',
            'created_at' => '2026-10-26 09:00:00', 'updated_at' => '2026-10-26 09:00:00',
        ]);
        $this->assertSame(1, EmbedAuto::pending($this->db)['count']);

        // What embed:batch does for a showcase edited without changing its embedded text.
        EmbeddingStore::showcases($this->db)->touch([$id]);

        $this->assertSame(0, EmbedAuto::pending($this->db)['count']);
    }

    public function testNothingWaitingWhenAllAreEmbedded(): void
    {
        $this->assertSame(['count' => 0, 'oldest' => null, 'ids' => []], EmbedAuto::pending($this->db));
    }
}
