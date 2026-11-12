<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\TourStep;
use App\Models\User;
use App\Support\GuidedTour;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * إدارة الجولة التعريفية من لوحة المالك: تعديل النصوص والترتيب والتفعيل
 * وإضافة خطوات (يدويًا أو باختيار العنصر من الشاشة) على مسودة لا يراها
 * المستخدمون، ثم معاينة ونشر. الجولة تبقى إجبارية: خطوات البداية والنهاية
 * لا تُحذف ولا تُوقف، والنشر لا يعيد الجولة لمن أنهاها إلا بطلب صريح.
 */
class OwnerTourController extends Controller
{
    public function index()
    {
        if (! GuidedTour::tableReady()) {
            return view('owner.tour', [
                'tableReady' => false,
                'steps' => collect(),
                'hasDraft' => false,
                'routes' => [],
                'permissions' => [],
            ]);
        }

        $hasDraft = TourStep::draft()->exists();

        return view('owner.tour', [
            'tableReady' => true,
            'hasDraft' => $hasDraft,
            'steps' => TourStep::where('is_draft', $hasDraft)->orderBy('sort_order')->get(),
            'liveCount' => TourStep::live()->count(),
            'lastPublished' => TourStep::live()->max('published_at'),
            'routes' => $this->tourableRoutes(),
            'permissions' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    public function startDraft()
    {
        abort_unless(GuidedTour::tableReady(), 404);

        if (TourStep::draft()->exists()) {
            return back()->with('toast', __('توجد مسودة قائمة بالفعل.'));
        }

        $this->createDraftSet();

        return redirect()->route('owner.tour.index')->with('toast', __('بدأت المسودة. عدّل ثم عاين وانشر.'));
    }

    public function discardDraft()
    {
        TourStep::draft()->delete();

        return redirect()->route('owner.tour.index')->with('toast', __('تم تجاهل المسودة، وبقيت الجولة المنشورة كما هي.'));
    }

    public function storeStep(Request $request)
    {
        $data = $request->validate([
            'route_name' => ['required', 'string', 'max:120', function ($attribute, $value, $fail) {
                if (! Route::has($value) || ! array_key_exists($value, $this->tourableRoutes())) {
                    $fail(__('اختر صفحة من القائمة.'));
                }
            }],
            'selector' => ['required', 'string', 'max:300'],
            'permission' => ['nullable', 'string', 'max:120', 'exists:permissions,name'],
            'icon' => ['nullable', 'string', 'max:60'],
            'title_ar' => ['required', 'string', 'max:500'],
            'body_ar' => ['required', 'string', 'max:2000'],
            'title_en' => ['nullable', 'string', 'max:500'],
            'body_en' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureDraftSet();

        $step = $this->makeDraftStep($data['route_name'], $data['selector'], $data);

        return back()->with('toast', __('أُضيفت الخطوة «:title» إلى المسودة.', ['title' => $step->title_ar]));
    }

    /**
     * Saves a step picked visually from an app page (?tour_edit=1). The
     * picker knows the current route and the clicked element's selector.
     */
    public function storePickedStep(Request $request)
    {
        $data = $request->validate([
            'route_name' => ['required', 'string', 'max:120', function ($attribute, $value, $fail) {
                if (! Route::has($value)) {
                    $fail('unknown route');
                }
            }],
            'selector' => ['required', 'string', 'max:300'],
            'permission' => ['nullable', 'string', 'max:120', 'exists:permissions,name'],
            'title_ar' => ['required', 'string', 'max:500'],
            'body_ar' => ['required', 'string', 'max:2000'],
            'title_en' => ['nullable', 'string', 'max:500'],
            'body_en' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureDraftSet();

        $step = $this->makeDraftStep($data['route_name'], $data['selector'], $data);

        return response()->json([
            'ok' => true,
            'key' => $step->key,
            'title' => $step->title_ar,
            'draft_count' => TourStep::draft()->count(),
        ]);
    }

    public function updateStep(Request $request, TourStep $tourStep)
    {
        abort_unless($tourStep->is_draft, 404);

        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:500'],
            'body_ar' => ['required', 'string', 'max:2000'],
            'title_en' => ['nullable', 'string', 'max:500'],
            'body_en' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:60'],
            'target' => ['nullable', 'string', 'max:1000'],
        ]);

        $tourStep->fill([
            'title_ar' => $data['title_ar'],
            'body_ar' => $data['body_ar'],
            'title_en' => $data['title_en'] ?? null,
            'body_en' => $data['body_en'] ?? null,
            'icon' => $data['icon'] ?: $tourStep->icon,
        ]);

        if ($tourStep->route_name !== null && array_key_exists('target', $data)) {
            $selectors = collect(preg_split('/\r?\n/', (string) $data['target']))
                ->map(fn ($line) => trim($line))
                ->filter()
                ->values()
                ->all();
            $tourStep->target = $selectors ?: null;
        }

        $tourStep->save();

        return back()->with('toast', __('حُفظت الخطوة في المسودة.'));
    }

    public function moveStep(Request $request, TourStep $tourStep)
    {
        abort_unless($tourStep->is_draft, 404);
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);

        $sibling = TourStep::draft()
            ->when(
                $data['direction'] === 'up',
                fn ($q) => $q->where('sort_order', '<', $tourStep->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $tourStep->sort_order)->orderBy('sort_order')
            )
            ->first();

        if ($sibling) {
            DB::transaction(function () use ($tourStep, $sibling) {
                [$tourStep->sort_order, $sibling->sort_order] = [$sibling->sort_order, $tourStep->sort_order];
                $tourStep->save();
                $sibling->save();
            });
        }

        return back();
    }

    public function toggleStep(TourStep $tourStep)
    {
        abort_unless($tourStep->is_draft, 404);

        if ($tourStep->is_system) {
            return back()->with('toast', __('خطوات البداية والنهاية أساسية ولا يمكن إيقافها.'));
        }

        $tourStep->forceFill(['is_active' => ! $tourStep->is_active])->save();

        return back()->with('toast', $tourStep->is_active ? __('فُعّلت الخطوة.') : __('أُوقفت الخطوة في المسودة.'));
    }

    public function destroyStep(TourStep $tourStep)
    {
        abort_unless($tourStep->is_draft, 404);

        if ($tourStep->is_system) {
            return back()->with('toast', __('خطوات البداية والنهاية أساسية ولا يمكن حذفها.'));
        }

        $tourStep->delete();

        return back()->with('toast', __('حُذفت الخطوة من المسودة.'));
    }

    public function publish(Request $request)
    {
        $data = $request->validate(['reshow' => ['nullable', 'boolean']]);

        abort_unless(TourStep::draft()->exists(), 404);

        DB::transaction(function () {
            TourStep::live()->delete();
            TourStep::draft()->update(['is_draft' => false, 'published_at' => now(), 'updated_at' => now()]);
        });

        if (! empty($data['reshow']) && GuidedTour::isTracked()) {
            User::query()->update(['tour_seen_at' => null, 'tour_step' => null]);
        }

        return redirect()->route('owner.tour.index')->with('toast', ! empty($data['reshow'])
            ? __('نُشرت الجولة، وستظهر من جديد لكل المستخدمين.')
            : __('نُشرت الجولة. من أنهاها سابقًا لن تظهر له من جديد.'));
    }

    /**
     * Copies the published set into a draft set. When the table holds no
     * published rows yet (never seeded), the built-in tour becomes the
     * starting content instead.
     */
    private function createDraftSet(): void
    {
        $now = now();
        $live = TourStep::live()->orderBy('sort_order')->get();

        if ($live->isEmpty()) {
            foreach (GuidedTour::defaults() as $index => $step) {
                TourStep::create([
                    'key' => $step['key'],
                    'route_name' => $step['route'],
                    'target' => $step['target'],
                    'icon' => $step['icon'],
                    'permission' => $step['permission'],
                    'title_ar' => $step['title'],
                    'body_ar' => $step['body'],
                    'title_en' => null,
                    'body_en' => null,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                    'is_system' => $step['system'],
                    'is_draft' => true,
                ]);
            }

            return;
        }

        foreach ($live as $step) {
            $copy = $step->replicate(['published_at']);
            $copy->is_draft = true;
            $copy->published_at = null;
            $copy->created_at = $now;
            $copy->updated_at = $now;
            $copy->save();
        }
    }

    private function ensureDraftSet(): void
    {
        if (! TourStep::draft()->exists()) {
            $this->createDraftSet();
        }
    }

    private function makeDraftStep(string $routeName, string $selector, array $data): TourStep
    {
        $base = Str::slug(str_replace('.', '-', $routeName)) ?: 'step';
        $key = $base;
        $suffix = 2;
        while (TourStep::draft()->where('key', $key)->exists()) {
            $key = $base.'-'.$suffix++;
        }

        $selectors = array_values(array_unique([$selector, '.page-head']));

        // New steps land right before the closing step so the tour still ends
        // with it; without a closing step they simply go last.
        $closing = TourStep::draft()->where('key', 'closing')->first();
        if ($closing) {
            $sortOrder = $closing->sort_order;
            $closing->forceFill(['sort_order' => $closing->sort_order + 10])->save();
        } else {
            $sortOrder = (int) TourStep::draft()->max('sort_order') + 10;
        }

        return TourStep::create([
            'key' => $key,
            'route_name' => $routeName,
            'target' => $selectors,
            'icon' => $data['icon'] ?? 'bi-hand-index',
            'permission' => $data['permission'] ?? null,
            'title_ar' => $data['title_ar'],
            'body_ar' => $data['body_ar'],
            'title_en' => $data['title_en'] ?? null,
            'body_en' => $data['body_en'] ?? null,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'is_system' => false,
            'is_draft' => true,
        ]);
    }

    /**
     * Named GET pages a tour step can point at: the regular authenticated
     * app pages (no owner panel, no tour callbacks, no parametrized URLs).
     */
    private function tourableRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && $route->getName()
                && ! str_contains($route->uri(), '{')
                && in_array('auth', $route->gatherMiddleware(), true)
                && ! str_starts_with($route->getName(), 'owner.')
                && ! str_starts_with($route->getName(), 'tour.'))
            ->mapWithKeys(fn ($route) => [$route->getName() => $route->getName().'  —  /'.$route->uri()])
            ->sort()
            ->all();
    }
}
