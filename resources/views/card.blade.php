<li class="px-4 py-3 space-y-2">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $listing->name }}</p>
            <p class="break-all text-xs text-gray-500 dark:text-gray-400">{{ $listing->package }}</p>
        </div>
        <p class="flex flex-wrap gap-1">
            @if ($listing->official)
                <span class="rounded bg-indigo-50 dark:bg-indigo-900/50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('Official') }}</span>
            @elseif ($listing->url)
                <span class="rounded bg-amber-50 dark:bg-amber-900/40 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-300">{{ __('Community') }}</span>
            @endif
            @if ($listing->coreModule)
                <span class="rounded bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-700 dark:text-gray-300">{{ __('Core module') }}</span>
            @endif
        </p>
    </div>

    @if ($listing->missingExtensions)
        <p class="rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-3 py-2 text-amber-900 dark:text-amber-200">
            {{ __('Off until the server is rebuilt with :extensions.', ['extensions' => implode(', ', $listing->missingExtensions)]) }}
        </p>
    @endif

    @if ($needs = \TornadoEngine\ModuleFinder\Finder::requirements($listing, $all))
        <p class="text-gray-600 dark:text-gray-400">{{ __('Requirements: :modules', ['modules' => $needs]) }}</p>
    @endif

    @if ($listing->description)
        <p class="text-gray-600 dark:text-gray-400">{{ $listing->description }}</p>
    @endif

    <p class="text-gray-600 dark:text-gray-400">
        @if ($listing->installed !== null)
            {{ __('Installed: :version.', ['version' => $listing->installed]) }}
            @if ($listing->updatable())
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ __(':version is available.', ['version' => $listing->latest]) }}</span>
            @elseif ($listing->url)
                {{ __('Up to date.') }}
            @endif
        @elseif ($listing->latest)
            {{ __('Version :version.', ['version' => $listing->latest]) }}
        @endif
        @if ($listing->needsNewerCore)
            {{ __(':version needs a newer Tornado Engine Core.', ['version' => $listing->needsNewerCore]) }}
        @endif
        @if (! $listing->url)
            {{ __('Not listed on GitHub, so it can\'t be updated from here.') }}
        @endif
    </p>

    <div class="flex flex-wrap items-center justify-between gap-3">
        @if ($listing->url)
            <x-secondary-link :href="$listing->url" target="_blank" rel="noopener">{{ __('View the code') }}</x-secondary-link>
        @endif
        <div class="ms-auto flex flex-wrap gap-3">
        @if ($listing->settings)
            <x-secondary-link :href="url($listing->settings)">{{ __('Settings') }}</x-secondary-link>
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
