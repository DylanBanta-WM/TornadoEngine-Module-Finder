<?php

namespace TornadoEngine\ModuleFinder;

use Composer\Semver\Comparator;

/**
 * A module found on GitHub (or installed here), with what's available for this server.
 */
final class Listing
{
    /**
     * The kinds of update, most urgent first: label, and the colors of its chip. A release can say
     * which it is ("Type: critical" in its GitHub release notes, or "update-type" in extra.tornado
     * of its composer.json); otherwise the change in version number decides (patch, minor, major).
     * The class names are written out whole so Tailwind finds them.
     */
    public const TYPES = [
        'critical' => ['Critical', 'bg-red-100 dark:bg-red-900/50 text-red-800 dark:text-red-200'],
        'required' => ['Required', 'bg-orange-100 dark:bg-orange-900/50 text-orange-800 dark:text-orange-200'],
        'major' => ['Major', 'bg-purple-100 dark:bg-purple-900/50 text-purple-800 dark:text-purple-200'],
        'minor' => ['Minor', 'bg-indigo-100 dark:bg-indigo-900/50 text-indigo-800 dark:text-indigo-200'],
        'bug-fix' => ['Bug fix', 'bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200'],
        'patch' => ['Patch', 'bg-teal-100 dark:bg-teal-900/50 text-teal-800 dark:text-teal-200'],
        'optional' => ['Optional', 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'],
    ];

    /** "Bug fix", "bugfix" and "bug-fix" are the same; null for anything that isn't a kind of update. */
    public static function normalizeType(mixed $type): ?string
    {
        $type = preg_replace('/[\s_-]+/', '-', strtolower(trim((string) $type)));
        $type = $type === 'bugfix' ? 'bug-fix' : $type;

        return isset(self::TYPES[$type]) ? $type : null;
    }

    public function __construct(
        public string $package,
        public string $name,
        public ?string $description,
        public ?string $url,
        public bool $official,
        public bool $onPackagist,
        /** The newest version that works with this server's Core version. */
        public ?string $latest,
        /** The newest version of all, if it needs a newer Core. */
        public ?string $needsNewerCore,
        public ?string $installed = null,
        public bool $coreModule = false,
        /** @var list<string> PHP extensions it's waiting for (installed modules only). */
        public array $missingExtensions = [],
        /** Its settings page, when it's installed and has one. */
        public ?string $settings = null,
        /** @var list<string> Other modules (packages) it needs. */
        public array $requires = [],
        /** @var list<string> Names of installed modules that need it. */
        public array $requiredBy = [],
        /** It's installed from a folder in web/modules. */
        public bool $local = false,
        /** First-time setup offers it ("suggest": true in extra.tornado; official modules only). */
        public bool $suggested = false,
        /** @var array<string, string> The kind of update a release says it is, by version. */
        public array $releaseTypes = [],
    ) {}

    public function vendor(): string
    {
        return strtok($this->package, '/');
    }

    /** Core modules, and modules others need, stay. */
    public function uninstallable(): bool
    {
        return $this->installed !== null && ! $this->coreModule && ! $this->requiredBy;
    }

    /**
     * Where /modules lists it: "official", "district" (this district's vendor, or a folder in
     * web/modules GitHub doesn't list) or "other".
     */
    public function section(?string $districtVendor): string
    {
        return match (true) {
            $this->coreModule => 'core',
            // Official means in the official GitHub account; a local folder named tornado-engine/… isn't yet.
            $this->official => 'official',
            ($districtVendor && $this->vendor() === $districtVendor) || (! $this->url && $this->local) => 'district',
            default => 'other',
        };
    }

    public function updatable(): bool
    {
        // Installs from a local folder or a branch (for example while developing) aren't updated from here.
        return $this->installed !== null && $this->latest !== null
            && preg_match('/^\d+\.\d+\.\d+$/', $this->installed)
            && Comparator::greaterThan($this->latest, $this->installed);
    }

    /**
     * The kind of update waiting: the most urgent one any release since the installed version says it
     * is, or else what the version numbers say (patch, minor or major). Null when there's no update.
     */
    public function updateType(): ?string
    {
        if (! $this->updatable()) {
            return null;
        }

        $said = [];
        foreach ($this->releaseTypes as $version => $type) {
            if (Comparator::greaterThan((string) $version, $this->installed) && Comparator::lessThanOrEqualTo((string) $version, $this->latest)) {
                $said[] = $type;
            }
        }
        foreach (array_keys(self::TYPES) as $type) {
            if (in_array($type, $said, true)) {
                return $type;
            }
        }

        [$from, $to] = [array_map('intval', explode('.', $this->installed)), array_map('intval', explode('.', $this->latest))];

        return match (true) {
            $to[0] !== $from[0] => 'major',
            $to[1] !== $from[1] => 'minor',
            default => 'patch',
        };
    }
}
