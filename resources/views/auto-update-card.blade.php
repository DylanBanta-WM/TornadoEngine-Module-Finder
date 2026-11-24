{{-- Settings → Updates: whether updates marked Required install by themselves. --}}
@php($on = \TornadoEngine\ModuleFinder\AutoUpdate::enabled())
<x-settings-card :title="__('Required updates')">
    @if (session('auto_update_status'))
        <p class="rounded-md bg-green-50 dark:bg-green-900/30 px-4 py-3 text-green-800 dark:text-green-300">{{ session('auto_update_status') }}</p>
    @endif
    <p>{{ $on ? __('Updates marked Required install by themselves for official modules.') : __('Updates marked Required don\'t install by themselves.') }}</p>
    <p class="text-gray-600 dark:text-gray-400">{{ __('Required updates are always relevant security fixes, so we recommend leaving this on. Some districts turn it off anyway, to update on their own schedule. Other districts\' modules are never updated by themselves.') }}</p>
    @can('modules.manage')
        <form method="POST" action="{{ route('modules.auto-update') }}" class="flex justify-end">
            @csrf
            <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
            <x-secondary-button type="submit">{{ $on ? __('Turn off') : __('Turn on') }}</x-secondary-button>
        </form>
    @endcan
</x-settings-card>
