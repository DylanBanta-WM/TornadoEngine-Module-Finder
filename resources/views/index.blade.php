@php
    $sections = [
        ['core', __('Core modules'), $core, null, false],
        ['official', __('Official modules'), $official, null, true],
        ['district', __('Your district\'s modules'), $district, null, true],
        ['other', __('Other districts\' modules'), $other, __('These aren\'t reviewed, and a module can read and change anything the website can.'), true],
    ];
@endphp

<x-admin-layout :title="__('Modules')">
    @if (session('modules_status'))
        <p class="rounded-md bg-green-50 dark:bg-green-900/30 px-4 py-3 text-sm text-green-800 dark:text-green-300">{{ session('modules_status') }}</p>
    @endif
    @if ($errors->has('modules'))
        <p class="rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-300">{{ $errors->first('modules') }}</p>
    @endif

    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg px-4 py-3 sm:px-6" x-data="{ jobs: false }">
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <button type="button" x-on:click="jobs = ! jobs" class="inline-flex min-h-11 items-center gap-2 font-medium text-gray-900 dark:text-gray-100" :aria-expanded="jobs">
                <svg class="size-4 transition-transform" :class="jobs && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('Installs and updates') }}
            </button>
            @if ($busy)
                <span class="rounded bg-indigo-50 dark:bg-indigo-900/50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('Working…') }}</span>
            @endif
            @unless ($reachable)
                <span class="text-gray-600 dark:text-gray-400">{{ __('Couldn\'t reach GitHub, so only installed modules are shown.') }}</span>
            @endunless
            <form method="POST" action="{{ route('modules.check') }}" class="ms-auto">
                @csrf
                <x-secondary-button type="submit">{{ __('Check now') }}</x-secondary-button>
            </form>
        </div>
        <div x-show="jobs" x-cloak class="mt-3 text-sm">
            <x-update-jobs :jobs="$jobs" />
        </div>
    </section>

    @foreach ($sections as [$section, $title, $listings, $warning, $openByDefault])
        @continue($listings->isEmpty())
        @php($updates = $listings->filter->updatable()->count())
        <section x-data="{ open: (() => { try { const saved = localStorage.getItem(@js('modules.'.$section)); return saved === null ? @js($openByDefault) : saved === '1' } catch (e) { return @js($openByDefault) } })() }"
                 x-init="$watch('open', value => { try { localStorage.setItem(@js('modules.'.$section), value ? '1' : '0') } catch (e) {} })">
            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                    class="mb-4 flex min-h-11 w-full items-center gap-2 px-4 text-start text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 sm:px-0">
                <svg class="size-4 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ $title }}
                <span class="font-normal normal-case tracking-normal">{{ $listings->count() }}</span>
                @if ($updates)
                    <span class="rounded bg-indigo-50 dark:bg-indigo-900/50 px-2 py-0.5 text-xs font-medium normal-case tracking-normal text-indigo-700 dark:text-indigo-300">{{ trans_choice(':count update|:count updates', $updates, ['count' => $updates]) }}</span>
                @endif
            </button>
            <div x-show="open" @unless ($openByDefault) x-cloak @endunless class="space-y-4">
                @if ($warning)
                    <p class="rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">{{ $warning }}</p>
                @endif
                <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 lg:gap-6">
                    @foreach ($listings as $listing)
                        @include('module-finder::card', ['listing' => $listing, 'busy' => $busy, 'all' => $all, 'section' => $section])
                    @endforeach
                </ul>
            </div>
        </section>
    @endforeach
</x-admin-layout>
