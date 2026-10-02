<?php

use App\Libraries\ProviderEmbedder;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App;
use Config\Embedding;

/**
 * @internal
 */
final class ProviderEmbedderTest extends CIUnitTestCase
{
    private MockCURLRequest $client;

    /**
     * An embedder whose HTTP client replays $status/$body instead of calling
     * the real service. Dimensions are shrunk to 4 to keep fixtures readable.
     */
    private function embedder(int $status, array|string $body): ProviderEmbedder
    {
        $config             = new Embedding();
        $config->dimensions = 4;

        $this->client = new MockCURLRequest(config(App::class), new URI($config->baseURL), null, ['baseURI' => $config->baseURL]);
        $this->client->setOutput(
            "HTTP/1.1 {$status} Status\r\nContent-Type: application/json\r\n\r\n"
            . (is_array($body) ? json_encode($body) : $body),
        );

        return new ProviderEmbedder($config, $this->client);
    }

    public function testSendsOnlyTheFiveFieldsInRankerOrder(): void
    {
        $this->embedder(200, ['model' => 'm', 'dimensions' => 4, 'embedding' => [0.5, 0.5, 0.5, 0.5]])
            ->embed([
                'services_offered_title' => 'Interim CFO',
                'about_title'            => 'Fractional finance lead',
                'how_i_work'             => 'not embedded',
                'rate_per_hour'          => 200,
            ]);

        $this->assertSame('http://localhost:8001/embed/provider', $this->client->curl_options[CURLOPT_URL]);
        $this->assertSame(
            json_encode([
                'about_title'                  => 'Fractional finance lead',
                'about_description'            => null,
                'services_offered_title'       => 'Interim CFO',
                'services_offered_description' => null,
                'relevant_experience'          => null,
            ]),
            $this->client->curl_options[CURLOPT_POSTFIELDS],
        );
    }

    public function testReturnsTheVector(): void
    {
        $result = $this->embedder(200, [
            'model'      => 'mixedbread-ai/mxbai-embed-large-v1',
            'dimensions' => 4,
            'embedding'  => [0.5, -0.5, 0.5, -0.5],
        ])->embed(['about_title' => 'Fractional finance lead']);

        $this->assertSame([
            'model'      => 'mixedbread-ai/mxbai-embed-large-v1',
            'dimensions' => 4,
            'embedding'  => [0.5, -0.5, 0.5, -0.5],
        ], $result);
    }

    public function testRejectsAVectorOfTheWrongSize(): void
    {
        $embedder = $this->embedder(200, ['model' => 'm', 'dimensions' => 3, 'embedding' => [1.0, 0.0, 0.0]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned 3 dimensions, expected 4');

        $embedder->embed(['about_title' => 'Fractional finance lead']);
    }

    public function testSurfacesServiceErrors(): void
    {
        $embedder = $this->embedder(422, ['detail' => 'All provider text fields are empty']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 422');

        $embedder->embed([]);
    }

    public function testRejectsANonJsonResponse(): void
    {
        $embedder = $this->embedder(200, 'Internal Server Error');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no embedding');

        $embedder->embed(['about_title' => 'Fractional finance lead']);
    }

    public function testMapsAShowcaseOntoTheRankerFields(): void
    {
        $fields = ProviderEmbedder::fieldsFromShowcase(
            [
                'title'       => 'Financial Due Diligence',
                'credentials' => '<p>CFA</p>',
                'headline'    => ' Fractional CFO | Strategy & Finance Expert ',
                'about'       => '<p>I drive <strong>transformation</strong>.</p><p>Second&nbsp;para &amp; more</p>',
                'how_i_work'  => '<p>Remote first</p>',
            ],
            [
                ['title' => 'Due diligence', 'description' => '<p>Buy-side reviews</p>'],
                ['title' => 'Valuation', 'description' => null],
                ['title' => 'Modelling', 'description' => '<ol><li data-list="bullet">DCF</li><li data-list="bullet">LBO</li></ol>'],
            ],
            ['<p>Advised banks</p>', '', '<ol><li data-list="bullet">Led a S$50m raise</li><li data-list="bullet"><br></li></ol>'],
        );

        $this->assertSame([
            'about_title'                  => 'Fractional CFO | Strategy & Finance Expert',
            'about_description'            => "I drive transformation.\nSecond para & more",
            'services_offered_title'       => 'Due diligence; Valuation; Modelling',
            'services_offered_description' => "Buy-side reviews\n- DCF\n- LBO",
            'relevant_experience'          => "- Advised banks\n- Led a S\$50m raise",
        ], $fields);
    }

    public function testAnEmptyShowcaseMapsToEmptyFields(): void
    {
        $this->assertSame(
            array_fill_keys(ProviderEmbedder::FIELDS, ''),
            ProviderEmbedder::fieldsFromShowcase([], [], []),
        );
    }

    public function testEmbedsManyInOneRequestKeepingKeys(): void
    {
        $result = $this->embedder(200, [
            'model'      => 'm',
            'dimensions' => 4,
            'embeddings' => [[0.5, 0.5, 0.5, 0.5], [0.5, -0.5, 0.5, -0.5]],
        ])->embedMany([12 => ['about_title' => 'First'], 7 => ['about_title' => 'Second', 'rate' => 'ignored']]);

        $this->assertSame('http://localhost:8001/embed/providers', $this->client->curl_options[CURLOPT_URL]);
        $sent = json_decode($this->client->curl_options[CURLOPT_POSTFIELDS], true)['providers'];
        $this->assertSame(['First', 'Second'], array_column($sent, 'about_title'));
        $this->assertSame(ProviderEmbedder::FIELDS, array_keys($sent[1]));
        $this->assertSame(['model' => 'm', 'embeddings' => [12 => [0.5, 0.5, 0.5, 0.5], 7 => [0.5, -0.5, 0.5, -0.5]]], $result);
    }

    public function testEmbedManyRejectsAMissingVector(): void
    {
        $embedder = $this->embedder(200, ['model' => 'm', 'dimensions' => 4, 'embeddings' => [[1.0, 0.0, 0.0, 0.0]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned 1 embeddings for 2 providers');

        $embedder->embedMany([['about_title' => 'a'], ['about_title' => 'b']]);
    }

    public function testEmbedManyRejectsAVectorOfTheWrongSize(): void
    {
        $embedder = $this->embedder(200, ['model' => 'm', 'dimensions' => 4, 'embeddings' => [[1.0, 0.0, 0.0]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned 3 dimensions, expected 4');

        $embedder->embedMany([['about_title' => 'a']]);
    }

    public function testProviderTextMatchesTheServiceJoin(): void
    {
        // embedding.py provider_text: FIELDS in order, empty ones skipped, space-joined.
        $provider = [
            'relevant_experience' => "- Led a raise\n- Advised banks",
            'about_title'         => 'Fractional CFO',
            'about_description'   => '',
            'how_i_work'          => 'not embedded',
        ];

        $this->assertSame("Fractional CFO - Led a raise\n- Advised banks", ProviderEmbedder::providerText($provider));
        $this->assertSame(hash('sha256', ProviderEmbedder::providerText($provider)), ProviderEmbedder::textHash($provider));
        $this->assertNotSame(ProviderEmbedder::textHash($provider), ProviderEmbedder::textHash(['about_title' => 'Fractional CFO']));
    }
}
