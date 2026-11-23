<?php

namespace TornadoEngine\ModuleFinder;

use App\Models\Setting;
use App\Updates\UpdateJob;

/**
 * Installs updates marked "required" by themselves, for official modules only (never another
 * district's). One at a time: the updater runs one job at a time, and the next one is queued on
 * the next run. An update that failed isn't tried again until there's a newer version.
 */
class AutoUpdate
{
    public const SETTING = 'modules.auto_update';

    public function __construct(private Finder $finder) {}

    /** On unless an admin turned it off. */
    public static function enabled(): bool
    {
        return Setting::get(self::SETTING, '1') === '1';
    }

    public static function enable(bool $on): void
    {
        Setting::put(self::SETTING, $on ? '1' : '0');
    }

    /** Queues the next required update, and returns what it queued (or null). */
    public function run(): ?UpdateJob
    {
        if (! self::enabled() || UpdateJob::busy()) {
            return null;
        }

        foreach ($this->finder->listings() as $listing) {
            if (! $this->due($listing)) {
                continue;
            }

            return UpdateJob::queue('module', $listing->latest, $listing->package, $listing->onPackagist ? null : $listing->url);
        }

        return null;
    }

    private function due(Listing $listing): bool
    {
        return $listing->official
            && $listing->url
            && $listing->updateType() === 'required'
            && ! UpdateJob::where('kind', 'module')->where('package', $listing->package)->where('version', $listing->latest)->where('status', 'failed')->exists();
    }
}
