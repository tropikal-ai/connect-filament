<x-filament-panels::page>
    @php($status = $this->status())

    <section class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-6 md:grid-cols-3">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Connection</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ str_replace('_', ' ', (string) $status['status']) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Website</p>
                <p class="mt-1 truncate text-lg font-semibold text-gray-950 dark:text-white">{{ $status['website']['url'] ?? 'Not connected' }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Resources</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $status['resources']['count'] ?? 0 }}</p>
            </div>
        </div>
    </section>

    @if (($status['embed']['snippet'] ?? null) && (($status['embed']['status'] ?? null) === 'enabled'))
        <section class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Embed</p>
            <code class="mt-3 block overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-white">{{ $status['embed']['snippet'] }}</code>
        </section>
    @endif
</x-filament-panels::page>
