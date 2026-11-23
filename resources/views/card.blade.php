@php
    $chip = 'rounded px-2 py-0.5 text-xs font-medium';
    $note = 'text-gray-600 dark:text-gray-400';
@endphp

<li class="flex min-w-0 flex-col gap-3 bg-white dark:bg-gray-800 p-5 text-sm shadow-sm sm:rounded-lg">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h4 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $listing->name }}</h4>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $listing->package }}">{{ $listing->package }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap justify-end gap-1">
            @if ($listing->updatable())
                @php($type = \TornadoEngine\ModuleFinder\Listing::TYPES[$listing->updateType()])
                <span class="{{ $chip }} {{ $type[1] }}">{{ __($type[0]) }}</span>
                <span class="{{ $chip }} bg-indigo-50 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300">{{ __(':version available', ['version' => $listing->latest]) }}</span>
            @elseif ($listing->installed !== null)
                <span class="{{ $chip }} bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300">{{ preg_match('/^\d/', $listing->installed) ? 'v'.$listing->installed : $listing->installed }}</span>
            @elseif ($listing->latest)
                <span class="{{ $chip }} bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">{{ 'v'.$listing->latest }}</span>
            @endif
            @if ($listing->url && ($section ?? 'other') === 'other')
                <span class="{{ $chip }} bg-amber-50 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300">{{ __('Community') }}</span>
            @endif
            @if (! $listing->url)
                <span class="{{ $chip }} bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300" title="{{ __('Not listed on GitHub, so it can\'t be updated from here.') }}">{{ __('Local') }}</span>
            @endif
        </div>
    </div>

    @if ($listing->description)
        <p class="{{ $note }}">{{ \Illuminate\Support\Str::ucfirst(preg_replace('/^Tornado Engine (core[- ])?module:\s*/i', '', $listing->description)) }}</p>
    @endif

    @if ($listing->missingExtensions)
        <p class="rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-3 py-2 text-amber-900 dark:text-amber-200">
            {{ __('Off until the server is rebuilt with :extensions.', ['extensions' => implode(', ', $listing->missingExtensions)]) }}
        </p>
    @endif
    @if ($needs = \TornadoEngine\ModuleFinder\Finder::requirements($listing, $all))
        <p class="{{ $note }}">{{ __('Requirements: :modules', ['modules' => $needs]) }}</p>
    @endif
    @if ($listing->installed !== null && ! $listing->coreModule && $listing->requiredBy)
        <p class="{{ $note }}">{{ __('Needed by :modules, so it can\'t be uninstalled.', ['modules' => implode(', ', $listing->requiredBy)]) }}</p>
    @endif
    @if ($listing->needsNewerCore)
        <p class="{{ $note }}">{{ __(':version needs a newer Tornado Engine Core.', ['version' => $listing->needsNewerCore]) }}</p>
    @endif

    <div class="mt-auto flex flex-wrap items-center gap-2 pt-1">
        @if ($listing->url)
            <a href="{{ $listing->url }}" target="_blank" rel="noopener" class="inline-flex size-11 items-center justify-center rounded-md border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700" title="{{ __('View the code') }}" aria-label="{{ __('View the code') }}">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/></svg>
            </a>
        @endif
        <div class="ms-auto flex flex-wrap justify-end gap-2">
            @if ($listing->settings)
                <x-secondary-link :href="url($listing->settings)">{{ __('Settings') }}</x-secondary-link>
            @endif
            @if ($listing->uninstallable())
                <x-confirm :action="route('modules.uninstall')" :message="__('Uninstall :name?', ['name' => $listing->name])" :confirm="__('Uninstall')" danger>
                    <x-slot name="trigger"><x-danger-button type="button" :disabled="$busy">{{ __('Uninstall') }}</x-danger-button></x-slot>
                    <input type="hidden" name="package" value="{{ $listing->package }}">
                    <label class="flex min-h-11 items-center gap-2 text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="delete_data" value="1" class="rounded text-indigo-600 border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:ring-indigo-500">
                        {{ __('Also delete its data') }}
                    </label>
                </x-confirm>
            @endif
            @if (($listing->installed === null && $listing->latest) || $listing->updatable())
                <form method="POST" action="{{ route('modules.install') }}">
                    @csrf
                    <input type="hidden" name="package" value="{{ $listing->package }}">
                    <x-primary-button :disabled="$busy">{{ $listing->installed ? __('Update to :version', ['version' => $listing->latest]) : __('Install') }}</x-primary-button>
                </form>
            @endif
        </div>
        @foreach (app(\TornadoEngine\ModuleFinder\ModuleActions::class)->views() as $action)
            @include($action, ['listing' => $listing])
        @endforeach
    </div>
</li>
