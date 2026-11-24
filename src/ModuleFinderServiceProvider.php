<?php

namespace TornadoEngine\ModuleFinder;

use App\Administration\Administration;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Access\Access;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Nav\Nav;

class ModuleFinderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleActions::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-finder');
        $this->loadRoutesFrom(__DIR__.'/../routes/module-finder.php');

        // The setup admin, and roles given this permission (with the Access module).
        Gate::define('modules.manage', fn (User $user) => (bool) $user->is_admin);
        $this->callAfterResolving(Access::class, function (Access $access) {
            $access->permission('modules.manage', __('Install and update modules'), 'Core');
        });

        // Its switch is on Settings → Updates.
        $this->callAfterResolving(Administration::class, fn ($administration) => $administration->card('module-finder::auto-update-card', order: 30, tab: 'updates'));

        // Official modules' updates marked "required" install by themselves (Administration → Scheduled jobs).
        $this->callAfterResolving(Cron::class, function ($cron) {
            $cron->job('modules.auto-update', __('Install required module updates'), function () {
                $job = app(AutoUpdate::class)->run();

                return $job ? __('Queued :package :version.', ['package' => $job->package, 'version' => $job->version]) : __('Nothing to install.');
            }, schedule: '20 * * * *', description: __('Installs updates marked required for official modules. Other districts\' modules are never updated by themselves.'));
        });

        $this->callAfterResolving(Nav::class, function (Nav $nav) {
            $nav->add(__('Modules'), 'modules.index', active: 'modules.*', order: 30, can: 'modules.manage', group: 'administration');
            if (method_exists($nav, 'tool')) {
                $nav->tool(__('Modules'), 'modules.index', __('Find, install and update modules.'),
                    section: __('Administration'), icon: 'puzzle', color: 'purple', can: 'modules.manage');
            }
        });
    }
}
