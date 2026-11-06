<?php

namespace App\Controllers;

use App\Libraries\RichText;
use App\Libraries\Taxonomy;
use App\Models\ShowcaseModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Providers create showcases here; the route is behind Shield's session filter.
 */
class Showcases extends BaseController
{
    /** Quill fields; cleaned before validation so an empty editor counts as blank. */
    private const RICH_TEXT_FIELDS = ['about', 'how_i_work'];

    private const DOCUMENT_EXTENSIONS = 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png';

    private const MAX_SKILL_LENGTH = 50;

    private const MAX_GROUP_NAME_LENGTH = 100;

    /**
     * The "Create your Profile" form, with the cached SkillsFuture tree and the
     * per-section limits the form enforces in the browser.
     *
     * GET /create-profile
     *
     * @return string The rendered create-profile view
     */
    public function new(): string
    {
        return view('create-profile', [
            'taxonomy'           => (new Taxonomy())->cachedTree(),
            'documentCategories' => ShowcaseModel::DOCUMENT_CATEGORIES,
            'limits'             => ShowcaseModel::LIMITS,
        ]);
    }

    /**
     * Validates and saves a showcase with its children (credentials, tags,
     * services, achievements, skill groups, documents) in one transaction.
     * Uploaded files are stored first and deleted again if the save fails.
     * The showcase is embedded later by `php spark embed:auto` (cron), not here,
     * so a slow embedding-service never delays the form.
     *
     * POST /create-profile (multipart): the profile fields, credentials[],
     * services[i][title|detail], achievements[], skill_groups[i][name|skills[]],
     * the picker's categories[] / specialisations[] / skills[<id>][], an optional
     * photo and documents under links[<category>][].
     *
     * @return RedirectResponse Back to the empty form with a 'message' flash; or back
     *                          with the input and an 'errors' flash (field => message)
     */
    public function create(): RedirectResponse
    {
        // Validate what would be saved: trimmed text, cleaned rich text, no empty boxes.
        $data = $this->normalise($this->request->getPost());

        $rules = [
            'name'              => ['label' => 'Name', 'rules' => 'required|max_length[150]'],
            'title'             => ['label' => 'Title', 'rules' => 'required|max_length[255]'],
            'years_experience'  => ['label' => 'Years of Experience', 'rules' => 'permit_empty|is_natural|less_than_equal_to[60]'],
            'credentials.*'     => ['label' => 'Credential', 'rules' => 'max_length[255]'],
            'headline'          => ['label' => 'Headline', 'rules' => 'required|max_length[255]'],
            'about'             => ['label' => 'Bio', 'rules' => 'required'],
            'availability'      => ['label' => 'Availability', 'rules' => 'permit_empty|max_length[255]'],
            'rate'              => ['label' => 'Rate', 'rules' => 'permit_empty|max_length[255]'],
            'services'          => ['label' => 'Services I offer', 'rules' => 'required|is_array'],
            'services.*.title'  => ['label' => 'Service Title', 'rules' => 'required|max_length[255]'],
            'how_i_work'        => ['label' => 'How I Work', 'rules' => 'permit_empty'],
            'terms'             => ['label' => 'Terms of Service', 'rules' => 'required', 'errors' => ['required' => 'Please accept the Terms of Service.']],
        ];

        // Uploads are optional; the file rules fail on a missing file, so only
        // apply them to inputs where something was actually chosen.
        if ($this->chosen('photo')) {
            $rules['photo'] = [
                'label' => 'Photo',
                'rules' => 'uploaded[photo]|is_image[photo]|ext_in[photo,jpg,jpeg,png,webp]|max_size[photo,4096]|min_dims[photo,325,325]',
            ];
        }
        foreach (ShowcaseModel::DOCUMENT_CATEGORIES as $key => $label) {
            if ($this->chosen("links.{$key}")) {
                $rules["links.{$key}"] = [
                    'label' => $label,
                    'rules' => "uploaded[links.{$key}]|max_size[links.{$key},4096]|ext_in[links.{$key}," . self::DOCUMENT_EXTENSIONS . ']',
                ];
            }
        }

        $valid = $this->validateData($data, $rules);

        // Checks the rule syntax can't express. Run whether or not the rules
        // passed, so every problem is reported at once.
        $taxonomy = (new Taxonomy())->check($data['categories'] ?? [], $data['specialisations'] ?? [], $data['skills'] ?? []);
        $errors   = $taxonomy['errors'];
        $labels = ['credentials' => 'credentials', 'services' => 'services', 'achievements' => 'achievements', 'skill_groups' => 'technical proficiency categories'];
        foreach ($labels as $field => $label) {
            if (count($data[$field]) > ShowcaseModel::LIMITS[$field]) {
                $errors[$field] = 'You can add at most ' . ShowcaseModel::LIMITS[$field] . " {$label}.";
            }
        }
        // Checked per group rather than as a skill_groups.*.name rule: a wildcard
        // rule still runs when the (optional) section is empty, and fails required.
        foreach ($data['skill_groups'] as $i => $group) {
            if ($group['name'] === '') {
                $errors["skill_groups.{$i}.name"] = 'Name the category for these skills.';
            } elseif (mb_strlen($group['name']) > self::MAX_GROUP_NAME_LENGTH) {
                $errors["skill_groups.{$i}.name"] = 'Category names can be at most ' . self::MAX_GROUP_NAME_LENGTH . ' characters.';
            } elseif ($group['skills'] === []) {
                $errors["skill_groups.{$i}.skills"] = 'Add at least one skill to "' . $group['name'] . '".';
            } elseif (count($group['skills']) > ShowcaseModel::LIMITS['skills']) {
                $errors["skill_groups.{$i}.skills"] = 'Each category can have at most ' . ShowcaseModel::LIMITS['skills'] . ' skills.';
            } elseif (max(array_map('mb_strlen', $group['skills'])) > self::MAX_SKILL_LENGTH) {
                $errors["skill_groups.{$i}.skills"] = 'Skills can be at most ' . self::MAX_SKILL_LENGTH . ' characters.';
            }
        }
        if (! $valid || $errors !== []) {
            return redirect()->back()->withInput()->with('errors', $errors);
        }

        // Store the uploads first (paths kept in $stored), so the rows can point at them.
        $stored = [];
        $photo  = isset($rules['photo']) ? $this->request->getFile('photo') : null;
        if ($photo !== null && $photo->isValid() && ! $photo->hasMoved()) {
            $stored['photo'] = $photo->store('showcases/photos');
        }

        $documents = [];
        foreach (array_keys(ShowcaseModel::DOCUMENT_CATEGORIES) as $key) {
            if (! isset($rules["links.{$key}"])) {
                continue;
            }
            foreach ($this->request->getFileMultiple("links.{$key}") ?? [] as $file) {
                if ($file->isValid() && ! $file->hasMoved()) {
                    $documents[] = [
                        'category'      => $key,
                        'original_name' => mb_substr($file->getClientName(), 0, 255),
                        'path'          => $stored[] = $file->store('showcases/documents'),
                    ];
                }
            }
        }

        // The showcase row and all its child rows, in one transaction.
        $nullIfBlank = static fn (string $value): ?string => $value !== '' ? $value : null;

        $showcases  = new ShowcaseModel();
        $showcaseId = $showcases->createWithChildren(
            [
                'user_id'      => auth()->id(),
                'name'         => $data['name'],
                'photo_path'   => $stored['photo'] ?? null,
                'title'        => $data['title'],
                'years_experience' => $data['years_experience'] !== '' ? (int) $data['years_experience'] : null,
                'headline'     => $data['headline'],
                'about'        => $data['about'],
                'availability' => $nullIfBlank($data['availability']),
                'rate'         => $nullIfBlank($data['rate']),
                'how_i_work'   => $nullIfBlank($data['how_i_work']),
            ],
            [
                'credentials'  => $data['credentials'],
                'taxonomy'     => $taxonomy['selection'],
                'services'     => array_map(static fn (array $s): array => [
                    'title'       => $s['title'],
                    'description' => $nullIfBlank($s['detail']),
                ], $data['services']),
                'achievements' => $data['achievements'],
                'skill_groups' => $data['skill_groups'],
                'documents'    => $documents,
            ]
        );

        if ($showcaseId === false) {
            // Nothing was saved: remove the files stored above so none are orphaned.
            foreach ($stored as $path) {
                @unlink(WRITEPATH . 'uploads/' . $path);
            }

            return redirect()->back()->withInput()
                ->with('errors', $showcases->errors() ?: ['showcase' => 'Your showcase could not be saved. Please try again.']);
        }
        $this->attachSuggestedTerms('showcase', $showcaseId);

        return redirect()->to(url_to('showcase-new'))->with('message', 'Your showcase has been published.');
    }

    /**
     * Trims text, cleans rich text, de-duplicates selections and drops empty
     * repeatable boxes, so validation sees what would actually be saved.
     *
     * @param array<string, mixed> $post The raw POST data
     *
     * @return array<string, mixed> The same keys, cleaned; services as [{title, detail}],
     *                              achievements and credentials as lists of strings,
     *                              skill_groups as [{name, skills: list<string>}]
     */
    private function normalise(array $post): array
    {
        $data = $post;
        foreach (['name', 'title', 'headline', 'years_experience', 'availability', 'rate'] as $field) {
            $data[$field] = trim((string) ($post[$field] ?? ''));
        }
        foreach (self::RICH_TEXT_FIELDS as $field) {
            $data[$field] = RichText::clean($post[$field] ?? '');
        }
        $data['credentials'] = $this->uniqueStrings((array) ($post['credentials'] ?? []));

        $boxes = static fn (string $field): array => array_values(array_filter((array) ($post[$field] ?? []), 'is_array'));

        $data['services'] = array_map(static fn (array $s): array => [
            'title'  => trim((string) ($s['title'] ?? '')),
            'detail' => RichText::clean($s['detail'] ?? ''),
        ], $boxes('services'));

        $data['achievements'] = array_values(array_filter(array_map(
            static fn ($a): string => is_string($a) ? RichText::clean($a) : '',
            (array) ($post['achievements'] ?? [])
        )));

        $groups = [];
        foreach ($boxes('skill_groups') as $group) {
            $name   = trim((string) ($group['name'] ?? ''));
            $skills = $this->uniqueStrings((array) ($group['skills'] ?? []));
            if ($name !== '' || $skills !== []) {
                $groups[] = ['name' => $name, 'skills' => $skills];
            }
        }
        $data['skill_groups'] = $groups;

        return $data;
    }

    /**
     * Trimmed, non-empty strings, de-duplicated case-insensitively.
     *
     * @param array<mixed> $values Anything; non-strings are dropped
     *
     * @return list<string> In first-seen order, keeping the first spelling
     */
    private function uniqueStrings(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '' && ! isset($unique[mb_strtolower($value)])) {
                $unique[mb_strtolower($value)] = $value;
            }
        }

        return array_values($unique);
    }

    /**
     * Whether the user picked at least one file for this input, so the
     * upload rules only apply to inputs that were used.
     *
     * @param string $name The file input's name, e.g. "photo" or "links.cv"
     */
    private function chosen(string $name): bool
    {
        $files = $this->request->getFileMultiple($name) ?? [$this->request->getFile($name)];

        foreach ($files as $file) {
            if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }

        return false;
    }
}
