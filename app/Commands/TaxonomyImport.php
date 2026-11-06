<?php

namespace App\Commands;

use App\Libraries\Taxonomy;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use JsonException;

/**
 * Loads the SkillsFuture taxonomy into the sf_* tables. Each file is either a
 * taxonomy file ({"taxonomy_version", "categories": [...]}) or a list of tagged
 * records (the Data team's providers.json / hirers.json), whose tags are merged
 * into one taxonomy. Additive and safe to re-run. The files aren't kept in the
 * repo: the taxonomy lives in the database.
 */
class TaxonomyImport extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'taxonomy:import';
    protected $description = 'Loads the SkillsFuture taxonomy (sf_v1) into the database.';
    protected $usage       = 'taxonomy:import <file> [file ...]';
    protected $arguments   = ['file' => 'Taxonomy files or tagged record files (providers.json, hirers.json)'];

    /**
     * `php spark taxonomy:import <file> [file ...]`
     *
     * @param list<string> $params Paths of taxonomy files or tagged record files
     *
     * @return int EXIT_SUCCESS, or EXIT_USER_INPUT (no files, unreadable JSON, wrong taxonomy version, no taxonomy found)
     */
    public function run(array $params)
    {
        $files = $params;
        if ($files === []) {
            CLI::error('Usage: php spark ' . $this->usage);

            return EXIT_USER_INPUT;
        }
        $tree  = []; // category => ['group' => ?string, 'specialisations' => [name => [skill => true]]]

        foreach ($files as $file) {
            try {
                $data = json_decode((string) @file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                CLI::error("{$file}: not readable JSON ({$e->getMessage()})");

                return EXIT_USER_INPUT;
            }

            // A taxonomy file is one tree; a records file has one tree per record (its `tags`).
            // Merge them all: sets keyed by name, so repeats count once.
            $version = $data['taxonomy_version'] ?? null;
            $trees   = isset($data['categories']) ? [$data] : array_column($data, 'tags');
            foreach ($trees as $tags) {
                $version ??= $tags['taxonomy_version'] ?? null;
                foreach ($tags['categories'] ?? [] as $category) {
                    $tree[$category['name']]['group'] ??= $category['group'] ?? null;
                    foreach ($category['specialisations'] ?? [] as $specialisation) {
                        $tree[$category['name']]['specialisations'][$specialisation['name']] ??= [];
                        foreach ($specialisation['skills'] ?? [] as $skill) {
                            $tree[$category['name']]['specialisations'][$specialisation['name']][$skill] = true;
                        }
                    }
                }
            }
            if ($version !== null && $version !== Taxonomy::VERSION) {
                CLI::error("{$file}: taxonomy {$version}, expected " . Taxonomy::VERSION);

                return EXIT_USER_INPUT;
            }
        }

        // The merged sets -> Taxonomy::import()'s input, everything sorted by name.
        ksort($tree);
        $categories = [];
        foreach ($tree as $name => $category) {
            $specialisations = $category['specialisations'] ?? [];
            ksort($specialisations);
            $categories[] = [
                'name'            => $name,
                'group'           => $category['group'],
                'specialisations' => array_map(static function (string $name, array $skills): array {
                    $skills = array_keys($skills);
                    sort($skills);

                    return ['name' => $name, 'skills' => $skills];
                }, array_keys($specialisations), $specialisations),
            ];
        }
        if ($categories === []) {
            CLI::error('No taxonomy found in ' . implode(', ', $files));

            return EXIT_USER_INPUT;
        }

        $added = (new Taxonomy())->import($categories);
        CLI::write('Added ' . implode(', ', array_map(static fn (string $k, int $v): string => "{$v} {$k}", array_keys($added), $added)) . '.', 'green');

        return EXIT_SUCCESS;
    }
}
