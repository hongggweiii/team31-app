<?php

namespace App\Controllers;

use App\Models\GigModel;
use App\Libraries\MatcherClient;
use App\Libraries\MatcherException;
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

    /**
     * Flashdata create() leaves for the matches page it redirects to: the new
     * gig and its tags, so that page doesn't read them back from the database.
     */
    private const NEW_GIG_FLASH = 'new_gig';

    /**
     * The "Post a Job" form, with the SkillsFuture picker built from the
     * cached taxonomy tree (no taxonomy queries per page load).
     *
     * GET /create-gig
     *
     * @return string The rendered create-gig view
     */
    public function new(): string
    {
        return view('create-gig', [
            'taxonomy' => (new Taxonomy())->cachedTree(),
        ]);
    }

    /**
     * Saves a posted gig with its tags in one transaction, then redirects to
     * its matches page. The new gig and its tags travel with the redirect as
     * flashdata, so matches() can call matcher/ without reading them back
     * from the (remote) database.
     *
     * POST /create-gig. Reads gig_title, gig_summary, duration_weeks_min/max,
     * additional_notes, and the picker's categories[], specialisations[] and
     * skills[<specialisation id>][].
     *
     * @return RedirectResponse To the gig's matches page; or back to the form with
     *                          the input and an 'errors' flash (field => message)
     */
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
        $valid      = $this->validate($rules);
        $taxonomies = new Taxonomy();
        $taxonomy   = $taxonomies->check(
            $this->request->getPost('categories'),
            $this->request->getPost('specialisations'),
            $this->request->getPost('skills'),
        );
        if (! $valid || $taxonomy['errors'] !== []) {
            return redirect()->back()->withInput()->with('errors', $taxonomy['errors']);
        }

        $data = $this->validator->getValidated();

        // Durations: '' (not given) -> null. One duration given means a fixed
        // length: schema v2 stores it as both the minimum and the maximum.
        $minWeeks = ($data['duration_weeks_min'] ?? '') !== '' ? (int) $data['duration_weeks_min'] : null;
        $maxWeeks = ($data['duration_weeks_max'] ?? '') !== '' ? (int) $data['duration_weeks_max'] : null;
        $minWeeks ??= $maxWeeks;
        $maxWeeks ??= $minWeeks;
        if ($minWeeks > $maxWeeks) {
            return redirect()->back()->withInput()
                ->with('errors', ['duration_weeks_max' => 'The maximum duration can\'t be shorter than the minimum.']);
        }
        $notes = trim((string) ($data['additional_notes'] ?? ''));

        $gig = [
            'user_id'            => auth()->id(),
            'title'              => trim($data['gig_title']),
            'summary'            => trim($data['gig_summary']),
            'duration_weeks_min' => $minWeeks,
            'duration_weeks_max' => $maxWeeks,
            'additional_notes'   => $notes !== '' ? $notes : null,
        ];
        // The gig row and its gig_specialisations / gig_skills, in one transaction.
        $gigId = $gigs->createWithTaxonomy($gig, $taxonomy['selection']);

        if ($gigId === false) {
            return redirect()->back()->withInput()
                ->with('errors', $gigs->errors() ?: ['gig' => 'Your gig could not be saved. Please try again.']);
        }
        $this->attachSuggestedTerms('gig', $gigId);

        return redirect()->to(url_to('gig-matches', $gigId))
            ->with('message', 'Your gig has been posted.')
            ->with(self::NEW_GIG_FLASH, [
                'gig'      => ['id' => $gigId, ...$gig],
                'taxonomy' => $taxonomies->selectionTags($taxonomy['selection']),
            ]);
    }

    /**
     * The showcases that best match one of the hirer's gigs. matcher/ embeds the
     * gig live and ranks every published showcase with the ranker's pipeline,
     * within MatcherClient's 5 s timeout; it saves the gig's vector afterwards.
     *
     * GET /gigs/{id}/matches. Matching failures never fail the page: the view
     * gets $matches = null and $error = the MatcherException reason.
     *
     * @param int $gigId From the URL
     *
     * @return string The rendered gig-matches view
     *
     * @throws PageNotFoundException When the gig doesn't exist or belongs to another user
     */
    public function matches(int $gigId): string
    {
        // Straight after create(): the gig and its tags came with the redirect.
        // Only used when it is this gig (a stale flash from another tab is ignored).
        $new  = session()->getFlashdata(self::NEW_GIG_FLASH);
        $gigs = new GigModel();
        $tags = null;
        if (is_array($new) && ($new['gig']['id'] ?? null) === $gigId) {
            ['gig' => $gig, 'taxonomy' => $tags] = $new;
        } else {
            $gig = $gigs->find($gigId);
        }
        if ($gig === null || (string) $gig['user_id'] !== (string) auth()->id()) {
            throw PageNotFoundException::forPageNotFound();
        }
        // A refresh or a later visit: read the tags (2 queries) instead.
        $tags ??= $gigs->rankerTaxonomy($gigId);

        $matches = null;
        $error   = null; // a MatcherException reason when matching failed

        try {
            $matches = service('matcherClient')->match(MatcherClient::gigRequest($gig, $tags));
        } catch (RuntimeException $e) {
            // The gig is saved either way: the page says why there are no matches and offers a retry.
            $error = $e instanceof MatcherException ? $e->reason() : MatcherException::UNAVAILABLE;
            log_message('error', 'Matching gig {id} failed ({reason}): {message}', ['id' => $gigId, 'reason' => $error, 'message' => $e->getMessage()]);
        }

        return view('gig-matches', ['gig' => $gig, 'matches' => $matches, 'error' => $error]);
    }
}
