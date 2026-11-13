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
    public function index(Finder $finder): View
    {
        $listings = collect($finder->listings());

        return view('module-finder::index', [
            'installed' => $listings->filter(fn (Listing $listing) => $listing->installed !== null),
            'official' => $listings->filter(fn (Listing $listing) => $listing->installed === null && $listing->official),
            'community' => $listings->filter(fn (Listing $listing) => $listing->installed === null && ! $listing->official),
            'reachable' => $finder->reachable(),
            'jobs' => UpdateJob::with('user')->where('kind', 'module')->latest('id')->take(5)->get(),
            'busy' => UpdateJob::busy(),
        ]);
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

        UpdateJob::queue('module', $listing->latest, $listing->package, $listing->onPackagist ? null : $listing->url);

        return back()->with('modules_status', $listing->installed
            ? __('Updating :name to :version.', ['name' => $listing->name, 'version' => $listing->latest])
            : __('Installing :name :version.', ['name' => $listing->name, 'version' => $listing->latest]));
    }
}
