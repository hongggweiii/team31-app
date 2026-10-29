<?php

namespace App\Controllers;

use App\Models\GigModel;
use App\Libraries\MatcherClient;
use App\Libraries\Taxonomy;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * Hirers post gigs here; the route is behind Shield's session filter.
 */
class Gigs extends BaseController
{
    /** Longest engagement a hirer can enter, in weeks. */
    private const MAX_DURATION_WEEKS = 52;

    public function new(): string
    {
        return view('create-gig', [
            'taxonomy' => (new Taxonomy())->cachedTree(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $gigs = new GigModel();

        $rules = [
            'gig_title'          => ['label' => 'Gig Title', 'rules' => 'required|max_length[500]'],
            'gig_summary'        => ['label' => 'Short Gig Description', 'rules' => 'required'],
            'duration_weeks_min' => ['label' => 'Minimum duration', 'rules' => 'permit_empty|is_natural_no_zero|less_than_equal_to[' . self::MAX_DURATION_WEEKS . ']'],
            'duration_weeks_max' => ['label' => 'Maximum duration', 'rules' => 'permit_empty|is_natural_no_zero|less_than_equal_to[' . self::MAX_DURATION_WEEKS . ']'],
            'additional_notes'   => ['label' => 'Additional Notes', 'rules' => 'permit_empty|max_length[2000]'],
        ];

        // Checked together so every problem is reported at once.
        $valid    = $this->validate($rules);
        $taxonomy = (new Taxonomy())->check(
            $this->request->getPost('categories'),
            $this->request->getPost('specialisations'),
            $this->request->getPost('skills'),
        );
        if (! $valid || $taxonomy['errors'] !== []) {
            return redirect()->back()->withInput()->with('errors', $taxonomy['errors']);
        }

        $data = $this->validator->getValidated();

        // One duration given means a fixed length: schema v2 stores it as both
        // the minimum and the maximum.
        $minWeeks = ($data['duration_weeks_min'] ?? '') !== '' ? (int) $data['duration_weeks_min'] : null;
        $maxWeeks = ($data['duration_weeks_max'] ?? '') !== '' ? (int) $data['duration_weeks_max'] : null;
        $minWeeks ??= $maxWeeks;
        $maxWeeks ??= $minWeeks;
        if ($minWeeks > $maxWeeks) {
            return redirect()->back()->withInput()
                ->with('errors', ['duration_weeks_max' => 'The maximum duration can\'t be shorter than the minimum.']);
        }
        $notes = trim((string) ($data['additional_notes'] ?? ''));

        $gigId = $gigs->createWithTaxonomy(
            [
                'user_id'            => auth()->id(),
                'title'              => trim($data['gig_title']),
                'summary'            => trim($data['gig_summary']),
                'duration_weeks_min' => $minWeeks,
                'duration_weeks_max' => $maxWeeks,
                'additional_notes'   => $notes !== '' ? $notes : null,
            ],
            $taxonomy['selection'],
        );

        if ($gigId === false) {
            return redirect()->back()->withInput()
                ->with('errors', $gigs->errors() ?: ['gig' => 'Your gig could not be saved. Please try again.']);
        }

        return redirect()->to(url_to('gig-matches', $gigId))->with('message', 'Your gig has been posted.');
    }

    /**
     * The showcases that best match one of the hirer's gigs. matcher/ embeds the
     * gig live and ranks every published showcase with the ranker's pipeline,
     * within MatcherClient's 5 s timeout; it saves the gig's vector afterwards.
     */
    public function matches(int $gigId): string
    {
        $gigs = new GigModel();
        $gig  = $gigs->find($gigId);
        if ($gig === null || (string) $gig['user_id'] !== (string) auth()->id()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $matches = null;

        try {
            $matches = service('matcherClient')->match(MatcherClient::gigRequest($gig, $gigs->rankerTaxonomy($gigId)));
        } catch (RuntimeException $e) {
            log_message('error', 'Matching gig {id} failed: {message}', ['id' => $gigId, 'message' => $e->getMessage()]);
        }

        return view('gig-matches', ['gig' => $gig, 'matches' => $matches]);
    }
}
