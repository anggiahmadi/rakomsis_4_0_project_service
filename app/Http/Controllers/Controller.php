<?php

namespace App\Http\Controllers;

abstract class Controller
{
    public function getPerPage($page = 10)
    {
        return $page;
    }
}
