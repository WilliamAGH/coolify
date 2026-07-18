@php
    $version = (string) config('constants.coolify.version');
    $releaseUrl = \App\Actions\Server\UpdateCoolify::isGuardedForkRelease($version)
        ? "https://github.com/WilliamAGH/coolify/releases/tag/{$version}"
        : "https://github.com/coollabsio/coolify/releases/tag/v{$version}";
@endphp

<a {{ $attributes->merge(['class' => 'text-xs cursor-pointer opacity-90 hover:opacity-100 dark:hover:text-white hover:text-black']) }}
    href="{{ $releaseUrl }}" target="_blank" rel="noreferrer">
    v{{ $version }}
</a>
