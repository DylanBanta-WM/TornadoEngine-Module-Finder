<?php

namespace TornadoEngine\ModuleFinder;

use App\Modules\ModuleRegistry;
use Composer\Semver\Semver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Finds modules: public GitHub repositories with the topic "tornado-engine-module" whose
 * composer.json has an extra.tornado section. Versions come from Packagist, or from the
 * repository's tags if the module isn't on Packagist. Results are kept for an hour.
 */
class Finder
{
    public const TOPIC = 'tornado-engine-module';

    public function __construct(private ModuleRegistry $modules) {}

    /**
     * Everything found, plus installed modules GitHub doesn't list, keyed by package name.
     *
     * @return array<string, Listing>
     */
    public function listings(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('module-finder.found.v2');
        }

        $found = Cache::get('module-finder.found.v2');
        if ($found === null) {
            $found = $this->search();
            Cache::put('module-finder.found.v2', $found, $found === false ? 300 : 3600);
        }

        $listings = [];
        foreach ($found ?: [] as $data) {
            if ($data['official'] || $data['onPackagist']) {
                $listings[$data['package']] = new Listing(...$data);
            }
        }

        foreach ($this->modules->all() as $module) {
            $listings[$module->package] ??= new Listing($module->package, $module->name, null, null, false, false, null, null);
            $listings[$module->package]->installed = ltrim($module->version, 'v');
            $listings[$module->package]->coreModule = $module->coreModule;
            $listings[$module->package]->missingExtensions = $module->missingExtensions ?? [];
            $listings[$module->package]->settings = $module->compatible ? ($module->settings ?? null) : null;
            $listings[$module->package]->local = $this->local($module->path ?? null);
        }

        // Installed modules that need another installed one.
        foreach ($this->modules->all() as $module) {
            foreach ($module->requires ?? [] as $package) {
                if (isset($listings[$package]) && $listings[$package]->installed !== null) {
                    $listings[$package]->requiredBy[] = $module->name;
                }
            }
        }

        // Only other modules count as what a module needs (not PHP or Laravel packages).
        foreach ($listings as $listing) {
            $listing->requires = array_values(array_filter($listing->requires, fn ($package) => isset($listings[$package])));
        }

        uasort($listings, fn (Listing $a, Listing $b) => strcasecmp($a->name, $b->name));

        return $listings;
    }

    /**
     * What a module needs, for the page: "GAM Module (will auto install), Broker Module". Core
     * modules aren't listed: they're always there.
     *
     * @param  array<string, Listing>  $all
     */
    public static function requirements(Listing $listing, array $all): string
    {
        $needs = [];
        foreach ($listing->requires as $package) {
            $need = $all[$package] ?? null;
            if ($need && ! $need->coreModule) {
                $needs[] = __(':name Module', ['name' => $need->name]).($need->installed === null ? ' '.__('(will auto install)') : '');
            }
        }

        return implode(', ', $needs);
    }

    private function local(?string $path): bool
    {
        $modules = realpath(base_path('modules'));

        return $path !== null && $modules !== false && str_starts_with($path, $modules.DIRECTORY_SEPARATOR);
    }

    /** Whether the last search reached GitHub. */
    public function reachable(): bool
    {
        return Cache::get('module-finder.found.v2') !== false;
    }

    /** @return list<array<string, mixed>>|false */
    private function search(): array|false
    {
        try {
            $repos = $this->http()->get('https://api.github.com/search/repositories', ['q' => 'topic:'.self::TOPIC, 'per_page' => 100])->throw()->json('items') ?? [];
        } catch (Throwable) {
            return false;
        }

        $found = [];
        foreach ($repos as $repo) {
            if ($repo['archived'] ?? false) {
                continue;
            }
            try {
                $listing = $this->describe($repo);
            } catch (Throwable) {
                continue; // Not a usable module (no composer.json, no tornado section, ...).
            }
            if ($listing) {
                $found[] = $listing;
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    private function describe(array $repo): ?array
    {
        $composer = $this->http()->get("https://raw.githubusercontent.com/{$repo['full_name']}/{$repo['default_branch']}/composer.json")->throw()->json();
        $tornado = $composer['extra']['tornado'] ?? null;
        $package = strtolower((string) ($composer['name'] ?? ''));

        if (! is_array($tornado) || ! str_contains($package, '/')) {
            return null;
        }

        // [version => ['core' => Core range it needs, 'requires' => packages it requires]]
        $versions = $this->packagist($package);
        $onPackagist = $versions !== null;
        $versions ??= $this->tags($repo['full_name'], (string) ($tornado['core'] ?? '*'), array_keys($composer['require'] ?? []));

        $core = config('tornado.version');
        $usable = array_keys(array_filter($versions, fn ($version) => $this->supports($version['core'], $core)));
        $all = array_keys($versions);
        $latest = $usable ? Semver::rsort($usable)[0] : null;
        $newest = $all ? Semver::rsort($all)[0] : null;

        return [
            'package' => $package,
            'name' => (string) ($tornado['name'] ?? $package),
            'description' => $repo['description'] ?? ($composer['description'] ?? null),
            'url' => $repo['html_url'],
            'official' => strcasecmp($repo['owner']['login'] ?? '', config('tornado.official_owner')) === 0,
            'onPackagist' => $onPackagist,
            'latest' => $latest,
            'needsNewerCore' => $newest !== $latest ? $newest : null,
            'requires' => $latest ? $versions[$latest]['requires'] : [],
            'suggested' => (bool) ($tornado['suggest'] ?? false),
        ];
    }

    /** @return array<string, array{core: string, requires: list<string>}>|null by version, or null if it isn't on Packagist */
    private function packagist(string $package): ?array
    {
        $response = $this->http()->get("https://repo.packagist.org/p2/{$package}.json");
        if ($response->status() === 404) {
            return null;
        }

        $versions = [];
        $previous = [];
        foreach ($response->throw()->json("packages.{$package}") ?? [] as $entry) {
            // Packagist's minified format: each entry only lists what changed since the last one.
            $entry = array_filter(array_merge($previous, $entry), fn ($value) => $value !== '__unset');
            $previous = $entry;

            $version = ltrim((string) ($entry['version'] ?? ''), 'v');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $versions[$version] = ['core' => (string) ($entry['extra']['tornado']['core'] ?? '*'), 'requires' => array_keys((array) ($entry['require'] ?? []))];
            }
        }

        return $versions;
    }

    /**
     * @param  list<string>  $requires
     * @return array<string, array{core: string, requires: list<string>}>
     */
    private function tags(string $repo, string $core, array $requires): array
    {
        $versions = [];
        foreach ($this->http()->get("https://api.github.com/repos/{$repo}/tags", ['per_page' => 100])->throw()->json('*.name') ?? [] as $tag) {
            $version = ltrim((string) $tag, 'v');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $versions[$version] = ['core' => $core, 'requires' => $requires];
            }
        }

        return $versions;
    }

    private function supports(string $range, string $core): bool
    {
        try {
            return Semver::satisfies($core, $range);
        } catch (Throwable) {
            return false;
        }
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Tornado-Engine'])->timeout(15);
    }
}
