@php
    $version = config('constants.coolify.version');
    $isForkRelease = \App\Actions\Server\UpdateCoolify::isGuardedForkRelease($version);
    $releaseUrl = $isForkRelease
        ? config('constants.coolify.fork_releases_url').'/tag/'.$version
        : 'https://github.com/coollabsio/coolify/releases/tag/v'.$version;
@endphp
<a {{ $attributes->merge(['class' => 'text-xs cursor-pointer opacity-90 hover:opacity-100 dark:hover:text-white hover:text-black']) }}
    href="{{ $releaseUrl }}" target="_blank">
    v{{ $version }}
</a>
