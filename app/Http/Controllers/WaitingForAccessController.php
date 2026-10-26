<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Waiting for access » (SCRUM-82) : un compte sans team voit qui contacter et l'adresse à donner,
 * au lieu d'un dashboard vide. Dès qu'il a une team, la page renvoie sur le dashboard.
 */
class WaitingForAccessController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user()->teams()->exists()) {
            return redirect()->route('dashboard');
        }

        return view('auth.waiting-for-access', [
            'contact' => config('registration.contact'),
        ]);
    }
}
