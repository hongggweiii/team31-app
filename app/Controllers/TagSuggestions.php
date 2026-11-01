<?php

namespace App\Controllers;

use App\Libraries\ProviderEmbedder;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * AI tag suggestion for the gig and showcase forms: the taxonomy picker's
 * "Suggest tags with AI" button posts the form as filled in so far. Answers
 * JSON: the taxonomy's tags to tick, and the terms it lacks, which
 * taxonomy-service/ has already stored as pending_review proposals. The form
 * posts their ids back as proposals[], and create() links them to the saved
 * gig / showcase (BaseController::attachSuggestedTerms()).
 */
class TagSuggestions extends BaseController
{
    public function suggest(): ResponseInterface
    {
        $post = $this->request->getPost();
        $type = (string) ($post['type'] ?? '');

        if ($type === 'gig') {
            $title = trim((string) ($post['gig_title'] ?? ''));
            $text  = trim(($post['gig_summary'] ?? '') . "\n" . ($post['additional_notes'] ?? ''));
        } elseif ($type === 'showcase') {
            // What a saved showcase is embedded from: headline, bio, services, achievements.
            $services = array_map(static fn (array $s): array => ['title' => (string) ($s['title'] ?? ''), 'description' => (string) ($s['detail'] ?? '')],
                array_values(array_filter((array) ($post['services'] ?? []), 'is_array')));
            $title = trim((string) ($post['title'] ?? ''));
            $text  = ProviderEmbedder::providerText(ProviderEmbedder::fieldsFromShowcase(
                ['headline' => $post['headline'] ?? '', 'about' => $post['about'] ?? ''],
                $services,
                array_values(array_filter((array) ($post['achievements'] ?? []), 'is_string')),
            ));
        } else {
            return $this->answer(400, ['error' => 'Unknown form.']);
        }
        if ($text === '') {
            return $this->answer(422, ['error' => $type === 'gig'
                ? 'Write the gig description first, then ask for suggestions.'
                : 'Fill in your headline, bio or services first, then ask for suggestions.']);
        }

        try {
            $result = service('taxonomyClient')->suggest($type, null, auth()->id(), $title, $text);
        } catch (RuntimeException $e) {
            log_message('error', 'Tag suggestion failed: {message}', ['message' => $e->getMessage()]);

            return $this->answer(503, ['error' => 'Suggestions are unavailable right now.']);
        }

        return $this->answer(200, [
            'tags'    => $result['tags'],
            'pending' => array_map(static fn (array $p): array => ['id' => $p['id'], 'name' => $p['proposed_name'], 'kind' => $p['kind']], $result['pending']),
        ]);
    }

    /**
     * JSON, with the CSRF token the form must send next: it changes after every POST.
     */
    private function answer(int $status, array $body): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($body + ['csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
    }
}
