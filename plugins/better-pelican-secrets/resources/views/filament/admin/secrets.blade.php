<x-filament-panels::page wire:poll.5s>
    <label for="bps-server">Server</label>
    <x-filament::input.wrapper>
        <x-filament::input.select id="bps-server" wire:model.live="selectedServer">
            <option value="">All authorized servers</option>
            @foreach ($servers as $item)
                <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
    @if ($server)
        @include('better-pelican-secrets::filament.shared.management')
    @else
        <x-filament::section heading="Secrets across servers">
            <p>Select a server to manage its secrets, bindings, and access.</p>
            @forelse ($rows as $row)
                <div style="padding:1rem 0;border-bottom:1px solid #64748b40">
                    <button type="button" wire:click="$set('selectedServer', {{ $row['server_id'] }})" style="text-decoration:underline">
                        {{ $row['server_name'] }} / {{ $row['name'] }}
                    </button>
                    <p>Version {{ $row['version'] }} · {{ $row['binding_count'] }} bindings · {{ $row['updated_at'] }}</p>
                    <p>Desired {{ $row['status']->desiredRevision }} · Applied {{ $row['status']->appliedRevision ?? 'Never applied' }} · {{ $row['status']->observationStale ? 'Source observation unavailable' : ($row['status']->pending ? 'Pending next start' : ($row['status']->appliedRevision === null ? 'No runtime bindings' : 'Applied')) }}</p>
                </div>
            @empty
                <p>No secret metadata is visible with your current permissions.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
