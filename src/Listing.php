<?php

namespace TornadoEngine\ModuleFinder;

use Composer\Semver\Comparator;

/**
 * A module found on GitHub (or installed here), with what's available for this server.
 */
final class Listing
{
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
}
