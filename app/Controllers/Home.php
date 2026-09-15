<?php

namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\SpecialityModel;

class Home extends BaseController
{
    public function index(): string
    {
        return view('home');
    }

    public function createProfile(): string
    {
        return view('create-profile', [
            'categories' => (new CategoryModel())->activeNames(),
        ]);
    }

    public function createGig(): string
    {
        return view('create-gig', [
            'categories'      => (new CategoryModel())->activeNames(),
            'specialisations' => (new SpecialityModel())->activeNames(),
        ]);
    }
}
