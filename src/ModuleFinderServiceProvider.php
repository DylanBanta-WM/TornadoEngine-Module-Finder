<?php

namespace TornadoEngine\ModuleFinder;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Access\Access;
use TornadoEngine\Nav\Nav;

class ModuleFinderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-finder');
        $this->loadRoutesFrom(__DIR__.'/../routes/module-finder.php');

        // The setup admin, and roles given this permission (with the Access module).
        Gate::define('modules.manage', fn (User $user) => (bool) $user->is_admin);
        $this->callAfterResolving(Access::class, function (Access $access) {
            $access->permission('modules.manage', __('Install and update modules'), 'Core');
        });

        $this->callAfterResolving(Nav::class, function (Nav $nav) {
            $nav->user(__('Modules'), 'modules.index', active: 'modules.*', order: 850, can: 'modules.manage');
            if (method_exists($nav, 'tool')) {
                $nav->tool(__('Modules'), 'modules.index', __('Find, install and update modules.'),
                    section: __('Administration'), icon: 'puzzle', color: 'purple', can: 'modules.manage');
            }
        });
    }
}
