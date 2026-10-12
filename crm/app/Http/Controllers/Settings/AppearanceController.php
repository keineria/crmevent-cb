<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Themes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/appearance');
    }

    /**
     * Saves the colour theme and light/dark mode on the account, so they follow the user to every device.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'string', Rule::in(Themes::THEMES)],
            'appearance' => ['sometimes', 'string', Rule::in(Themes::APPEARANCES)],
        ]);

        $request->user()->forceFill($data)->save();

        return back();
    }
}
