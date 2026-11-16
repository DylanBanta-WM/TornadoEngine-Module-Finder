<x-admin-layout :title="__('Modules')">
    @if (session('modules_status'))
        <p class="rounded-md bg-green-50 dark:bg-green-900/30 px-4 py-3 text-sm text-green-800 dark:text-green-300">{{ session('modules_status') }}</p>
    @endif
    @if ($errors->has('modules'))
        <p class="rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-300">{{ $errors->first('modules') }}</p>
    @endif

    <x-settings-card :title="__('Installs and updates')">
        <div class="flex flex-wrap items-center justify-end gap-3">
            @unless ($reachable)
                <p class="text-gray-600 dark:text-gray-400">{{ __('Couldn\'t reach GitHub, so only installed modules are shown.') }}</p>
            @endunless
            <form method="POST" action="{{ route('modules.check') }}">
                @csrf
                <x-secondary-button type="submit">{{ __('Check now') }}</x-secondary-button>
            </form>
        </div>
        <x-update-jobs :jobs="$jobs" />
    </x-settings-card>

    @foreach ([
        [__('Installed'), $installed, null],
        [__('Official modules'), $official, null],
        [__('Community modules'), $community, __('These modules aren\'t made by Tornado Engine. Install them at your own risk: a module runs with full access to your website and its data. Always read a module\'s code before you install and trust it.')],
    ] as [$title, $listings, $warning])
        @if ($listings->isNotEmpty())
            <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <h3 class="px-4 pt-4 font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
                @if ($warning)
                    <p class="mx-4 mt-3 rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">{{ $warning }}</p>
                @endif
                <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    @foreach ($listings as $listing)
                        @include('module-finder::card', ['listing' => $listing, 'busy' => $busy, 'all' => $all])
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</x-admin-layout>
