<section id="travel-history" class="section-card mt-6 scroll-mt-24">
    <h2 class="text-lg">Travel History</h2>
    <p class="mt-1 text-sm text-slate-500">Completed trips for this vehicle, taken directly from the trip records.</p>
    <div class="table-wrap mt-4"><table><thead><tr><th>Date</th><th>Route</th><th>Driver</th><th>Purpose</th><th class="text-right">Odo before</th><th class="text-right">Odo after</th><th class="text-right">Distance</th><th>Fuel</th></tr></thead><tbody>
    @forelse ($tripHistory as $trip)
        <tr>
            <td><a class="font-semibold text-blue-800" href="{{ route('transport.show', $trip) }}">{{ ($trip->actual_departed_at ?? $trip->travel_date)->format('M j, Y') }}</a></td>
            <td>{{ $trip->origin }} → {{ $trip->destination }}</td>
            <td>{{ $trip->driver?->user?->name ?? '—' }}</td>
            <td>{{ \Illuminate\Support\Str::limit($trip->purpose, 50) }}</td>
            <td class="text-right">{{ $trip->odometer_before !== null ? number_format($trip->odometer_before) : '—' }}</td>
            <td class="text-right">{{ $trip->odometer_after !== null ? number_format($trip->odometer_after) : '—' }}</td>
            <td class="text-right">{{ $trip->distance_km !== null ? number_format($trip->distance_km).' km' : '—' }}</td>
            <td>{{ $trip->fuel_before?->label() }} → {{ $trip->fuel_after?->label() }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="py-6 text-center text-sm text-slate-500">No completed trips yet.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="mt-4">{{ $tripHistory->links() }}</div>
</section>
