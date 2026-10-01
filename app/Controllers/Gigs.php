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
            'categories'      => (new CategoryModel())->activeOptions(),
            'specialisations' => (new SpecialityModel())->activeOptions(),
            'seniorityLevels' => GigModel::SENIORITY_LEVELS,
        ]);
    }

    public function create(): RedirectResponse
    {
        $gigs          = new GigModel();
        $categoryIds   = array_keys((new CategoryModel())->activeOptions());
        $specialityIds = array_keys((new SpecialityModel())->activeOptions());

        $rules = [
            'gig_title'         => ['label' => 'Gig Title', 'rules' => 'required|max_length[500]'],
            'gig_summary'       => ['label' => 'Short Gig Description', 'rules' => 'required'],
            'gig_notes'         => ['label' => 'Additional Notes', 'rules' => 'permit_empty'],
            'budget_min'        => ['label' => 'Minimum Budget', 'rules' => 'permit_empty|decimal|greater_than_equal_to[0]'],
            'budget_max'        => ['label' => 'Maximum Budget', 'rules' => 'permit_empty|decimal|greater_than_equal_to[0]'],
            'seniority'         => ['label' => 'Seniority', 'rules' => 'required|in_list[' . implode(',', array_keys(GigModel::SENIORITY_LEVELS)) . ']'],
            'start_date'        => ['label' => 'Start Date', 'rules' => 'permit_empty|valid_date[Y-m-d]'],
            'categories'        => ['label' => 'Category', 'rules' => 'required|is_array'],
            'categories.*'      => ['label' => 'Category', 'rules' => 'in_list[' . implode(',', $categoryIds) . ']'],
            'specialisations'   => ['label' => 'Specialisation', 'rules' => 'required|is_array'],
            'specialisations.*' => ['label' => 'Specialisation', 'rules' => 'in_list[' . implode(',', $specialityIds) . ']'],
        ];

        // The document is optional; the file rules fail on a missing file, so
        // only apply them when one was actually chosen.
        $document = $this->request->getFile('gig_document');
        if ($document !== null && $document->getError() !== UPLOAD_ERR_NO_FILE) {
            $rules['gig_document'] = [
                'label' => 'Upload Description',
                'rules' => 'uploaded[gig_document]|max_size[gig_document,4096]|ext_in[gig_document,pdf,doc,docx]'
                    . '|mime_in[gig_document,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document]',
            ];
        }

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput();
        }

        $data = $this->validator->getValidated();

        $budgetMin = $data['budget_min'] ?? '';
        $budgetMax = $data['budget_max'] ?? '';
        if ($budgetMin !== '' && $budgetMax !== '' && (float) $budgetMin > (float) $budgetMax) {
            return redirect()->back()->withInput()
                ->with('errors', ['budget_max' => 'Maximum Budget must be greater than or equal to Minimum Budget.']);
        }

        $documentPath = null;
        if (isset($rules['gig_document']) && $document->isValid() && ! $document->hasMoved()) {
            // Stored under writable/uploads/gigs/ with a random name.
            $documentPath = $document->store('gigs');
        }

        $gigId = $gigs->createWithTaxonomy(
            [
                'user_id'       => auth()->id(),
                'title'         => $data['gig_title'],
                'summary'       => $data['gig_summary'],
                'notes'         => ($data['gig_notes'] ?? '') !== '' ? $data['gig_notes'] : null,
                'document_path' => $documentPath,
                'budget_min'    => $budgetMin !== '' ? $budgetMin : null,
                'budget_max'    => $budgetMax !== '' ? $budgetMax : null,
                'seniority'     => $data['seniority'],
                'start_date'    => ($data['start_date'] ?? '') !== '' ? $data['start_date'] : null,
            ],
            $data['categories'],
            $data['specialisations']
        );

        if ($gigId === false) {
            if ($documentPath !== null) {
                @unlink(WRITEPATH . 'uploads/' . $documentPath);
            }

            return redirect()->back()->withInput()
                ->with('errors', $gigs->errors() ?: ['gig' => 'Your gig could not be saved. Please try again.']);
        }

        return redirect()->to(url_to('gig-new'))->with('message', 'Your gig has been posted.');
    }
}
