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

    <section class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="mb-4">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Business objects</p>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Capabilities</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-3 pr-4 font-medium">Object</th>
                        <th class="px-4 py-3 font-medium">Model</th>
                        <th class="px-4 py-3 font-medium">Readable</th>
                        <th class="px-4 py-3 font-medium">Writable</th>
                        <th class="px-4 py-3 font-medium">Read</th>
                        <th class="px-4 py-3 font-medium">Write</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->discoveredResources() as $slug => $resource)
                        @php($fields = $resource['fields'] ?? [])
                        @php($writable = collect($fields)->filter(fn ($field) => ($field['writable'] ?? true) !== false)->count())
                        <tr class="border-b border-gray-100 dark:border-white/10">
                            <td class="py-3 pr-4 font-medium text-gray-950 dark:text-white">{{ $resource['label'] ?? $slug }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $resource['model'] ?? '' }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ count($fields) }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $writable }}</td>
                            <td class="px-4 py-3">
                                <input type="checkbox" @checked($capabilityGrants[$slug]['read'] ?? false) wire:change="setCapabilityGrant('{{ $slug }}', 'read', $event.target.checked)" />
                            </td>
                            <td class="px-4 py-3">
                                <input type="checkbox" @checked($capabilityGrants[$slug]['write'] ?? false) wire:change="setCapabilityGrant('{{ $slug }}', 'write', $event.target.checked)" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-4 text-gray-500 dark:text-gray-400">No discoverable business objects.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
