<?php

namespace App\Controllers;

use App\Libraries\RichText;
use App\Models\CategoryModel;
use App\Models\GigModel;
use App\Models\ShowcaseModel;
use App\Models\SpecialityModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Providers create showcases here; the route is behind Shield's session filter.
 */
class Showcases extends BaseController
{
    /** Quill fields; cleaned before validation so an empty editor counts as blank. */
    private const RICH_TEXT_FIELDS = ['credentials', 'relevant_achievements', 'about', 'how_i_work', 'availability', 'rate_notes'];

    private const DOCUMENT_EXTENSIONS = 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png';

    public function new(): string
    {
        return view('create-profile', [
            'categories'         => (new CategoryModel())->activeOptions(),
            'specialisations'    => (new SpecialityModel())->activeByCategory(),
            'seniorityLevels'    => GigModel::SENIORITY_LEVELS,
            'locations'          => ShowcaseModel::LOCATIONS,
            'documentCategories' => ShowcaseModel::DOCUMENT_CATEGORIES,
        ]);
    }

    public function create(): RedirectResponse
    {
        $data = $this->request->getPost();

        foreach (self::RICH_TEXT_FIELDS as $field) {
            $data[$field] = RichText::clean($data[$field] ?? '');
        }
        $data['services'] = array_values(array_map(static fn ($service): array => [
            'title'  => trim((string) ($service['title'] ?? '')),
            'detail' => RichText::clean($service['detail'] ?? ''),
        ], array_filter((array) ($data['services'] ?? []), 'is_array')));
        $data['search_tags'] = $this->normaliseTags((array) ($data['search_tags'] ?? []));

        $categoryIds     = array_keys((new CategoryModel())->activeOptions());
        $specialisations = (new SpecialityModel())->activeByCategory()[$data['service_category'] ?? ''] ?? [];
        $availableNow    = ($data['available_now'] ?? '') === '1';

        $rules = [
            'service_category'      => ['label' => 'Category', 'rules' => 'required|in_list[' . implode(',', $categoryIds) . ']'],
            'title'                 => ['label' => 'Title', 'rules' => 'required|max_length[255]'],
            'years'                 => ['label' => 'Years of Experience', 'rules' => 'required|is_natural|less_than_equal_to[70]'],
            'seniority'             => ['label' => 'Seniority', 'rules' => 'required|in_list[' . implode(',', array_keys(GigModel::SENIORITY_LEVELS)) . ']'],
            'credentials'           => ['label' => 'Credentials', 'rules' => 'required'],
            'relevant_achievements' => ['label' => 'Relevant Achievements', 'rules' => 'permit_empty'],
            'headline'              => ['label' => 'Headline', 'rules' => 'required|max_length[255]'],
            'about'                 => ['label' => 'Bio', 'rules' => 'required'],
            'services'              => ['label' => 'Services I offer', 'rules' => 'required|is_array'],
            'services.*.title'      => ['label' => 'Service Title', 'rules' => 'required|max_length[255]'],
            'how_i_work'            => ['label' => 'How I Work', 'rules' => 'required'],
            'locations'             => ['label' => 'Preferred location of work', 'rules' => 'required|is_array'],
            'available_from'        => ['label' => 'Available From', 'rules' => $availableNow ? 'permit_empty' : 'required|valid_date[Y-m-d]'],
            'capacity'              => ['label' => 'Capacity', 'rules' => 'required|is_natural_no_zero|less_than_equal_to[5]'],
            'availability'          => ['label' => 'Availability Notes', 'rules' => 'permit_empty'],
            'rate_per_hour'         => ['label' => 'Rate (per hour)', 'rules' => 'required|is_natural_no_zero|less_than_equal_to[100000]'],
            'rate_notes'            => ['label' => 'Rate Notes', 'rules' => 'permit_empty'],
            'search_tags.*'         => ['label' => 'Search Tag', 'rules' => 'max_length[50]'],
            'terms'                 => ['label' => 'Terms of Service', 'rules' => 'required', 'errors' => ['required' => 'Please accept the Terms of Service.']],
        ];
        if ($specialisations !== []) {
            $rules['specialisation'] = ['label' => 'Specialisation', 'rules' => 'required|in_list[' . implode(',', array_keys($specialisations)) . ']'];
        }

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

        if (! $this->validateData($data, $rules)) {
            return redirect()->back()->withInput();
        }

        // in_list splits on commas, which the location names contain.
        if (array_diff((array) $data['locations'], ShowcaseModel::LOCATIONS) !== []) {
            return redirect()->back()->withInput()
                ->with('errors', ['locations' => 'Please choose locations from the list.']);
        }

        $stored = [];
        $photo  = isset($rules['photo']) ? $this->request->getFile('photo') : null;
        if ($photo !== null && $photo->isValid() && ! $photo->hasMoved()) {
            $stored['photo'] = $photo->store('showcases/photos');
        }

        $documents = [];
        foreach (ShowcaseModel::DOCUMENT_CATEGORIES as $key => $label) {
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

        $showcases  = new ShowcaseModel();
        $showcaseId = $showcases->createWithChildren(
            [
                'user_id'               => auth()->id(),
                'category_id'           => (int) $data['service_category'],
                'speciality_id'         => $specialisations !== [] ? (int) $data['specialisation'] : null,
                'title'                 => trim($data['title']),
                'years_experience'      => (int) $data['years'],
                'seniority'             => $data['seniority'],
                'credentials'           => $data['credentials'],
                'relevant_achievements' => $data['relevant_achievements'] !== '' ? $data['relevant_achievements'] : null,
                'headline'              => trim($data['headline']),
                'about'                 => $data['about'],
                'how_i_work'            => $data['how_i_work'],
                'locations'             => array_values(array_unique($data['locations'])),
                'available_now'         => $availableNow,
                'available_from'        => $availableNow ? null : $data['available_from'],
                'capacity'              => (int) $data['capacity'],
                'availability_notes'    => $data['availability'] !== '' ? $data['availability'] : null,
                'rate_per_hour'         => (int) $data['rate_per_hour'],
                'rate_notes'            => $data['rate_notes'] !== '' ? $data['rate_notes'] : null,
                'search_tags'           => $data['search_tags'] !== [] ? $data['search_tags'] : null,
                'photo_path'            => $stored['photo'] ?? null,
            ],
            array_map(static fn (array $s): array => [
                'title'       => $s['title'],
                'description' => $s['detail'] !== '' ? $s['detail'] : null,
            ], $data['services']),
            $documents
        );

        if ($showcaseId === false) {
            foreach ($stored as $path) {
                @unlink(WRITEPATH . 'uploads/' . $path);
            }

            return redirect()->back()->withInput()
                ->with('errors', $showcases->errors() ?: ['showcase' => 'Your showcase could not be saved. Please try again.']);
        }

        return redirect()->to(url_to('showcase-new'))->with('message', 'Your showcase has been published.');
    }

    /**
     * Whether the user picked at least one file for this input.
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

    /**
     * Trimmed, de-duplicated (case-insensitive) tags, capped at 20.
     *
     * @return list<string>
     */
    private function normaliseTags(array $tags): array
    {
        $unique = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && ! isset($unique[mb_strtolower($tag)])) {
                $unique[mb_strtolower($tag)] = $tag;
            }
        }

        return array_slice(array_values($unique), 0, 20);
    }
}
