<?php

namespace App\Actions\Application\BlueGreen;

final readonly class ActiveApplicationContainerState
{
    /**
     * @param  non-empty-list<array{
     *     destination_id: int,
     *     deployment_uuid: string,
     *     color: string|null,
     *     routing_revision: int|null,
     *     container_ids: non-empty-list<string>
     * }>  $destination
     */
    public function __construct(
        public string $image,
        public string $imageReference,
        public string $status,
        public array $destination,
    ) {}
}
