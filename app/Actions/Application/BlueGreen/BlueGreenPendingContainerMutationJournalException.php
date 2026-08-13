<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;

final class BlueGreenPendingContainerMutationJournalException extends BlueGreenManagedRouteUnobservableException
{
    /**
     * Whether a failure means a container-mutation journal is fencing the route.
     *
     * The condition reaches a caller two ways: this type, thrown by the readers
     * that recognise it, and a raw remote failure whose script exited on the
     * fence marker before any reader could type it. Both are the same fact, so
     * they are decided here once. Every lane that routes on this condition asks
     * this method — a lane that re-derived it from a message would silently stop
     * routing the moment an intermediate rethrow reworded that message, which is
     * exactly how the clean IDLE convergence lane went dark.
     */
    public static function fencesManagedRoute(?\Throwable $exception): bool
    {
        for ($candidate = $exception; $candidate !== null; $candidate = $candidate->getPrevious()) {
            if ($candidate instanceof self
                || str_contains(
                    $candidate->getMessage(),
                    WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                )) {
                return true;
            }
        }

        return false;
    }
}
