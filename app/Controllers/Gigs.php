<?php

namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\GigModel;
use App\Models\SpecialityModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Hirers post gigs here; the route is behind Shield's session filter.
 */
class Gigs extends BaseController
{
    public function new(): string
    {
        return view('create-gig', [
            'categoryGroups'  => (new CategoryModel())->activeByService(),
            'specialisations' => (new SpecialityModel())->activeByCategory(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $gigs               = new GigModel();
        $categoryIds        = array_keys((new CategoryModel())->activeOptions());
        $specialityCategory = (new SpecialityModel())->activeCategoryMap();

        $rules = [
            'gig_title'         => ['label' => 'Gig Title', 'rules' => 'required|max_length[500]'],
            'gig_summary'       => ['label' => 'Short Gig Description', 'rules' => 'required'],
            'categories'        => ['label' => 'Category', 'rules' => 'required|is_array'],
            'categories.*'      => ['label' => 'Category', 'rules' => 'in_list[' . implode(',', $categoryIds) . ']'],
            'specialisations'   => ['label' => 'Specialisation', 'rules' => 'required|is_array'],
            'specialisations.*' => ['label' => 'Specialisation', 'rules' => 'in_list[' . implode(',', array_keys($specialityCategory)) . ']'],
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput();
        }

        $data = $this->validator->getValidated();

        $parents = array_map(static fn ($id): string => (string) $specialityCategory[$id], $data['specialisations']);
        if (array_diff($parents, array_map('strval', $data['categories'])) !== []) {
            return redirect()->back()->withInput()
                ->with('errors', ['specialisations' => 'Each specialisation must belong to one of the chosen categories.']);
        }

        $gigId = $gigs->createWithTaxonomy(
            [
                'user_id' => auth()->id(),
                'title'   => trim($data['gig_title']),
                'summary' => trim($data['gig_summary']),
            ],
            $data['categories'],
            $data['specialisations']
        );

        if ($gigId === false) {
            return redirect()->back()->withInput()
                ->with('errors', $gigs->errors() ?: ['gig' => 'Your gig could not be saved. Please try again.']);
        }

        return redirect()->to(url_to('gig-new'))->with('message', 'Your gig has been posted.');
    }
}
