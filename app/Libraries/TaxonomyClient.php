<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\Taxonomy as TaxonomyConfig;
use RuntimeException;

/**
 * Calls taxonomy-service/: AI tag suggestion for a gig / showcase, and the
 * review of the terms the taxonomy doesn't have yet (taxonomy_proposals rows).
 * taxonomy-service/app.py documents each endpoint.
 */
class TaxonomyClient
{
    private TaxonomyConfig $config;
    private CURLRequest $client;

    public function __construct(?TaxonomyConfig $config = null, ?CURLRequest $client = null)
    {
        $this->config = $config ?? config(TaxonomyConfig::class);
        $this->client = $client ?? single_service('curlrequest', [
            'baseURI' => $this->config->baseURL,
            'timeout' => $this->config->timeout,
        ]);
    }

    /**
     * Tags for a gig / showcase's text. Terms the taxonomy lacks are stored as
     * pending proposals for it; before it is saved ($ownerId null), for
     * $userId, until attach() links them to it.
     *
     * @return array{tags: list<array>, pending: list<array>, model: string}
     *         tags in Taxonomy::tags()'s shape plus ids and confidence; pending: taxonomy_proposals rows
     */
    public function suggest(string $ownerType, ?int $ownerId, ?int $userId, string $title, string $text): array
    {
        return $this->send('POST', 'suggest', [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'user_id' => $userId, 'title' => $title, 'text' => $text,
        ]);
    }

    /**
     * Links pending proposals suggest() made before the gig / showcase was saved to it.
     *
     * @param list<int> $proposalIds
     *
     * @return array{attached: list<int>}
     */
    public function attach(array $proposalIds, string $ownerType, int $ownerId, ?int $userId): array
    {
        return $this->send('POST', 'proposals/attach', [
            'proposal_ids' => $proposalIds, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'user_id' => $userId,
        ]);
    }

    /**
     * @param 'approved'|'pending_review'|'rejected' $status
     *
     * @return list<array<string, mixed>> taxonomy_proposals rows, newest first; pending ones list their `duplicates`
     */
    public function proposals(string $status = 'pending_review'): array
    {
        return $this->send('GET', 'proposals', query: ['status' => $status])['proposals'];
    }

    /**
     * @return array{approved: list<int>, item: array, attached: list<array>, skipped: list<array>}
     */
    public function approve(int $proposalId, ?int $adminUserId): array
    {
        return $this->send('POST', "proposals/{$proposalId}/approve", ['admin_user_id' => $adminUserId]);
    }

    /**
     * @return array{rejected: list<int>, detached: list<array>, notified: list<array>}
     */
    public function reject(int $proposalId, ?int $adminUserId, string $reason): array
    {
        return $this->send('POST', "proposals/{$proposalId}/reject", [
            'admin_user_id' => $adminUserId, 'reason' => $reason !== '' ? $reason : null,
        ]);
    }

    /**
     * After editing the sf_* tables: the service reloads them and re-checks
     * pending proposals against them.
     */
    public function refresh(): array
    {
        return $this->send('POST', 'refresh');
    }

    /**
     * @throws RuntimeException When the service is unreachable or answers with an error
     *                          (the exception code is the HTTP status; 409: already reviewed)
     */
    private function send(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $options = ['http_errors' => false];
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($this->config->serviceToken !== '') {
            $options['headers'] = ['X-Service-Token' => $this->config->serviceToken];
        }

        $response = $this->client->request($method, $path, $options);
        $status   = $response->getStatusCode();
        $body     = (string) $response->getBody();
        $data     = json_decode($body, true);

        if ($status !== 200 || ! is_array($data)) {
            $detail = is_array($data) && is_string($data['detail'] ?? null) ? $data['detail'] : "HTTP {$status}: {$body}";

            throw new RuntimeException($detail, $status);
        }

        return $data;
    }
}
