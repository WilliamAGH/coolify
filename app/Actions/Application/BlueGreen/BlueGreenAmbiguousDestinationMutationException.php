<?php

namespace App\Actions\Application\BlueGreen;

/**
 * A destination mutation failed without proving whether its commands ran, so
 * the caller must re-observe instead of guessing. Unlike other transition
 * failures this one earns a bounded retry budget: the next attempt re-inspects
 * the container and either finds the mutation applied, replays its journal, or
 * retries the mutation under the same fence.
 */
final class BlueGreenAmbiguousDestinationMutationException extends BlueGreenDeploymentTransitionException {}
