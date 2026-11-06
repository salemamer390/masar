<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Mobile uses the existing session and CSRF protection, never a demo login. */
class MobileJson
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        // Legacy payroll controllers report some outcomes through flash state.
        // Drop old flash values so a previous web request cannot mask this outcome.
        if (!$request->isMethod('GET')) {
            $request->session()->forget(['errors', 'toast']);
        }
        $response = $next($request);
        if ($response instanceof \Illuminate\Http\RedirectResponse && !$request->isMethod('GET')) {
            $errors = $request->session()->get('errors');
            if ($errors instanceof \Illuminate\Support\ViewErrorBag && $errors->any()) {
                $messages = $errors->getBag('default')->messages();
                $request->session()->forget('errors');
                $response = response()->json(['message' => collect($messages)->flatten()->first(), 'errors' => $messages],422);
            } elseif ($request->session()->has('toast')) {
                $response = response()->json(['success'=>true,'message'=>$request->session()->get('toast')]);
                $request->session()->forget('toast');
            } else {
                // Never convert an unexplained redirect into a claimed mutation.
                $response = response()->json(['message'=>'تعذر تأكيد نتيجة العملية. حدّث البيانات قبل تكرارها.'], 502);
            }
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }
}
