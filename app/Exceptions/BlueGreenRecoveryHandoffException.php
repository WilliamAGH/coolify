<?php

namespace App\Exceptions;

/**
 * Raised when a deployment cannot start because automatic recovery just handed
 * this destination to a fenced owner that is still finishing the previous
 * operation.
 *
 * It is deliberately not an ordinary deployment failure. Nothing is wrong with
 * the release being pushed: the destination is busy for a bounded moment, and
 * the correct outcome is for this exact deployment to wait and then run, not for
 * the operator to notice a red deployment and push the same commit again. The
 * job handler converts it into a return to the queue, where the existing
 * dispatch-claim gate defers it until the destination is cleanly claimable.
 */
final class BlueGreenRecoveryHandoffException extends DeploymentException {}
