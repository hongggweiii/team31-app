<?php

namespace App\Controllers;

/**
 * The matcher performance dashboard: the latest runs App\Libraries\MatchRecorder
 * saved (analytics_outputs, run_outputs). The route needs Shield's admin.access
 * permission (superadmin, admin, developer).
 */
class Dashboard extends BaseController
{
    /** How many of the latest runs the summary and charts cover. */
    private const RUNS = 50;

    /** Runs per page of the runs table. */
    private const PER_PAGE = 10;

    public function index(): string
    {
        $total = db_connect()->table('analytics_outputs')->countAllResults();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($pages, max(1, (int) $this->request->getGet('page')));

        $runs      = $this->runs(self::RUNS);
        $tableRuns = $this->runs(self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return view('dashboard', [
            'title'     => 'Matcher Dashboard — SenseiGigs',
            'runs'      => $runs,
            'tableRuns' => $tableRuns,
            'results'   => $this->results(array_column([...$runs, ...$tableRuns], 'id')),
            'limit'     => self::RUNS,
            'page'      => $page,
            'perPage'   => self::PER_PAGE,
            'pages'     => $pages,
            'total'     => $total,
        ]);
    }

    /**
     * analytics_outputs rows, newest first, with their gig's title.
     *
     * @return list<array<string, mixed>>
     */
    private function runs(int $limit, int $offset = 0): array
    {
        return db_connect()->table('analytics_outputs a')
            ->select('a.*, g.title AS gig_title')
            ->join('gigs g', 'g.id = a.gig_id')
            ->orderBy('a.id', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();
    }

    /**
     * run_outputs rows per analytics_output_id, by rank.
     *
     * @param list<int|string> $ids
     *
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function results(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $results = [];
        $rows    = db_connect()->table('run_outputs')
            ->whereIn('analytics_output_id', array_unique($ids))
            ->orderBy('rank')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $results[$row['analytics_output_id']][] = $row;
        }

        return $results;
    }
}
