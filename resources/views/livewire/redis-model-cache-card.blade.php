<x-pulse::card>
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-sm text-gray-900 dark:text-gray-100">Redis Model Cache</h2>
        <span class="text-xs text-gray-500">Last updated: {{ $updatedAt->toDateTimeString() }}</span>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-2">Model</th>
                    <th class="py-2">Cached</th>
                    <th class="py-2">Hit rate</th>
                    <th class="py-2">Avg query</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $value = is_string($row->value ?? null) ? json_decode($row->value, true) : (array) ($row->value ?? []);
                        $hitRate = (float) ($value['hit_rate'] ?? 0);
                    @endphp
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-2">{{ $value['model'] ?? '—' }}</td>
                        <td class="py-2">{{ $value['cached_count'] ?? '—' }}</td>
                        <td class="py-2 {{ $hitRate >= 95 ? 'text-green-600' : ($hitRate >= 80 ? 'text-amber-600' : 'text-red-600') }}">
                            {{ number_format($hitRate, 1) }}%
                        </td>
                        <td class="py-2">{{ number_format((float) ($value['avg_query_ms'] ?? 0), 2) }} ms</td>
                    </tr>
                @empty
                    <tr><td class="py-3 text-gray-500" colspan="4">No cache metrics recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-pulse::card>
