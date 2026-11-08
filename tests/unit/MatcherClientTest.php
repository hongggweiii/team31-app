<?php

use App\Libraries\MatcherClient;
use App\Libraries\MatcherException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
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
            'results' => [['rank' => 1, 'provider_id' => 7, 'score' => 87.5, 'raw_margin' => 1.25, 'provider' => ['display_title' => 'CFO']]],
            'meta'    => ['returned' => 1],
        ];

        $this->assertSame($response, $this->matcher(200, $response)->match($request));
        $this->assertSame('http://localhost:8002/match', $this->client->curl_options[CURLOPT_URL]);
        $this->assertSame(json_encode($request), $this->client->curl_options[CURLOPT_POSTFIELDS]);
    }

    /**
     * The MatcherException match() throws for $client, or fails the test.
     */
    private function failure(MatcherClient $matcher, array $gig = ['id' => 42, 'title' => 't', 'summary' => 's']): MatcherException
    {
        try {
            $matcher->match(MatcherClient::gigRequest($gig, []));
        } catch (MatcherException $e) {
            return $e;
        }
        $this->fail('Expected a MatcherException');
    }

    public function testAnErrorStatusIsUnavailableAndRetryable(): void
    {
        $e = $this->failure($this->matcher(503, ['detail' => 'embedding-service unavailable']));

        $this->assertSame(MatcherException::UNAVAILABLE, $e->reason());
        $this->assertTrue($e->retryable());
        $this->assertSame(503, $e->getCode());
        $this->assertStringContainsString('embedding-service unavailable', $e->getMessage());
    }

    public function testARefusedRequestIsRejectedAndNotRetryable(): void
    {
        $e = $this->failure($this->matcher(422, ['detail' => [['msg' => 'Value error']]]));

        $this->assertSame(MatcherException::REJECTED, $e->reason());
        $this->assertFalse($e->retryable());
        $this->assertSame(422, $e->getCode());
    }

    public function testAGigWithoutTextIsRejectedWithoutCallingTheMatcher(): void
    {
        $e = $this->failure($this->matcher(200, ['results' => []]), ['id' => 42, 'title' => ' ', 'summary' => "\n"]);

        $this->assertSame(MatcherException::REJECTED, $e->reason());
        $this->assertEmpty($this->client->curl_options ?? []); // no request was sent
    }

    public function testAnUnreachableMatcherIsUnavailable(): void
    {
        $config = new Matcher();
        $client = new class (config(App::class), new URI($config->baseURL), null, ['baseURI' => $config->baseURL]) extends MockCURLRequest {
            public function request($method, string $url, array $options = []): ResponseInterface
            {
                throw HTTPException::forCurlError('7', 'Failed to connect to localhost port 8002');
            }
        };

        $e = $this->failure(new MatcherClient($config, $client));

        $this->assertSame(MatcherException::UNAVAILABLE, $e->reason());
        $this->assertSame(0, $e->getCode());
    }

    public function testAResponseWithoutResultsIsABadResponse(): void
    {
        $e = $this->failure($this->matcher(200, ['detail' => 'oops']));

        $this->assertSame(MatcherException::BAD_RESPONSE, $e->reason());
        $this->assertStringContainsString('no results', $e->getMessage());
    }

    public function testAResponseThatIsNotJsonIsABadResponse(): void
    {
        $this->assertSame(MatcherException::BAD_RESPONSE, $this->failure($this->matcher(200, '<html>Bad gateway</html>'))->reason());
    }

    public function testDropsResultsThePageCouldNotShow(): void
    {
        $good     = ['rank' => 2, 'provider_id' => 8, 'score' => 50, 'raw_margin' => 0.5, 'provider' => []];
        $response = ['results' => [
            ['rank' => 1, 'score' => 100, 'provider' => []], // no provider_id
            'not a result',
            ['rank' => '3', 'provider_id' => 9, 'score' => 10, 'provider' => []], // rank not an integer
            $good,
        ], 'meta' => []];

        $results = $this->matcher(200, $response)->match(MatcherClient::gigRequest(['id' => 42, 'title' => 't', 'summary' => 's'], []))['results'];

        $this->assertSame([$good], $results);
    }
}
