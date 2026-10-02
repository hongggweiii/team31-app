<?php

namespace App\Commands;

use App\Libraries\ProviderEmbedder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * Embeds a saved showcase through embedding-service/ and prints the result,
 * to check the service end to end. Read-only: nothing is saved or pushed.
 */
class EmbedShowcase extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'embed:showcase';
    protected $description = 'Embeds a showcase via embedding-service/ and prints the vector summary (saves nothing).';
    protected $usage       = 'embed:showcase <showcase_id>';
    protected $arguments   = ['showcase_id' => 'The showcases.id to embed'];

    public function run(array $params)
    {
        $id = (int) ($params[0] ?? 0);
        if ($id <= 0) {
            CLI::error('Usage: php spark ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $result = (new ProviderEmbedder())->embedShowcase($id);
        } catch (RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $vector = $result['embedding'];
        $norm   = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $vector)));

        CLI::write('Showcase:   ' . $result['showcase_id']);
        CLI::write('Model:      ' . $result['model']);
        CLI::write('Dimensions: ' . $result['dimensions']);
        CLI::write('Norm:       ' . number_format($norm, 4) . ' (should be ~1.0)');
        CLI::write('First 5:    ' . implode(', ', array_map(static fn (float $x): string => number_format($x, 5), array_slice($vector, 0, 5))));
        CLI::write('OK', 'green');

        return EXIT_SUCCESS;
    }
}
