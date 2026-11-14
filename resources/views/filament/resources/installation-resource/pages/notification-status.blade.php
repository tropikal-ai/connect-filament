@if ($notifications['enabled'])
    <section class="fi-section rounded-xl bg-gray-50 p-6 dark:bg-gray-900" aria-label="Notifications">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Notifications</h2>
        @if (! $notifications['available'])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Update required</p>
        @elseif ($notifications['deliveries'] === [])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No deliveries yet</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Event</th>
                            <th scope="col" class="px-4 py-2 font-medium">Status</th>
                            <th scope="col" class="px-4 py-2 font-medium">Channel</th>
                            <th scope="col" class="px-4 py-2 font-medium">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-950 dark:text-white">
                        @foreach ($notifications['deliveries'] as $delivery)
                            <tr>
                                <td class="py-2 pr-4">{{ $delivery['event_id'] }}</td>
                                <td class="px-4 py-2">{{ ucfirst(str_replace('_', ' ', $delivery['status'])) }}</td>
                                <td class="px-4 py-2">{{ ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email'][$delivery['channel']] ?? 'Pending' }}</td>
                                <td class="whitespace-nowrap px-4 py-2"><time datetime="{{ $delivery['updated_at'] }}">{{ $delivery['updated_at'] }}</time></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
