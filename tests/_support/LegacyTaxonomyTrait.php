<?php

namespace Tests\Support;

use Config\Database;

/**
 * Creates the legacy services / categories / specialities tables, which come
 * from the shared DB and have no migration, with a small fixed taxonomy:
 *
 *   Industry (1) > Finance (1): Due Diligence (10), Valuation (11), Old Audit (12, inactive)
 *   Industry (1) > Retired (2, inactive)
 *   Others (4)   > Fund Raising (3): Seed Rounds (20)
 *
 * Call from setUp() / tearDown() of a DatabaseTestTrait test.
 */
trait LegacyTaxonomyTrait
{
    private static array $legacyTables = [
        'services' => [
            [],
            [['id' => 1, 'name' => 'Industry', 'status' => '1'], ['id' => 4, 'name' => 'Others', 'status' => '1']],
        ],
        'categories' => [
            ['service_id' => ['type' => 'int']],
            [
                ['id' => 1, 'name' => 'Finance', 'service_id' => 1, 'status' => '1'],
                ['id' => 2, 'name' => 'Retired', 'service_id' => 1, 'status' => '0'],
                ['id' => 3, 'name' => 'Fund Raising', 'service_id' => 4, 'status' => '1'],
            ],
        ],
        'specialities' => [
            ['category_id' => ['type' => 'int']],
            [
                ['id' => 10, 'name' => 'Due Diligence', 'category_id' => 1, 'status' => '1'],
                ['id' => 11, 'name' => 'Valuation', 'category_id' => 1, 'status' => '1'],
                ['id' => 12, 'name' => 'Old Audit', 'category_id' => 1, 'status' => '0'],
                ['id' => 20, 'name' => 'Seed Rounds', 'category_id' => 3, 'status' => '1'],
            ],
        ],
    ];

    protected function createLegacyTaxonomy(): void
    {
        $forge = Database::forge();

        foreach (self::$legacyTables as $table => [$extra, $rows]) {
            $forge->addField(
                ['id' => ['type' => 'int', 'auto_increment' => true], 'name' => ['type' => 'varchar', 'constraint' => 255]]
                + $extra
                + ['status' => ['type' => 'varchar', 'constraint' => 1, 'default' => '0']]
            );
            $forge->addPrimaryKey('id');
            $forge->createTable($table, true);
            $this->db->table($table)->insertBatch($rows);
        }
    }

    protected function dropLegacyTaxonomy(): void
    {
        foreach (array_keys(self::$legacyTables) as $table) {
            Database::forge()->dropTable($table, true);
        }
    }
}
