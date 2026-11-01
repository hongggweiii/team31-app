<?php

namespace App\Controllers;

use App\Libraries\Taxonomy;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use RuntimeException;

/**
 * The taxonomy dashboard: approve or reject the terms taxonomy-service/ holds
 * as pending (AI suggestion on the gig / showcase forms puts them there), and
 * edit the SkillsFuture taxonomy. Behind the session filter like the app's other
 * pages; no role is checked.
 */
class TaxonomyDashboard extends BaseController
{
    private const STATUSES = ['pending_review' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    public function proposals(): string
    {
        $status    = (string) $this->request->getGet('status');
        $status    = isset(self::STATUSES[$status]) ? $status : 'pending_review';
        $proposals = null;

        try {
            $proposals = service('taxonomyClient')->proposals($status);
        } catch (RuntimeException $e) {
            log_message('error', 'Listing taxonomy proposals failed: {message}', ['message' => $e->getMessage()]);
        }

        return view('taxonomy-dashboard/proposals', [
            'tab' => 'proposals', 'status' => $status, 'statuses' => self::STATUSES, 'proposals' => $proposals,
        ]);
    }

    /**
     * A row's buttons post approve=<id> or reject=<id>.
     */
    public function review(): RedirectResponse
    {
        $post = $this->request->getPost();
        if (ctype_digit((string) ($post['approve'] ?? ''))) {
            [$action, $ids] = ['approve', [(int) $post['approve']]];
        } elseif (ctype_digit((string) ($post['reject'] ?? ''))) {
            [$action, $ids] = ['reject', [(int) $post['reject']]];
        } else {
            return redirect()->back()->with('errors', ['Choose a proposal to approve or reject.']);
        }

        $client = service('taxonomyClient');
        $done   = 0;
        $notes  = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                if ($action === 'approve') {
                    $result = $client->approve($id, auth()->id());
                    $done += count($result['approved']);
                    foreach ($result['skipped'] as $skipped) {
                        $notes[] = "\"{$result['item']['name']}\" was not added to {$skipped['owner_type']} #{$skipped['owner_id']}: {$skipped['reason']}.";
                    }
                } else {
                    $result = $client->reject($id, auth()->id(), '');
                    $done += count($result['rejected']);
                    if ($result['notified'] !== []) {
                        $notes[] = 'Notified user(s) ' . implode(', ', array_unique(array_column($result['notified'], 'user_id'))) . '.';
                    }
                }
            } catch (RuntimeException $e) {
                // 409: already reviewed, e.g. together with an earlier row holding the same term.
                if ($e->getCode() !== 409) {
                    $errors[] = "Proposal #{$id}: {$e->getMessage()}";
                }
            }
        }
        if ($action === 'approve' && $done > 0) {
            (new Taxonomy())->forgetTree(); // the service added to the sf_* tables
        }

        $redirect = redirect()->back()->with('notes', $notes)->with('errors', $errors);

        return $done > 0 ? $redirect->with('message', ($action === 'approve' ? 'Approved' : 'Rejected') . " {$done} proposal(s).") : $redirect;
    }

    public function taxonomy(): string
    {
        $taxonomy = new Taxonomy();

        return view('taxonomy-dashboard/taxonomy', ['tab' => 'taxonomy', 'tree' => $taxonomy->tree(), 'usage' => $taxonomy->usage()]);
    }

    /**
     * action=add (kind, name, parent_id), rename (kind, id, name) or remove
     * (kind, id, and for a skill the specialisation_id to remove it from).
     */
    public function edit(): RedirectResponse
    {
        $post     = $this->request->getPost();
        $action   = (string) ($post['action'] ?? '');
        $kind     = (string) ($post['kind'] ?? '');
        $optional = static fn (string $key): ?int => ctype_digit((string) ($post[$key] ?? '')) ? (int) $post[$key] : null;

        try {
            match ($action) {
                'add'    => (new Taxonomy())->add($kind, (string) ($post['name'] ?? ''), $optional('parent_id')),
                'rename' => (new Taxonomy())->rename($kind, (int) $optional('id'), (string) ($post['name'] ?? '')),
                'remove' => (new Taxonomy())->remove($kind, (int) $optional('id'), $optional('specialisation_id')),
                default  => throw new InvalidArgumentException('Unknown action.'),
            };
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('errors', [$e->getMessage()]);
        }

        $redirect = redirect()->back()->with('message', ['add' => 'Added.', 'rename' => 'Renamed.', 'remove' => 'Removed.'][$action]);

        // Pending proposals are re-checked against the edited taxonomy.
        try {
            service('taxonomyClient')->refresh();
        } catch (RuntimeException $e) {
            return $redirect->with('errors', ['Saved, but the taxonomy service could not re-check pending proposals: ' . $e->getMessage()]);
        }

        return $redirect;
    }
}
