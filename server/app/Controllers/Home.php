<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        return view('home');
    }

    public function createProfile(): string
    {
        return view('create-profile');
    }

    public function createGig(): string
    {
        return view('create-gig');
    }
}
