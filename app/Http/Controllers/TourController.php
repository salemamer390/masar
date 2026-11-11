<?php

namespace App\Http\Controllers;

use App\Support\GuidedTour;
use Illuminate\Http\Request;

class TourController extends Controller
{
    /**
     * Persist the tour stop the user is heading to. Called by the front-end
     * right before it navigates to that stop's page, so the tour resumes
     * there even after a full page load.
     */
    public function progress(Request $request)
    {
        $validated = $request->validate(['step' => ['required', 'string', 'max:50']]);

        $keys = array_column(GuidedTour::stepsFor($request->user()), 'key');
        abort_unless(in_array($validated['step'], $keys, true), 422);

        $request->user()->forceFill(['tour_step' => $validated['step']])->save();

        return response()->noContent();
    }

    /**
     * Mark the guided tour as seen for the current user. Called by the
     * front-end when the tour is finished or skipped.
     */
    public function complete(Request $request)
    {
        $request->user()->forceFill(['tour_seen_at' => now(), 'tour_step' => null])->save();

        return response()->noContent();
    }

    /**
     * Replay entry point from the user menu: clear the flag and send the user
     * back to the page they were on, where the tour starts automatically.
     */
    public function reset(Request $request)
    {
        $request->user()->forceFill(['tour_seen_at' => null, 'tour_step' => null])->save();

        return redirect()->back()->with('toast', __('تبدأ الجولة التعريفية الآن.'));
    }
}
