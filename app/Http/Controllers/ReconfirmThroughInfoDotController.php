<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in again through InfoDot": ends this session and starts a fresh central sign-in that returns to the
 * profile page, so a person without a local password can confirm a sensitive account action.
 */
class ReconfirmThroughInfoDotController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', route('profile.show', absolute: false));

        return redirect()->route('dotsso.login');
    }
}
