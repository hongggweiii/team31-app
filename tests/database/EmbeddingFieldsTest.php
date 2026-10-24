<?php

use App\Libraries\EmbeddingStore;
use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The fields_json column (the ranker-format record each vector was embedded
 * from) and EmbeddingStore::stored() reporting it, which embed:batch uses to
 * re-embed rows stored before the column existed.
 *
 * @internal
 */
final class EmbeddingFieldsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null; // App and Shield migrations: gigs.user_id references users
    protected $refresh   = true;

    public function testBothEmbeddingTablesHaveFieldsJson(): void
    {
        foreach (['showcase_embeddings', 'gig_embeddings'] as $table) {
            $this->assertContains('fields_json', $this->db->getFieldNames($table), $table);
        }
    }

    public function testStoredReportsWhetherTheRankerRecordIsSaved(): void
    {
        $userId = fake(UserModel::class)->id;
        $gigs   = new GigModel();
        $old    = $gigs->insert(['user_id' => $userId, 'title' => 'Old', 'summary' => 'Embedded before fields_json']);
        $new    = $gigs->insert(['user_id' => $userId, 'title' => 'New', 'summary' => 'Embedded after']);

        $row = static fn (int $id, ?string $fields): array => [
            'gig_id' => $id, 'model' => 'm', 'text_hash' => str_repeat('a', 64), 'fields_json' => $fields, 'embedding' => '[0.5,0.5]',
        ];
        $this->db->table('gig_embeddings')->insertBatch([
            $row($old, null),
            $row($new, json_encode(['hire_title' => 'New', 'hire_description' => 'Embedded after', 'hire_description_additional_notes' => ''])),
        ]);

        $stored = EmbeddingStore::gigs($this->db)->stored([$old, $new]);

        $this->assertFalse($stored[$old]['has_fields']);
        $this->assertTrue($stored[$new]['has_fields']);
        $this->assertSame(str_repeat('a', 64), $stored[$new]['text_hash']);
    }
}
