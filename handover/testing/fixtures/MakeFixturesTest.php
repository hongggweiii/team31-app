<?php

use App\Libraries\MatcherClient;
use App\Libraries\ProviderEmbedder;
use App\Libraries\Taxonomy;
use App\Models\GigModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\TaxonomyFixtureTrait;

/**
 * Regenerates showcases.json and gigs.json in this folder from team31-app's own
 * code (ProviderEmbedder, Taxonomy::tags(), MatcherClient::gigRequest()), so the
 * expected values are what our integration actually produces. Run from the
 * team31-app root:
 *
 *     php vendor/bin/phpunit handover/testing/fixtures/MakeFixturesTest.php
 *
 * SenseiGigz don't need to run this: they compare their own output with the
 * JSON files (see ../README.md).
 *
 * @internal
 */
final class MakeFixturesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TaxonomyFixtureTrait;

    protected $namespace = null; // run App and Shield migrations
    protected $refresh   = true;

    /** Showcase form inputs: Quill HTML where our form uses rich text. */
    private const SHOWCASES = [
        ['id' => 9001, 'case' => 'typical profile with rich text',
            'headline' => 'Fractional CFO for growth-stage startups', 'years_experience' => 15,
            'about'    => '<p>I help <strong>Series A-B</strong> companies build their finance function.</p><p>From monthly close to board reporting.</p>',
            'services' => [
                ['title' => 'Financial planning and analysis', 'description' => '<p>Three-statement models and budgets.</p>'],
                ['title' => 'Fundraising support', 'description' => '<p>Investor data rooms and due diligence.</p>'],
            ],
            'achievements' => ['<p>Led finance through a S$40m Series B.</p>', '<p>Set up FP&amp;A at two SaaS firms.</p>']],
        ['id' => 9002, 'case' => 'lists, line breaks, HTML entities and non-breaking spaces',
            'headline' => 'Internal audit & risk advisor', 'years_experience' => 12,
            'about'    => '<p>Former Big Four audit manager.<br>Now independent.</p><ol><li>Internal controls</li><li>Risk frameworks</li><li></li></ol><p>Clients&nbsp;include SGX&#39;s listed groups.</p>',
            'services' => [['title' => 'Internal audit', 'description' => '<ul><li>Control testing</li><li>Fraud&nbsp;risk</li></ul>']],
            'achievements' => ['<p>Ran internal audit</p><p>across five countries.</p>']],
        ['id' => 9003, 'case' => 'headline only: no bio, services or achievements',
            'headline' => 'Cloud security architect', 'years_experience' => null,
            'about'    => null, 'services' => [], 'achievements' => []],
        ['id' => 9004, 'case' => 'services with an empty title or no description; empty achievement',
            'headline' => 'M&A advisor', 'years_experience' => 20,
            'about'    => '<p>Buy-side and sell-side deals in Southeast Asia.</p>',
            'services' => [
                ['title' => 'Transaction due diligence', 'description' => null],
                ['title' => '   ', 'description' => '<p>Valuation models.</p>'],
                ['title' => 'Post-deal integration', 'description' => '<p></p>'],
            ],
            'achievements' => ['<p>Closed 30 deals.</p>', '<p>   </p>', 'Advised on a US$2bn merger.']],
        ['id' => 9005, 'case' => 'non-English text, emoji and smart quotes',
            'headline' => '会计师 · Akauntan bertauliah 📊', 'years_experience' => 8,
            'about'    => '<p>Saya membantu PKS dengan “cukai” dan audit — 中小企业财务。</p>',
            'services' => [['title' => 'Cukai korporat', 'description' => '<p>GST &amp; cukai pendapatan.</p>']],
            'achievements' => ['<p>Menjimatkan 20% kos cukai untuk 50 pelanggan.</p>']],
        ['id' => 9006, 'case' => 'surrounding whitespace and blank lines everywhere',
            'headline' => "   Marketing strategist \n", 'years_experience' => 5,
            'about'    => "<p>   I build brand plans.   </p><p></p><p>\n\nFor consumer startups.</p>",
            'services' => [['title' => "  Growth marketing\t", 'description' => '<p>  Paid and organic.  </p>']],
            'achievements' => ["<p>Grew a D2C brand\nfrom zero to 100k customers.</p>"]],
    ];

    /**
     * Gig inputs: tags as (category, specialisation, skills) in the order a user
     * might pick them. Every combination exists in the real SkillsFuture sf_v1
     * taxonomy (unlike tests/_support/TaxonomyFixtureTrait's small one), so
     * integrators can recreate these gigs in their own database.
     */
    private const GIGS = [
        ['id' => 9101, 'case' => 'typical gig, two categories picked out of order, skills unsorted',
            'title' => 'Interim finance lead for a regional group', 'summary' => 'Prepare transfer pricing documentation and support the year-end audit.',
            'additional_notes' => 'Budget flexible.',
            'tags' => [
                ['Infocomm Technology', 'Data and Artificial Intelligence', ['Data Analytics']],
                ['Accountancy', 'Tax', ['Transfer Pricing', 'Tax Compliance']],
                ['Accountancy', 'Assurance', ['Financial Statements Analysis', 'Auditing and Assurance Standards']],
            ]],
        ['id' => 9102, 'case' => 'no tags at all',
            'title' => 'Help with our company accounts', 'summary' => 'Year-end accounts for a small firm.', 'additional_notes' => null,
            'tags' => []],
        ['id' => 9103, 'case' => 'specialisation with no skills chosen',
            'title' => 'Management accountant', 'summary' => 'Monthly management packs.', 'additional_notes' => null,
            'tags' => [['Accountancy', 'Management Accounting', []]]],
        ['id' => 9104, 'case' => 'title and summary with surrounding whitespace',
            'title' => "  Data analytics lead \n", 'summary' => "\n Build our first dashboards.\n\n", 'additional_notes' => null,
            'tags' => [['Infocomm Technology', 'Data and Artificial Intelligence', ['Data Analytics', 'Cloud Computing']]]],
        ['id' => 9105, 'case' => 'the same specialisation name under two categories; the maximum of 8 skills',
            'title' => 'Project manager for a shipyard upgrade', 'summary' => 'Run the construction programme for a shipyard extension.', 'additional_notes' => null,
            'tags' => [
                ['Marine and Offshore', 'Project Management', []],
                ['Built Environment', 'Project Management', ['Dispute Resolution', 'Asset Management', 'Design for Safety', 'Critical Thinking',
                    'Construction Technology', 'Business Development', 'Data Collection and Analysis', 'Continuous Improvement Management']],
            ]],
    ];

    /** The sf_v1 rows the gigs use: each specialisation with the skills the gigs pick (all real sf_v1 links). */
    private function loadRealTaxonomySubset(): void
    {
        $tree = [];
        foreach (self::GIGS as $g) {
            foreach ($g['tags'] as [$category, $specialisation, $skills]) {
                $tree[$category][$specialisation] = array_values(array_unique(array_merge($tree[$category][$specialisation] ?? [], $skills)));
            }
        }
        (new Taxonomy($this->db))->import(array_map(
            static fn (string $category, array $specs): array => ['name' => $category, 'specialisations' => array_map(
                static fn (string $spec, array $skills): array => ['name' => $spec, 'skills' => $skills], array_keys($specs), $specs)],
            array_keys($tree), $tree,
        ));
    }

    public function testWritesTheFixtures(): void
    {
        $this->loadRealTaxonomySubset();
        $dir = __DIR__ . '/';

        $showcases = [];
        foreach (self::SHOWCASES as $s) {
            $fields = ProviderEmbedder::fieldsFromShowcase($s, $s['services'], $s['achievements'])
                + ['years_experience' => $s['years_experience']];
            $showcases[] = [
                'id'       => $s['id'],
                'case'     => $s['case'],
                'input'    => array_diff_key($s, ['id' => 0, 'case' => 0]),
                'expected' => [
                    'fields_json'   => $fields,
                    'provider_text' => ProviderEmbedder::providerText($fields),
                    'text_hash'     => ProviderEmbedder::textHash($fields),
                ],
            ];
        }

        $user = fake(UserModel::class);
        $gigs = [];
        foreach (self::GIGS as $g) {
            $selection = ['specialisations' => [], 'skills' => []];
            foreach ($g['tags'] as [$category, $specialisation, $skills]) {
                $specId                        = $this->specId($category, $specialisation);
                $selection['specialisations'][] = $specId;
                if ($skills !== []) {
                    $selection['skills'][$specId] = array_map($this->skillId(...), $skills);
                }
            }
            $gigId   = (new GigModel())->createWithTaxonomy(
                ['user_id' => $user->id, 'title' => $g['title'], 'summary' => $g['summary'], 'additional_notes' => $g['additional_notes']],
                $selection,
            );
            $request = MatcherClient::gigRequest((new GigModel())->find($gigId), (new Taxonomy($this->db))->tags('gig', [$gigId])[$gigId] ?? []);
            $request['hire_id'] = $g['id']; // the fixture's id, not this test database's

            $gigs[] = [
                'id'       => $g['id'],
                'case'     => $g['case'],
                'input'    => ['title' => $g['title'], 'summary' => $g['summary'], 'additional_notes' => $g['additional_notes'],
                    'tags' => array_map(static fn (array $t): array => ['category' => $t[0], 'specialisation' => $t[1], 'skills' => $t[2]], $g['tags'])],
                'expected' => ['match_request' => $request],
            ];
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($dir . 'showcases.json', json_encode($showcases, $flags) . "\n");
        file_put_contents($dir . 'gigs.json', json_encode($gigs, $flags) . "\n");

        $this->assertCount(count(self::SHOWCASES), json_decode((string) file_get_contents($dir . 'showcases.json'), true));
        $this->assertCount(count(self::GIGS), json_decode((string) file_get_contents($dir . 'gigs.json'), true));
    }
}
