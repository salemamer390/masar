<?php

namespace App\Http\Controllers;

/** Standalone blank S2 insurance wage-adjustment form. */
class EditableS2Controller extends Controller
{
    public function show()
    {
        return view('forms.s2-editable');
    }
}
