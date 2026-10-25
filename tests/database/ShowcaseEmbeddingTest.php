<?php

use App\Libraries\ProviderEmbedder;
use App\Models\ShowcaseModel;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App;
use Config\Embedding;

/**
 * ProviderEmbedder::embedShowcase() against a showcase saved the way the
 * create-profile form saves it. The embedding service itself is mocked.
 *
 * @internal
 */
final class ShowcaseEmbeddingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null; // App and Shield migrations: showcases.user_id references users
    protected $refresh   = true;

    private MockCURLRequest $client;

    private function embedder(): ProviderEmbedder
    {
        $config             = new Embedding();
        $config->dimensions = 4;

        $this->client = new MockCURLRequest(config(App::class), new URI($config->baseURL), null, ['baseURI' => $config->baseURL]);
        $this->client->setOutput(
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n"
            . json_encode(['model' => 'm', 'dimensions' => 4, 'embedding' => [0.5, 0.5, 0.5, 0.5]]),
        );

        return new ProviderEmbedder($config, $this->client);
    }

    public function testEmbedsTheSavedShowcase(): void
    {
        $id = (new ShowcaseModel())->createWithChildren(
            [
                'user_id'    => fake(UserModel::class)->id,
                'name'       => 'Hong Wei Tai',
                'title'      => 'Financial Due Diligence',
                'years_experience' => 12, // stored for matcher/, never embedded
                'headline'   => 'Fractional CFO | Strategy & Finance Expert',
                'about'      => '<p>I drive transformation.</p>',
                'how_i_work' => '<p>Remote first</p>',
            ],
            [
                'services'     => [
                    ['title' => 'Due diligence', 'description' => '<p>Buy-side reviews</p>'],
                    ['title' => 'Valuation', 'description' => null],
                ],
                'achievements' => ['<p>Advised banks</p>', '<p>Led a S$50m raise</p>'],
                'skill_groups' => [['name' => 'Data', 'skills' => ['SAP']]],
                'documents'    => [],
            ],
        );

        $result = $this->embedder()->embedShowcase($id);

        $this->assertSame(['showcase_id' => $id, 'model' => 'm', 'dimensions' => 4, 'embedding' => [0.5, 0.5, 0.5, 0.5]], $result);
        $this->assertSame(
            json_encode([
                'about_title'                  => 'Fractional CFO | Strategy & Finance Expert',
                'about_description'            => 'I drive transformation.',
                'services_offered_title'       => 'Due diligence; Valuation',
                'services_offered_description' => 'Buy-side reviews',
                'relevant_experience'          => 'Advised banks Led a S$50m raise',
            ]),
            $this->client->curl_options[CURLOPT_POSTFIELDS],
        );
    }

    public function testRejectsAnUnknownShowcase(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Showcase 999 not found');

        $this->embedder()->embedShowcase(999);
    }
}
