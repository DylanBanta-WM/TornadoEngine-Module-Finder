<?php

namespace TornadoEngine\ModuleFinder\Http;

use App\Updates\UpdateJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use TornadoEngine\ModuleFinder\Finder;
use TornadoEngine\ModuleFinder\Listing;

class ModulesController extends Controller
{
    public function index(Request $request, Finder $finder): View|RedirectResponse
    {
        if ($settings = $this->settingsToOpen($request)) {
            return redirect($settings);
        }

        $listings = collect($finder->listings());

        return view('module-finder::index', [
            'installed' => $listings->filter(fn (Listing $listing) => $listing->installed !== null),
            'official' => $listings->filter(fn (Listing $listing) => $listing->installed === null && $listing->official),
            'community' => $listings->filter(fn (Listing $listing) => $listing->installed === null && ! $listing->official),
            'reachable' => $finder->reachable(),
            'jobs' => UpdateJob::with('user')->where('kind', 'module')->latest('id')->take(200)->get(),
            'busy' => UpdateJob::busy(),
            'all' => $listings->all(),
        ]);
    }

    /**
     * After someone installs a module that needs setting up, its settings page, once: when the
     * install (and any rebuild it needs) is done.
     */
    private function settingsToOpen(Request $request): ?string
    {
        if (UpdateJob::busy() || (class_exists(\App\Updates\Rebuild::class) && (\App\Updates\Rebuild::needed() || \App\Updates\Rebuild::running()))) {
            return null;
        }

        $modules = app(\App\Modules\ModuleRegistry::class)->all();
        foreach (UpdateJob::where('user_id', $request->user()->id)->where('kind', 'module')->where('install', true)->where('status', 'done')->whereNull('opened_at')->orderBy('id')->get() as $job) {
            $job->update(['opened_at' => now()]);
            $module = $modules[$job->package] ?? null;
            if ($module && $module->compatible && ($module->needsSetup ?? false) && $module->settings) {
                return url($module->settings);
            }
        }

        return null;
    }

    public function check(Finder $finder): RedirectResponse
    {
        $finder->listings(fresh: true);

        return back()->with('modules_status', $finder->reachable() ? __('Checked GitHub for modules.') : __('Couldn\'t reach GitHub. Try again later.'));
    }

    /**
     * Installs a module, or updates an installed one, to the newest version that works here.
     * Only modules the finder found can be installed.
     */
    public function install(Request $request, Finder $finder): RedirectResponse
    {
        $listing = $finder->listings()[(string) $request->input('package')] ?? null;

        if (! $listing || ! $listing->url || ! $listing->latest || ($listing->installed !== null && ! $listing->updatable())) {
            return back()->withErrors(['modules' => __('There\'s nothing to install for that module.')]);
        }
        if (UpdateJob::busy()) {
            return back()->withErrors(['modules' => __('Another install or update is still running. Wait for it to finish.')]);
        }

        UpdateJob::queue('module', $listing->latest, $listing->package, $listing->onPackagist ? null : $listing->url, install: $listing->installed === null);

        return back()->with('modules_status', $listing->installed
            ? __('Updating :name to :version.', ['name' => $listing->name, 'version' => $listing->latest])
            : __('Installing :name :version.', ['name' => $listing->name, 'version' => $listing->latest]));
    }
}
