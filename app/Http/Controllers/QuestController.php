<?php

namespace App\Http\Controllers;

use App\Services\Quests;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reads and one write, both thin — the service owns the rules and the
 * accept-once guarantee, for the same reason QuizRewards is not in
 * QuizController.
 */
class QuestController extends Controller
{
    public function index(Request $request, Quests $quests): View
    {
        return view('quests.index', [
            'quests' => $quests->progressFor($request->user()),
        ]);
    }

    /**
     * The key arrives from the form, so it's untrusted twice over: claim()
     * rejects an unknown key, and rejects a known one whose threshold hasn't
     * been met. Nothing here re-checks either — duplicating the rules in the
     * controller is how the two copies diverge.
     *
     * Back to the page rather than to a receipt: the bar and the balance pill
     * both change, and the page is the confirmation.
     */
    public function claim(Request $request, Quests $quests): RedirectResponse
    {
        $data = $request->validate([
            'quest' => ['required', 'string', 'max:32'],
        ]);

        $paid = $quests->claim($request->user(), $data['quest']);

        return redirect()
            ->route('quests.index')
            ->with('status', $paid ? 'Quest claimed.' : 'That quest isn’t ready to claim.');
    }
}
