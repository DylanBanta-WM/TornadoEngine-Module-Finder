<?php

namespace TornadoEngine\ModuleFinder\Tests;

use App\Models\Setting;
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
        Cache::forget('module-finder.found.v3');

        // Installed here: GAM 0.4.0 (a newer one is on Packagist) and a module GitHub doesn't list.
        $this->installed = sys_get_temp_dir().'/installed.'.uniqid().'.json';
        file_put_contents($this->installed, json_encode(['packages' => [
            ['name' => 'tornado-engine/gam', 'pretty_version' => 'v0.4.0', 'extra' => ['tornado' => ['name' => 'GAM', 'core' => '>=0.6', 'settings' => '/administration/gam']]],
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
                $repo('someone', 'GitOnly'),
                $repo('someone', 'OldThing', ['archived' => true]),
                $repo('someone', 'NotAModule'),
            ]]),
            'raw.githubusercontent.com/DylanBanta-WM/TornadoEngine-GAM/*' => Http::response($composer('tornado-engine/gam', 'GAM')),
            'raw.githubusercontent.com/DylanBanta-WM/TornadoEngine-GAM-Runner/*' => Http::response($composer('tornado-engine/gam-runner', 'GAM Runner')),
            'raw.githubusercontent.com/someone/TornadoWidgets/*' => Http::response($composer('someone/widgets', 'Widgets') + ['require' => ['tornado-engine/gam-runner' => '*', 'laravel/framework' => '*']]),
            'raw.githubusercontent.com/someone/NotAModule/*' => Http::response(['name' => 'someone/not-a-module']),
            'raw.githubusercontent.com/someone/GitOnly/*' => Http::response($composer('someone/git-only', 'Git Only')),
            'repo.packagist.org/p2/someone/widgets.json' => Http::response(['packages' => ['someone/widgets' => [
                ['version' => 'v2.1.0', 'extra' => ['tornado' => ['core' => '>=0.6']], 'require' => ['tornado-engine/gam-runner' => '*', 'laravel/framework' => '*']],
                ['version' => 'v2.0.0'],
            ]]]),
            // Packagist's minified format: later entries only list what changed.
            'repo.packagist.org/p2/tornado-engine/gam.json' => Http::response(['packages' => ['tornado-engine/gam' => [
                ['version' => 'v0.9.0', 'extra' => ['tornado' => ['core' => '>=0.8']]],
                ['version' => 'v0.5.0', 'extra' => ['tornado' => ['core' => '>=0.6']]],
                ['version' => 'v0.4.0'],
                ['version' => 'dev-main'],
            ]]]),
            'repo.packagist.org/p2/tornado-engine/gam-runner.json' => Http::response(['packages' => ['tornado-engine/gam-runner' => [
                ['version' => 'v0.1.0', 'extra' => ['tornado' => ['core' => '>=0.7']], 'require' => ['php' => '^8.4', 'tornado-engine/gam' => '>=0.5']],
            ]]]),
            'repo.packagist.org/*' => Http::response([], 404),
            // GAM 0.5.0 says it's a bug fix in its release notes.
            'api.github.com/repos/DylanBanta-WM/TornadoEngine-GAM/releases*' => Http::response([
                ['tag_name' => 'v0.5.0', 'body' => "Fixes the sync.\n\n**Type:** Bug fix\n"],
                ['tag_name' => 'v0.4.0', 'body' => 'Type: critical'],
            ]),
            'api.github.com/repos/*/releases*' => Http::response([]),
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

    public function test_modules_are_found_and_shown(): void
    {
        $this->actingAs($this->admin())->get('/modules')
            ->assertOk()
            ->assertSeeInOrder(['Official modules', 'GAM', '0.5.0 available', '0.9.0 needs a newer Tornado Engine Core.', 'href="http://localhost/administration/gam"', 'Uninstall', 'Update to 0.5.0',
                'GAM Runner', 'v0.1.0', 'Install',
                'Other districts&#039; modules', 'These aren&#039;t reviewed', 'Homegrown', 'Local', 'Off until the server is rebuilt with zz_not_installed',
                'Widgets', 'v2.1.0', 'Community', 'View the code'], false)
            ->assertDontSee('OldThing')
            ->assertDontSee('Git Only')
            ->assertDontSee('not-a-module');
    }

    /** @param list<array<string, mixed>> $packages */
    private function install(array $packages): void
    {
        file_put_contents($this->installed, json_encode(['packages' => $packages]));
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->installed, '0.7.0'));
    }

    public function test_modules_are_listed_as_official_then_this_districts_then_other_districts(): void
    {
        Setting::put('site.url', 'https://te.district.org');
        // A module linked from a folder in web/modules (GitHub's, under another vendor name here).
        $up = str_repeat('../', substr_count(trim(dirname($this->installed), '/'), '/') + 1);
        $this->install([
            ['name' => 'district/homegrown', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Homegrown', 'core' => '>=0.6']]],
            ['name' => 'tornado-engine/nav', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Nav', 'core' => '>=0.6', 'core-module' => true]]],
            ['name' => 'elsewhere/folder-thing', 'pretty_version' => 'dev-main', 'install-path' => $up.ltrim(base_path('modules/github'), '/')],
            ['name' => 'elsewhere/unlisted', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Unlisted', 'core' => '>=0.6']]],
        ]);

        $this->actingAs($this->admin())->get('/modules')
            ->assertOk()
            ->assertSeeInOrder(['Core modules', 'Nav', 'Official modules', 'GAM', 'GAM Runner',
                'Your district&#039;s modules', 'GitHub', 'elsewhere/folder-thing', 'Homegrown',
                'Other districts&#039; modules', 'These aren&#039;t reviewed, and a module can read and change anything the website can.', 'Unlisted', 'Widgets', 'Community'], false);
    }

    public function test_uninstall_is_offered_for_modules_nothing_else_needs(): void
    {
        $this->install([
            ['name' => 'tornado-engine/gam', 'pretty_version' => 'v0.5.0', 'extra' => ['tornado' => ['name' => 'GAM', 'core' => '>=0.6']]],
            ['name' => 'tornado-engine/gam-runner', 'pretty_version' => 'v0.1.0', 'require' => ['tornado-engine/gam' => '>=0.5'], 'extra' => ['tornado' => ['name' => 'GAM Runner', 'core' => '>=0.6']]],
            ['name' => 'tornado-engine/nav', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Nav', 'core' => '>=0.6', 'core-module' => true]]],
        ]);

        $page = $this->actingAs($this->admin())->get('/modules')->assertOk();
        $page->assertSeeInOrder(['Nav', 'GAM', 'Needed by GAM Runner, so it can&#039;t be uninstalled.', 'GAM Runner', 'Uninstall GAM Runner?', 'Also delete its data'], false)
            ->assertDontSee('Uninstall GAM?')
            ->assertDontSee('Uninstall Nav?')
            ->assertDontSee('confirm(', false);
        $this->assertStringNotContainsString('checked', $page->getContent());
    }

    public function test_uninstalling_queues_a_job_for_the_updater(): void
    {
        $admin = $this->admin();
        $this->install([
            ['name' => 'someone/widgets', 'pretty_version' => 'v2.1.0', 'extra' => ['tornado' => ['name' => 'Widgets', 'core' => '>=0.6']]],
            ['name' => 'district/homegrown', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Homegrown', 'core' => '>=0.6']]],
        ]);

        $this->actingAs($admin)->post('/modules/uninstall', ['package' => 'someone/widgets'])->assertSessionHas('modules_status', 'Uninstalling Widgets.');
        $job = UpdateJob::sole();
        $this->assertSame(['remove', 'someone/widgets', false, null], [$job->kind, $job->package, $job->delete_data, $job->repository]);

        // One at a time.
        $this->actingAs($admin)->from('/modules')->post('/modules/uninstall', ['package' => 'district/homegrown'])->assertSessionHasErrors('modules');
        $job->update(['status' => 'done']);

        $this->actingAs($admin)->post('/modules/uninstall', ['package' => 'district/homegrown', 'delete_data' => '1']);
        $this->assertSame(['district/homegrown', true], [UpdateJob::latest('id')->first()->package, UpdateJob::latest('id')->first()->delete_data]);

        // It's shown with the installs and updates.
        $this->actingAs($admin)->get('/modules')->assertSee('Uninstall district/homegrown and delete its data');
    }

    public function test_core_modules_and_modules_others_need_cant_be_uninstalled(): void
    {
        $admin = $this->admin();
        $this->install([
            ['name' => 'tornado-engine/gam', 'pretty_version' => 'v0.5.0', 'extra' => ['tornado' => ['name' => 'GAM', 'core' => '>=0.6']]],
            ['name' => 'tornado-engine/gam-runner', 'pretty_version' => 'v0.1.0', 'require' => ['tornado-engine/gam' => '>=0.5'], 'extra' => ['tornado' => ['name' => 'GAM Runner', 'core' => '>=0.6']]],
            ['name' => 'tornado-engine/nav', 'pretty_version' => 'v1.0.0', 'extra' => ['tornado' => ['name' => 'Nav', 'core' => '>=0.6', 'core-module' => true]]],
        ]);

        $this->actingAs($admin)->from('/modules')->post('/modules/uninstall', ['package' => 'tornado-engine/nav'])->assertSessionHasErrors(['modules' => 'Core modules can\'t be uninstalled.']);
        $this->actingAs($admin)->from('/modules')->post('/modules/uninstall', ['package' => 'tornado-engine/gam'])->assertSessionHasErrors(['modules' => 'It\'s needed by GAM Runner, so it can\'t be uninstalled.']);
        $this->actingAs($admin)->from('/modules')->post('/modules/uninstall', ['package' => 'someone/widgets'])->assertSessionHasErrors('modules');
        $this->actingAs(User::factory()->create())->post('/modules/uninstall', ['package' => 'tornado-engine/gam-runner'])->assertForbidden();
        $this->assertSame(0, UpdateJob::count());
    }

    public function test_modules_say_which_other_modules_they_need(): void
    {
        $this->actingAs($this->admin())->get('/modules')
            ->assertSee('Requirements: GAM Module')
            ->assertDontSee('Requirements: GAM Module (will auto install)')
            ->assertSee('Requirements: GAM Runner Module (will auto install)')
            ->assertDontSee('laravel/framework')
            ->assertDontSee('confirm(', false);
    }

    public function test_installing_queues_a_job_for_the_updater(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/modules/install', ['package' => 'someone/widgets'])->assertSessionHas('modules_status');
        $job = UpdateJob::sole();
        $this->assertSame(['module', 'someone/widgets', '2.1.0', null], [$job->kind, $job->package, $job->version, $job->repository]);

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
        Cache::forget('module-finder.found.v3');

        $this->actingAs($this->admin())->get('/modules')->assertOk()->assertSee('Couldn\'t reach GitHub')->assertSee('Homegrown');
    }

    public function test_only_people_allowed_to_manage_modules_can_open_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/modules')->assertForbidden();
        $this->actingAs(User::factory()->create())->post('/modules/install', ['package' => 'tornado-engine/gam'])->assertForbidden();
    }

    public function test_a_freshly_installed_module_that_needs_setup_opens_its_settings_once(): void
    {
        $admin = $this->admin();
        file_put_contents($this->installed, json_encode(['packages' => [
            ['name' => 'tornado-engine/gam', 'pretty_version' => 'v0.5.0', 'extra' => ['tornado' => ['name' => 'GAM', 'core' => '>=0.6', 'settings' => '/administration/gam', 'needs-setup' => true]]],
        ]]));
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->installed, '0.7.0'));

        // An update doesn't; someone else's install doesn't.
        UpdateJob::create(['kind' => 'module', 'package' => 'tornado-engine/gam', 'version' => '0.5.0', 'status' => 'done', 'user_id' => $admin->id]);
        UpdateJob::create(['kind' => 'module', 'package' => 'tornado-engine/gam', 'version' => '0.5.0', 'status' => 'done', 'install' => true, 'user_id' => $this->admin()->id]);
        $this->actingAs($admin)->get('/modules')->assertOk();

        $job = UpdateJob::create(['kind' => 'module', 'package' => 'tornado-engine/gam', 'version' => '0.5.0', 'status' => 'running', 'install' => true, 'user_id' => $admin->id]);
        $this->actingAs($admin)->get('/modules')->assertOk();

        $job->update(['status' => 'done']);
        $this->actingAs($admin)->get('/modules')->assertRedirect('/administration/gam');
        $this->actingAs($admin)->get('/modules')->assertOk();
    }

    public function test_core_modules_have_their_own_section_at_the_top(): void
    {
        file_put_contents($this->installed, json_encode(['packages' => [
            ['name' => 'tornado-engine/access', 'pretty_version' => 'v0.9.0', 'extra' => ['tornado' => ['name' => 'Access', 'core' => '>=0.6', 'core-module' => true]]],
            ['name' => 'tornado-engine/gam', 'pretty_version' => 'v0.9.0', 'extra' => ['tornado' => ['name' => 'GAM', 'core' => '>=0.6']]],
        ]]));
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->installed, '0.20.0'));
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($this->admin())->get('/modules')->assertSeeInOrder(['Core modules', 'Access', 'Official modules', 'GAM']);
    }

    public function test_updates_say_what_kind_they_are(): void
    {
        // GAM: its release notes say bug fix (0.4.0's critical is the installed version, so it doesn't count).
        $this->actingAs($this->admin())->get('/modules')->assertSeeInOrder(['Bug fix', '0.5.0 available'], false);
        $listings = app(\TornadoEngine\ModuleFinder\Finder::class)->listings();
        $this->assertSame('bug-fix', $listings['tornado-engine/gam']->updateType());
        $this->assertNull($listings['tornado-engine/gam-runner']->updateType());

        // With nothing said, the version numbers decide; the most urgent word wins.
        $listing = new \TornadoEngine\ModuleFinder\Listing('a/b', 'B', null, null, false, false, '2.0.0', null, installed: '1.4.2');
        $this->assertSame('major', $listing->updateType());
        $listing->latest = '1.5.0';
        $this->assertSame('minor', $listing->updateType());
        $listing->latest = '1.4.3';
        $this->assertSame('patch', $listing->updateType());
        $listing->releaseTypes = ['1.4.3' => 'optional', '1.4.2' => 'critical'];
        $this->assertSame('optional', $listing->updateType());
        $listing->latest = '1.5.0';
        $listing->releaseTypes = ['1.4.3' => 'optional', '1.5.0' => 'required'];
        $this->assertSame('required', $listing->updateType());
        $this->assertSame('bug-fix', \TornadoEngine\ModuleFinder\Listing::normalizeType('Bug fix'));
        $this->assertNull(\TornadoEngine\ModuleFinder\Listing::normalizeType('whatever'));
    }
}
