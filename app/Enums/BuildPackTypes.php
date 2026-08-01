<?php

namespace App\Enums;

enum BuildPackTypes: string
{
    case NIXPACKS = 'nixpacks';
    case STATIC = 'static';
    case DOCKERFILE = 'dockerfile';
    case DOCKERCOMPOSE = 'dockercompose';
    case RAILPACK = 'railpack';
    case DOCKERIMAGE = 'dockerimage';

    /**
     * A Docker Image application runs the registry reference it is given and never
     * builds from a source checkout, so it is unresolvable without one.
     *
     * Note the converse does not hold: a source-building pack may also carry a
     * docker_registry_image_name, where it names the push destination for the
     * image that pack builds.
     */
    public function requiresImageRepository(): bool
    {
        return $this === self::DOCKERIMAGE;
    }

    /** Build packs that build from a source checkout rather than pulling a registry reference. */
    public function buildsFromSource(): bool
    {
        return ! $this->requiresImageRepository();
    }
}
