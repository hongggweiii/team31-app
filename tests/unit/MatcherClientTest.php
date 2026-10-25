<?php

use App\Libraries\MatcherClient;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App;
use Config\Matcher;

/**
 * @internal
 */
final class MatcherClientTest extends CIUnitTestCase
{
    private MockCURLRequest $client;

    /**
     * A client whose HTTP layer replays $status/$body instead of calling matcher/.
     */
    private function matcher(int $status, array|string $body): MatcherClient
    {
        $config = new Matcher();

        $this->client = new MockCURLRequest(config(App::class), new URI($config->baseURL), null, ['baseURI' => $config->baseURL]);
        $this->client->setOutput(
            "HTTP/1.1 {$status} Status\r\nContent-Type: application/json\r\n\r\n"
            . (is_array($body) ? json_encode($body) : $body),
        );

        return new MatcherClient($config, $this->client);
    }

    private static function taxonomy(): array
    {
        return [['name' => 'Finance', 'group' => null, 'specialisations' => [
            ['name' => 'Due Diligence', 'search_tag' => 'Due Diligence (Finance)', 'skills' => []],
        ]]];
    }

    public function testBuildsTheRankersRequestFromAGig(): void
    {
        $request = MatcherClient::gigRequest(
            ['id' => '42', 'user_id' => 3, 'title' => ' Interim CFO ', 'summary' => "Lead finance for six months.\n", 'status' => 'open'],
            self::taxonomy(),
        );

        // API_IO_SPEC.md: these keys, in this order; hire_id is an integer.
        $this->assertSame([
            'hire_id'          => 42,
            'hire_title'       => 'Interim CFO',
            'hire_description' => 'Lead finance for six months.',
            'industry'         => null,
            'taxonomy'         => self::taxonomy(),
            'top_k'            => 10,
        ], $request);
        $this->assertSame(5, MatcherClient::gigRequest(['id' => 1, 'title' => 't', 'summary' => 's'], [], 5)['top_k']);
    }

    public function testPostsTheRequestAndReturnsTheResponse(): void
    {
        $request  = MatcherClient::gigRequest(['id' => 42, 'title' => 'Interim CFO', 'summary' => 'Lead finance.'], self::taxonomy());
        $response = [
            'results' => [['rank' => 1, 'provider_id' => 7, 'score' => 87.5, 'raw_margin' => 1.25]],
            'meta'    => ['returned' => 1],
        ];

        $this->assertSame($response, $this->matcher(200, $response)->match($request));
        $this->assertSame('http://localhost:8002/match', $this->client->curl_options[CURLOPT_URL]);
        $this->assertSame(json_encode($request), $this->client->curl_options[CURLOPT_POSTFIELDS]);
    }

    public function testRaisesWithTheStatusWhenMatchingFails(): void
    {
        $matcher = $this->matcher(503, ['detail' => 'embedding-service unavailable']);

        try {
            $matcher->match(MatcherClient::gigRequest(['id' => 42, 'title' => 't', 'summary' => 's'], []));
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertStringContainsString('embedding-service unavailable', $e->getMessage());
        }
    }

    public function testRejectsAResponseWithoutResults(): void
    {
        $matcher = $this->matcher(200, ['detail' => 'oops']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no results');

        $matcher->match(MatcherClient::gigRequest(['id' => 42, 'title' => 't', 'summary' => 's'], []));
    }
}
