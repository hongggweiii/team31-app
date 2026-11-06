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
    private const KIND_TABLES        = ['category' => 'sf_categories', 'specialisation' => 'sf_specialisations', 'skill' => 'sf_skills'];
    private const TREE_CACHE_SECONDS = 86400;
    private const TREE_CACHE_VERSION = 2; // 2: categories carry their group

    private BaseConnection $db;

    /**
     * @param BaseConnection|null $db Defaults to the app's database; models pass theirs so
     *                                save() runs inside their transaction
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * "<specialisation> (<category>)", the platform's search tag.
     *
     * @param string $specialisation e.g. "Tax"
     * @param string $category       e.g. "Accountancy"
     *
     * @return string e.g. "Tax (Accountancy)"
     */
    public static function searchTag(string $specialisation, string $category): string
    {
        return "{$specialisation} ({$category})";
    }

    /**
     * The whole taxonomy for the picker, categories and specialisations by
     * name. Three queries; use cachedTree() on hot paths.
     *
     * @return list<array{id: int, name: string, group: string|null, specialisations: list<array{id: int, name: string, search_tag: string, skills: array<int, string>}>}>
     */
    public function tree(): array
    {
        $categories = [];
        foreach ($this->db->table('sf_categories')->select('id, name, group_name')->orderBy('name')->get()->getResultArray() as $row) {
            $categories[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'group' => $row['group_name'], 'specialisations' => []];
        }

        $skills = [];
        $links  = $this->db->table('sf_specialisation_skills l')->select('l.specialisation_id, k.id, k.name')
            ->join('sf_skills k', 'k.id = l.skill_id')->orderBy('k.name')->get()->getResultArray();
        foreach ($links as $row) {
            $skills[(int) $row['specialisation_id']][(int) $row['id']] = $row['name'];
        }

        // Hang each specialisation (with its skills) under its category. $category is a
        // reference into $categories, unset after each use so the next row can't overwrite it.
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
     * returns ~2,000 rows) per form load and per check(). import(), add(),
     * rename() and remove() clear it, as does the taxonomy dashboard after
     * taxonomy-service approves a term. After editing the sf_* tables by hand,
     * run `php spark cache:clear`.
     *
     * @return list<array{id: int, name: string, group: string|null, specialisations: list<array{id: int, name: string, search_tag: string, skills: array<int, string>}>}>
     */
    public function cachedTree(): array
    {
        return cache()->remember($this->treeCacheKey(), self::TREE_CACHE_SECONDS, fn (): array => $this->tree());
    }

    /**
     * Drops the cached tree; the next cachedTree() rebuilds it.
     */
    public function forgetTree(): void
    {
        cache()->delete($this->treeCacheKey());
    }

    /**
     * One entry per database, so the test database never shares the app's.
     * Bump TREE_CACHE_VERSION whenever tree()'s shape changes, so an entry
     * cached in the old shape is never read.
     *
     * @return string e.g. "taxonomy_tree_v2_<md5 of host/database>"
     */
    private function treeCacheKey(): string
    {
        return 'taxonomy_tree_v' . self::TREE_CACHE_VERSION . '_' . md5($this->db->hostname . '/' . $this->db->database);
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
        // Posted values -> unique integer ids; anything that isn't a whole number is dropped.
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

        // Specialisations: all known, all under a chosen category, and 1-3 per category.
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

        // Skills: only those under a chosen specialisation count; at most 8 each,
        // and each must be linked to its specialisation in sf_specialisation_skills.
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
     * @param string                                                          $owner     'gig' or 'showcase'
     * @param int                                                             $ownerId   The new gig / showcase id
     * @param array{specialisations: list<int>, skills: array<int, list<int>>} $selection From check()
     *
     * @throws \InvalidArgumentException For an unknown $owner
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
     * @param string    $owner    'gig' or 'showcase'
     * @param list<int> $ownerIds Gig / showcase ids (two queries for the whole list)
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

        // Group by owner, then category name, then specialisation name; the keys
        // are sorted below, so the result is in name order.
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
     * What tags() returns for an owner with this check()ed selection, built
     * from the cached tree instead of read back from the database. Used by
     * Gigs::create() to hand the new gig's tags to the matches page.
     *
     * @param array{specialisations: list<int>, skills: array<int, list<int>>} $selection From check()
     *
     * @return list<array{name: string, group: string|null, specialisations: list<array{name: string, search_tag: string, skills: list<string>}>}>
     */
    public function selectionTags(array $selection): array
    {
        $chosen = array_flip($selection['specialisations']);
        $tree   = [];
        foreach ($this->cachedTree() as $category) {
            foreach ($category['specialisations'] as $specialisation) {
                if (! isset($chosen[$specialisation['id']])) {
                    continue;
                }
                $skills = array_values(array_intersect_key($specialisation['skills'], array_flip($selection['skills'][$specialisation['id']] ?? [])));
                sort($skills);
                $tree[$category['name']]['group']                                    = $category['group'];
                $tree[$category['name']]['specialisations'][$specialisation['name']] = [
                    'name'       => $specialisation['name'],
                    'search_tag' => $specialisation['search_tag'],
                    'skills'     => $skills,
                ];
            }
        }

        // Ordered as tags() orders them.
        $tags = [];
        ksort($tree);
        foreach ($tree as $name => $category) {
            ksort($category['specialisations']);
            $tags[] = ['name' => $name, 'group' => $category['group'], 'specialisations' => array_values($category['specialisations'])];
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

        // What exists already, by name (specialisations by category + name, links by
        // both ids), so each row below is inserted only if it is new.
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

    /**
     * How many gigs and showcases use each specialisation, and each skill
     * under each specialisation. remove() refuses an item in use.
     *
     * @return array{specialisations: array<int, int>, skills: array<string, int>} skills keyed "<specialisation id>:<skill id>"
     */
    public function usage(): array
    {
        $usage = ['specialisations' => [], 'skills' => []];
        foreach (self::OWNERS as $owner) {
            $rows = $this->db->table("{$owner}_specialisations")->select('specialisation_id, COUNT(*) AS n')
                ->groupBy('specialisation_id')->get()->getResultArray();
            foreach ($rows as $row) {
                $usage['specialisations'][(int) $row['specialisation_id']] = ($usage['specialisations'][(int) $row['specialisation_id']] ?? 0) + (int) $row['n'];
            }
            $rows = $this->db->table("{$owner}_skills")->select('specialisation_id, skill_id, COUNT(*) AS n')
                ->groupBy(['specialisation_id', 'skill_id'])->get()->getResultArray();
            foreach ($rows as $row) {
                $key                   = $row['specialisation_id'] . ':' . $row['skill_id'];
                $usage['skills'][$key] = ($usage['skills'][$key] ?? 0) + (int) $row['n'];
            }
        }

        return $usage;
    }

    /**
     * Adds a category, a specialisation to a category, or a skill to a
     * specialisation. A skill whose name exists already (under another
     * specialisation) is linked rather than duplicated. Names are compared
     * case-insensitively.
     *
     * @param int|null $parentId A specialisation's category id, or a skill's specialisation id
     *
     * @return int The new item's id (for a linked skill, the existing skill's)
     *
     * @throws \InvalidArgumentException With a message for the user
     */
    public function add(string $kind, string $name, ?int $parentId = null): int
    {
        $name = $this->cleanName($kind, $name);
        $id   = $this->inTransaction(function () use ($kind, $name, $parentId): int {
            switch ($kind) {
                case 'category':
                    $this->assertNameFree('sf_categories', $name);
                    $this->db->table('sf_categories')->insert(['name' => $name]);
                    $id = (int) $this->db->insertID();
                    break;

                case 'specialisation':
                    $this->assertExists('sf_categories', $parentId, 'Choose a category.');
                    $this->assertNameFree('sf_specialisations', $name, ['category_id' => $parentId]);
                    $this->db->table('sf_specialisations')->insert(['category_id' => $parentId, 'name' => $name]);
                    $id = (int) $this->db->insertID();
                    break;

                case 'skill':
                    $this->assertExists('sf_specialisations', $parentId, 'Choose a specialisation.');
                    $id = $this->idByName('sf_skills', $name);
                    if ($id === null) {
                        $this->db->table('sf_skills')->insert(['name' => $name]);
                        $id = (int) $this->db->insertID();
                    } elseif ($this->db->table('sf_specialisation_skills')->where(['specialisation_id' => $parentId, 'skill_id' => $id])->countAllResults() > 0) {
                        throw new \InvalidArgumentException("\"{$name}\" is already a skill of that specialisation.");
                    }
                    $this->db->table('sf_specialisation_skills')->insert(['specialisation_id' => $parentId, 'skill_id' => $id]);
                    break;

                default:
                    throw new \InvalidArgumentException("Unknown taxonomy item '{$kind}'.");
            }

            return $id;
        });
        $this->forgetTree();

        return $id;
    }

    /**
     * Renames a category, specialisation or skill everywhere it is used.
     *
     * @throws \InvalidArgumentException With a message for the user
     */
    public function rename(string $kind, int $id, string $name): void
    {
        $name  = $this->cleanName($kind, $name);
        $table = self::KIND_TABLES[$kind] ?? throw new \InvalidArgumentException("Unknown taxonomy item '{$kind}'.");
        $row   = $this->db->table($table)->where('id', $id)->get()->getRowArray()
            ?? throw new \InvalidArgumentException("That {$kind} no longer exists.");

        // Specialisation names are unique within their category; the others everywhere.
        $this->assertNameFree($table, $name, $kind === 'specialisation' ? ['category_id' => $row['category_id']] : [], $id);
        $this->db->table($table)->where('id', $id)->update(['name' => $name]);
        $this->forgetTree();
    }

    /**
     * Removes an item nothing uses: a category without specialisations, a
     * specialisation no gig / showcase or pending proposal has, or a skill
     * from one specialisation (the skill itself goes once no specialisation
     * links it). Deleting an item in use would silently untag gigs and
     * showcases through the FKs' cascades.
     *
     * @param int|null $specialisationId For a skill: the specialisation to remove it from
     *
     * @throws \InvalidArgumentException With a message for the user
     */
    public function remove(string $kind, int $id, ?int $specialisationId = null): void
    {
        $pending = fn (string $column, int $value): int => $this->db->table('taxonomy_proposals')
            ->where(['status' => 'pending_review', $column => $value])->countAllResults();
        $this->inTransaction(function () use ($kind, $id, $specialisationId, $pending): void {
            switch ($kind) {
                case 'category':
                    if ($this->db->table('sf_specialisations')->where('category_id', $id)->countAllResults() > 0) {
                        throw new \InvalidArgumentException('Remove its specialisations first.');
                    }
                    if ($pending('category_id', $id) > 0) {
                        throw new \InvalidArgumentException('Pending proposals are waiting under it: review them first.');
                    }
                    $this->db->table('sf_categories')->where('id', $id)->delete();
                    break;

                case 'specialisation':
                    if (($n = $this->usage()['specialisations'][$id] ?? 0) > 0) {
                        throw new \InvalidArgumentException("It is used by {$n} gig(s) / showcase(s).");
                    }
                    if ($pending('specialisation_id', $id) > 0) {
                        throw new \InvalidArgumentException('Pending proposals are waiting under it: review them first.');
                    }
                    $this->db->table('sf_specialisations')->where('id', $id)->delete(); // its skill links cascade
                    break;

                case 'skill':
                    if (($n = $this->usage()['skills']["{$specialisationId}:{$id}"] ?? 0) > 0) {
                        throw new \InvalidArgumentException("It is used by {$n} gig(s) / showcase(s) under this specialisation.");
                    }
                    $this->db->table('sf_specialisation_skills')->where(['specialisation_id' => $specialisationId, 'skill_id' => $id])->delete();
                    if ($this->db->table('sf_specialisation_skills')->where('skill_id', $id)->countAllResults() === 0) {
                        $this->db->table('sf_skills')->where('id', $id)->delete();
                    }
                    break;

                default:
                    throw new \InvalidArgumentException("Unknown taxonomy item '{$kind}'.");
            }
        });
        $this->forgetTree();
    }

    /**
     * Runs $work in a transaction, rolled back if it throws: transException()
     * only unwinds for failed queries, not for a refusal thrown in between.
     */
    private function inTransaction(callable $work): mixed
    {
        $this->db->transException(true)->transStart();

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
        $this->db->transComplete();

        return $result;
    }

    private function cleanName(string $kind, string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $max  = $kind === 'skill' ? 255 : 150; // the sf_* name columns
        if ($name === '' || mb_strlen($name) > $max) {
            throw new \InvalidArgumentException("Give the {$kind} a name of 1 to {$max} characters.");
        }

        return $name;
    }

    private function idByName(string $table, string $name, array $where = [], ?int $except = null): ?int
    {
        $builder = $this->db->table($table)->select('id')->where('LOWER(name)', mb_strtolower($name))->where($where);
        if ($except !== null) {
            $builder->where('id !=', $except);
        }
        $row = $builder->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function assertNameFree(string $table, string $name, array $where = [], ?int $except = null): void
    {
        if ($this->idByName($table, $name, $where, $except) !== null) {
            throw new \InvalidArgumentException("\"{$name}\" exists already.");
        }
    }

    private function assertExists(string $table, ?int $id, string $message): void
    {
        if ($id === null || $this->db->table($table)->where('id', $id)->countAllResults() === 0) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * @throws \InvalidArgumentException Unless $owner is 'gig' or 'showcase' (it becomes part of table names)
     */
    private function assertOwner(string $owner): void
    {
        if (! in_array($owner, self::OWNERS, true)) {
            throw new \InvalidArgumentException("Unknown taxonomy owner '{$owner}'");
        }
    }
}
