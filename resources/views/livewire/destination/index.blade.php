<div>
    <x-slot:title>
        Destinations | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Destinations</h1>
        @if ($servers->count() > 0)
            @can('createAnyResource')
                <x-modal-input buttonTitle="+ Add" title="New Destination">
                    <livewire:destination.new.docker />
                </x-modal-input>
            @endcan
        @endif
    </div>
    <div class="subtitle">Network endpoints to deploy your resources.</div>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($destinations as $destination)
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <a class="coolbox group" {{ wireNavigate() }}
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">{{ $destination->name }}</div>
                        <div class="box-description">Server: {{ $destination->server->name }}</div>
                    </div>
                </a>
            @endif
            @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                <a class="coolbox group" {{ wireNavigate() }}
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="flex flex-col mx-6">
                        <div class="box-title">
                            {{ $destination->name }}
                            <x-deprecated-badge />
                        </div>
                        <div class="box-description">Server: {{ $destination->server->name }}</div>
                    </div>
                </a>
            @endif
        @empty
            @if ($servers->isEmpty())
                <div class="max-w-xl text-neutral-600 dark:text-neutral-400 lg:col-span-2">
                    <div class="font-medium text-black dark:text-white">No destinations are available.</div>
                    <p class="mt-1">An eligible deployment server is required before you can create a destination.</p>
                    <a class="inline-flex items-center gap-1 mt-2 rounded-sm text-coollabs hover:underline focus-visible:ring-2 focus-visible:ring-coollabs dark:text-warning dark:focus-visible:ring-warning"
                        href="{{ route('server.index') }}" {{ wireNavigate() }}>
                        Review servers
                    </a>
                </div>
            @else
                <div>No destinations found.</div>
            @endif
        @endforelse
    </div>
</div>
