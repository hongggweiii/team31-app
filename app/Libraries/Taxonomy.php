<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * The SkillsFuture taxonomy (sf_v1) that gigs and showcases are tagged with,
 * as in the Data team's records (schema v2 `tags`) and the ranker:
 *
 *   category (sector) -> specialisation (track) -> skills
 *
 * A record holds at most MAX_CATEGORIES categories, each with 1 to
 * MAX_SPECIALISATIONS of its specialisations, each with up to MAX_SKILLS of the
 * skills linked to it. Gigs and showcases store specialisations and skills in
 * {owner}_specialisations / {owner}_skills; categories follow from those.
 */
final class Taxonomy
{
    public const VERSION             = 'sf_v1';
    public const MAX_CATEGORIES      = 2;
    public const MAX_SPECIALISATIONS = 3; // per category
    public const MAX_SKILLS          = 8; // per specialisation

    private const OWNERS             = ['gig', 'showcase'];
    private const TREE_CACHE_SECONDS = 86400;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * "<specialisation> (<category>)", the platform's search tag.
     */
    public static function searchTag(string $specialisation, string $category): string
    {
        return "{$specialisation} ({$category})";
    }

    /**
     * The whole taxonomy for the picker, categories and specialisations by
     * name.
     *
     * @return list<array{id: int, name: string, specialisations: list<array{id: int, name: string, search_tag: string, skills: array<int, string>}>}>
     */
    public function tree(): array
    {
        $categories = [];
        foreach ($this->db->table('sf_categories')->select('id, name')->orderBy('name')->get()->getResultArray() as $row) {
            $categories[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'specialisations' => []];
        }

        $skills = [];
        $links  = $this->db->table('sf_specialisation_skills l')->select('l.specialisation_id, k.id, k.name')
            ->join('sf_skills k', 'k.id = l.skill_id')->orderBy('k.name')->get()->getResultArray();
        foreach ($links as $row) {
            $skills[(int) $row['specialisation_id']][(int) $row['id']] = $row['name'];
        }

        foreach ($this->db->table('sf_specialisations')->select('id, category_id, name')->orderBy('name')->get()->getResultArray() as $row) {
            $category = &$categories[(int) $row['category_id']];
            $category['specialisations'][] = [
                'id'         => (int) $row['id'],
                'name'       => $row['name'],
                'search_tag' => self::searchTag($row['name'], $category['name']),
                'skills'     => $skills[(int) $row['id']] ?? [],
            ];
            unset($category);
        }

        return array_values($categories);
    }

    /**
     * tree(), cached for TREE_CACHE_SECONDS: it is 3 queries (the skills one
     * returns ~2,000 rows) per form load and per check(); the taxonomy only changes
     * through import(), which clears it. After editing the sf_* tables by
     * hand, run `php spark cache:clear`.
     *
     * @return list<array{id: int, name: string, specialisations: list<array{id: int, name: string, search_tag: string, skills: array<int, string>}>}>
     */
    public function cachedTree(): array
    {
        return cache()->remember($this->treeCacheKey(), self::TREE_CACHE_SECONDS, fn (): array => $this->tree());
    }

    public function forgetTree(): void
    {
        cache()->delete($this->treeCacheKey());
    }

    /**
     * One entry per database, so the test database never shares the app's.
     */
    private function treeCacheKey(): string
    {
        return 'taxonomy_tree_' . md5($this->db->hostname . '/' . $this->db->database);
    }

    /**
     * Checks a picker submission against the taxonomy and the schema v2 caps.
     * Skills posted for a specialisation that isn't chosen are ignored.
     *
     * @param mixed $categories      categories[]: category ids
     * @param mixed $specialisations specialisations[]: specialisation ids
     * @param mixed $skills          skills[<specialisation id>][]: skill ids
     *
     * @return array{errors: array<string, string>, selection: array{specialisations: list<int>, skills: array<int, list<int>>}}
     *         errors keyed categories / specialisations / skills; selection is what save() stores
     */
    public function check(mixed $categories, mixed $specialisations, mixed $skills): array
    {
        $ids = static fn (mixed $values): array => array_values(array_unique(array_map('intval', array_filter(
            (array) $values,
            static fn ($v): bool => is_scalar($v) && ctype_digit((string) $v),
        ))));

        $categories      = $ids($categories);
        $specialisations = $ids($specialisations);
        $errors          = [];

        // Checked against the cached tree, so validating a form costs no queries.
        $known    = []; // category id => name
        $parentOf = []; // specialisation id => category id
        $linked   = []; // specialisation id => [skill id => true]
        foreach ($this->cachedTree() as $category) {
            $known[$category['id']] = $category['name'];
            foreach ($category['specialisations'] as $specialisation) {
                $parentOf[$specialisation['id']] = $category['id'];
                $linked[$specialisation['id']]   = array_fill_keys(array_keys($specialisation['skills']), true);
            }
        }

        if ($categories === []) {
            $errors['categories'] = 'Choose at least one category.';
        } elseif (count($categories) > self::MAX_CATEGORIES) {
            $errors['categories'] = 'Choose at most ' . self::MAX_CATEGORIES . ' categories.';
        } elseif (array_diff($categories, array_keys($known)) !== []) {
            $errors['categories'] = 'Choose categories from the list.';
        }

        $parents = array_intersect_key($parentOf, array_flip($specialisations));
        if ($specialisations === []) {
            $errors['specialisations'] = 'Choose at least one specialisation.';
        } elseif (count($parents) !== count($specialisations)) {
            $errors['specialisations'] = 'Choose specialisations from the list.';
        } elseif (array_diff($parents, $categories) !== []) {
            $errors['specialisations'] = 'Each specialisation must belong to one of the chosen categories.';
        } elseif (! isset($errors['categories'])) {
            $perCategory = array_count_values($parents);
            foreach ($categories as $category) {
                $n = $perCategory[$category] ?? 0;
                if ($n === 0 || $n > self::MAX_SPECIALISATIONS) {
                    $errors['specialisations'] = 'Choose 1 to ' . self::MAX_SPECIALISATIONS . " specialisations for {$known[$category]}.";
                    break;
                }
            }
        }

        $chosenSkills = [];
        foreach ((array) $skills as $specialisation => $skillIds) {
            if (in_array((int) $specialisation, $specialisations, true) && ($skillIds = $ids($skillIds)) !== []) {
                $chosenSkills[(int) $specialisation] = $skillIds;
            }
        }
        foreach ($chosenSkills as $specialisation => $skillIds) {
            if (count($skillIds) > self::MAX_SKILLS) {
                $errors['skills'] = 'Choose at most ' . self::MAX_SKILLS . ' skills per specialisation.';
            } elseif (array_diff_key(array_flip($skillIds), $linked[$specialisation] ?? []) !== []) {
                $errors['skills'] = 'Choose skills from the list for each specialisation.';
            }
        }

        return ['errors' => $errors, 'selection' => ['specialisations' => $specialisations, 'skills' => $chosenSkills]];
    }

    /**
     * Stores a check()ed selection for a new gig / showcase. Run it inside the
     * caller's transaction.
     *
     * @param array{specialisations: list<int>, skills: array<int, list<int>>} $selection
     */
    public function save(string $owner, int $ownerId, array $selection): void
    {
        $this->assertOwner($owner);

        if ($selection['specialisations'] !== []) {
            $this->db->table("{$owner}_specialisations")->insertBatch(array_map(
                static fn (int $s): array => ["{$owner}_id" => $ownerId, 'specialisation_id' => $s],
                $selection['specialisations'],
            ));
        }

        $skills = [];
        foreach ($selection['skills'] as $specialisation => $skillIds) {
            foreach ($skillIds as $skill) {
                $skills[] = ["{$owner}_id" => $ownerId, 'specialisation_id' => $specialisation, 'skill_id' => $skill];
            }
        }
        if ($skills !== []) {
            $this->db->table("{$owner}_skills")->insertBatch($skills);
        }
    }

    /**
     * Gigs' / showcases' tags in the records' shape (`tags.categories`), the
     * matcher request's `taxonomy`: categories, specialisations and skills
     * sorted by name.
     *
     * @param list<int> $ownerIds
     *
     * @return array<int, list<array{name: string, group: string|null, specialisations: list<array{name: string, search_tag: string, skills: list<string>}>}>>
     *         Keyed by owner id; owners without tags are left out
     */
    public function tags(string $owner, array $ownerIds): array
    {
        $this->assertOwner($owner);
        if ($ownerIds === []) {
            return [];
        }

        $skills = [];
        $rows   = $this->db->table("{$owner}_skills l")->select("l.{$owner}_id AS owner, l.specialisation_id, k.name")
            ->join('sf_skills k', 'k.id = l.skill_id')->whereIn("l.{$owner}_id", $ownerIds)->get()->getResultArray();
        foreach ($rows as $row) {
            $skills[(int) $row['owner']][(int) $row['specialisation_id']][] = $row['name'];
        }

        $tree = [];
        $rows = $this->db->table("{$owner}_specialisations l")
            ->select("l.{$owner}_id AS owner, s.id, s.name, c.name AS category, c.group_name")
            ->join('sf_specialisations s', 's.id = l.specialisation_id')
            ->join('sf_categories c', 'c.id = s.category_id')
            ->whereIn("l.{$owner}_id", $ownerIds)->get()->getResultArray();
        foreach ($rows as $row) {
            $owned = $skills[(int) $row['owner']][(int) $row['id']] ?? [];
            sort($owned);
            $tree[(int) $row['owner']][$row['category']]['group'] = $row['group_name'];
            $tree[(int) $row['owner']][$row['category']]['specialisations'][$row['name']] = [
                'name'       => $row['name'],
                'search_tag' => self::searchTag($row['name'], $row['category']),
                'skills'     => $owned,
            ];
        }

        $tags = [];
        foreach ($tree as $ownerId => $categories) {
            ksort($categories);
            foreach ($categories as $name => $category) {
                ksort($category['specialisations']);
                $tags[$ownerId][] = ['name' => $name, 'group' => $category['group'], 'specialisations' => array_values($category['specialisations'])];
            }
        }

        return $tags;
    }

    /**
     * Adds the given taxonomy to the sf_* tables. Additive: entries already
     * there are kept (gigs and showcases may point at them), so re-importing
     * a newer taxonomy only adds what's new and fills in groups.
     *
     * @param list<array{name: string, group?: string|null, specialisations: list<array{name: string, skills: list<string>}>}> $categories
     *
     * @return array{categories: int, specialisations: int, skills: int, links: int} Rows added
     */
    public function import(array $categories): array
    {
        $added = ['categories' => 0, 'specialisations' => 0, 'skills' => 0, 'links' => 0];

        $this->db->transException(true)->transStart();

        $categoryIds = array_column($this->db->table('sf_categories')->select('id, name')->get()->getResultArray(), 'id', 'name');
        $skillIds    = array_column($this->db->table('sf_skills')->select('id, name')->get()->getResultArray(), 'id', 'name');
        $specIds     = [];
        foreach ($this->db->table('sf_specialisations')->select('id, category_id, name')->get()->getResultArray() as $row) {
            $specIds[$row['category_id'] . "\0" . $row['name']] = $row['id'];
        }
        $links = [];
        foreach ($this->db->table('sf_specialisation_skills')->get()->getResultArray() as $row) {
            $links[$row['specialisation_id'] . "\0" . $row['skill_id']] = true;
        }

        $wanted = []; // [specialisation id, skill name]
        foreach ($categories as $category) {
            $group = $category['group'] ?? null;
            if (! isset($categoryIds[$category['name']])) {
                $this->db->table('sf_categories')->insert(['name' => $category['name'], 'group_name' => $group]);
                $categoryIds[$category['name']] = $this->db->insertID();
                $added['categories']++;
            } elseif ($group !== null) {
                $this->db->table('sf_categories')->where('id', $categoryIds[$category['name']])->update(['group_name' => $group]);
            }
            $categoryId = $categoryIds[$category['name']];

            foreach ($category['specialisations'] as $specialisation) {
                $key = $categoryId . "\0" . $specialisation['name'];
                if (! isset($specIds[$key])) {
                    $this->db->table('sf_specialisations')->insert(['category_id' => $categoryId, 'name' => $specialisation['name']]);
                    $specIds[$key] = $this->db->insertID();
                    $added['specialisations']++;
                }
                foreach ($specialisation['skills'] as $skill) {
                    $wanted[] = [$specIds[$key], $skill];
                }
            }
        }

        // Skills and links in batches: there are thousands of them.
        $newSkills = array_values(array_diff(array_unique(array_column($wanted, 1)), array_keys($skillIds)));
        if ($newSkills !== []) {
            $this->db->table('sf_skills')->insertBatch(array_map(static fn (string $name): array => ['name' => $name], $newSkills));
            $skillIds = array_column($this->db->table('sf_skills')->select('id, name')->get()->getResultArray(), 'id', 'name');
            $added['skills'] = count($newSkills);
        }

        $newLinks = [];
        foreach ($wanted as [$specId, $skill]) {
            $link = $specId . "\0" . $skillIds[$skill];
            if (! isset($links[$link])) {
                $newLinks[]    = ['specialisation_id' => $specId, 'skill_id' => $skillIds[$skill]];
                $links[$link] = true;
            }
        }
        if ($newLinks !== []) {
            $this->db->table('sf_specialisation_skills')->insertBatch($newLinks);
            $added['links'] = count($newLinks);
        }

        $this->db->transComplete();
        $this->forgetTree();

        return $added;
    }

    private function assertOwner(string $owner): void
    {
        if (! in_array($owner, self::OWNERS, true)) {
            throw new \InvalidArgumentException("Unknown taxonomy owner '{$owner}'");
        }
    }
}
