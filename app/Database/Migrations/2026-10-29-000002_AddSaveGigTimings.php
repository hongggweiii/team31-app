<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Where the time of the request that saved the gig (POST /create-gig, before
 * the redirect to its matches page) went, next to the matches page's own
 * numbers in analytics_outputs. RequestTimer passes them on in the session;
 * NULL when it is off, or for runs saved before this column existed.
 */
class AddSaveGigTimings extends Migration
{
    public function up(): void
    {
        $ms = ['type' => 'float', 'null' => true];

        $this->forge->addColumn('analytics_outputs', [
            'save_connect_ms'  => [...$ms, 'after' => 'matcher_call_ms'],
            'save_query_ms'    => [...$ms, 'after' => 'save_connect_ms'],
            'save_query_count' => ['type' => 'int', 'constraint' => 11, 'null' => true, 'after' => 'save_query_ms'],
            'save_php_ms'      => [...$ms, 'after' => 'save_query_count'],
            'save_total_ms'    => [...$ms, 'after' => 'save_php_ms'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('analytics_outputs', ['save_connect_ms', 'save_query_ms', 'save_query_count', 'save_php_ms', 'save_total_ms']);
    }
}
