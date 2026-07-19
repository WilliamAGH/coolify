<?php

namespace App\Actions\Proxy\ControlPlane;

use Lorisleiva\Actions\Concerns\AsAction;

final class ControlPlaneGenerationWriterAuthority
{
    use AsAction;

    public function predecessor(ControlPlaneGenerationPromotionState $state): ManagedTraefikDocumentWriterAuthority
    {
        $identity = $state->runtime->predecessorWriterIdentity();

        return new ManagedTraefikDocumentWriterAuthority(
            epoch: $state->writerEpoch - 1,
            operationId: $state->predecessor['operation_id'],
            member: $state->predecessor['member'],
            containerId: $identity['container_id'],
            containerName: $identity['name'],
            imageId: $identity['image_id'],
            dynamicRevision: $state->predecessor['dynamic_revision'],
            dynamicSha256: $state->predecessor['dynamic_sha256'],
        );
    }

    public function successor(ControlPlaneGenerationPromotionState $state): ManagedTraefikDocumentWriterAuthority
    {
        $identity = $state->runtime->writerIdentity();

        return new ManagedTraefikDocumentWriterAuthority(
            epoch: $state->writerEpoch,
            operationId: $state->operationId,
            member: $state->writerMember,
            containerId: $identity['container_id'],
            containerName: $identity['name'],
            imageId: $identity['image_id'],
            dynamicRevision: $state->successor['dynamic_revision'],
            dynamicSha256: $state->successor['dynamic_sha256'],
        );
    }
}
