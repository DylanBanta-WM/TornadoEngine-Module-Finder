<?php

namespace TornadoEngine\ModuleFinder\Tests;

use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Updates\UpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModuleFinderTest extends TestCase
{
    use RefreshDatabase;

    private string $installed;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tornado.version' => '0.7.0']);
        Cache::forget('module-finder.found');

        // Installed here: GAM 0.4.0 (a newer one is on Packagist) and a module GitHub doesn't list.
        $this->installed = sys_get_temp_dir().'/installed.'.uniqid().'.json';
        file_put_contents($this->installed, json_encode(['packages' => [
            ['name' => 'tornado-engine/gam', 'pretty_version' => 'v0.4.0', 'extra' => ['tornado' => ['name' => 'GAM', 'core' => '>=0.6']]],
            ['name' => 'district/homegrown', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Homegrown', 'core' => '>=0.6', 'php-extensions' => ['zz_not_installed']]]],
        ]]));
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->installed, '0.7.0'));

        $repo = fn ($owner, $name, $extra = []) => ['full_name' => "{$owner}/{$name}", 'owner' => ['login' => $owner], 'html_url' => "https://github.com/{$owner}/{$name}", 'default_branch' => 'main', 'description' => "{$name} description"] + $extra;
        $composer = fn ($package, $name) => ['name' => $package, 'extra' => ['tornado' => ['name' => $name, 'core' => '>=0.6']]];

        Http::fake([
            'api.github.com/search/repositories*' => Http::response(['items' => [
                $repo('DylanBanta-WM', 'TornadoEngine-GAM'),
                $repo('DylanBanta-WM', 'TornadoEngine-GAM-Runner'),
                $repo('someone', 'TornadoWidgets'),
                $repo('someone', 'OldThing', ['archived' => true]),
                $repo('someone', 'NotAModule'),
            ]]),
            'raw.githubusercontent.com/DylanBanta-WM/TornadoEngine-GAM/*' => Http::response($composer('tornado-engine/gam', 'GAM')),
            'raw.githubusercontent.com/DylanBanta-WM/TornadoEngine-GAM-Runner/*' => Http::response($composer('tornado-engine/gam-runner', 'GAM Runner')),
            'raw.githubusercontent.com/someone/TornadoWidgets/*' => Http::response($composer('someone/widgets', 'Widgets')),
            'raw.githubusercontent.com/someone/NotAModule/*' => Http::response(['name' => 'someone/not-a-module']),
            // Packagist's minified format: later entries only list what changed.
            'repo.packagist.org/p2/tornado-engine/gam.json' => Http::response(['packages' => ['tornado-engine/gam' => [
                ['version' => 'v0.9.0', 'extra' => ['tornado' => ['core' => '>=0.8']]],
                ['version' => 'v0.5.0', 'extra' => ['tornado' => ['core' => '>=0.6']]],
                ['version' => 'v0.4.0'],
                ['version' => 'dev-main'],
            ]]]),
            'repo.packagist.org/p2/tornado-engine/gam-runner.json' => Http::response(['packages' => ['tornado-engine/gam-runner' => [
                ['version' => 'v0.1.0', 'extra' => ['tornado' => ['core' => '>=0.7']]],
            ]]]),
            'repo.packagist.org/*' => Http::response([], 404),
            'api.github.com/repos/someone/TornadoWidgets/tags*' => Http::response([['name' => 'v2.1.0'], ['name' => 'v2.0.0']]),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->installed);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_modules_are_found_and_sorted_into_installed_official_and_community(): void
    {
        $this->actingAs($this->admin())->get('/modules')
            ->assertOk()
            ->assertSeeInOrder(['Installed', 'GAM', 'Installed: 0.4.0.', '0.5.0 is available.', '0.9.0 needs a newer Tornado Engine Core.', 'Update to 0.5.0', 'Homegrown', 'Off until the server is rebuilt with zz_not_installed', 'Not listed on GitHub',
                'Official modules', 'GAM Runner', 'Official', 'Version 0.1.0.', 'Install',
                'Community modules', 'Install them at your own risk', 'Widgets', 'Community', 'Version 2.1.0.', 'View the code'])
            ->assertDontSee('OldThing')
            ->assertDontSee('not-a-module');
    }

    public function test_installing_queues_a_job_for_the_updater(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/modules/install', ['package' => 'someone/widgets'])->assertSessionHas('modules_status');
        $job = UpdateJob::sole();
        $this->assertSame(['module', 'someone/widgets', '2.1.0', 'https://github.com/someone/TornadoWidgets'], [$job->kind, $job->package, $job->version, $job->repository]);

        // One at a time.
        $this->actingAs($admin)->from('/modules')->post('/modules/install', ['package' => 'tornado-engine/gam-runner'])->assertSessionHasErrors('modules');
        $job->update(['status' => 'done']);

        $this->actingAs($admin)->post('/modules/install', ['package' => 'tornado-engine/gam']);
        $update = UpdateJob::latest('id')->first();
        $this->assertSame(['tornado-engine/gam', '0.5.0', null], [$update->package, $update->version, $update->repository]);
    }

    public function test_only_found_modules_with_something_new_can_be_installed(): void
    {
        $admin = $this->admin();

        foreach (['evil/package', 'district/homegrown'] as $package) {
            $this->actingAs($admin)->from('/modules')->post('/modules/install', ['package' => $package])->assertSessionHasErrors('modules');
        }
        $this->assertSame(0, UpdateJob::count());
    }

    public function test_github_being_down_still_shows_installed_modules(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 500)]);
        Cache::forget('module-finder.found');

        $this->actingAs($this->admin())->get('/modules')->assertOk()->assertSee('Couldn\'t reach GitHub')->assertSee('Homegrown');
    }

    public function test_only_people_allowed_to_manage_modules_can_open_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/modules')->assertForbidden();
        $this->actingAs(User::factory()->create())->post('/modules/install', ['package' => 'tornado-engine/gam'])->assertForbidden();
    }
}
