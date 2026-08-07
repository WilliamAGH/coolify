<?php

namespace App\Actions\Application\BlueGreen;

use RuntimeException;

/**
 * The managed route's durable state is in a shape the read scope cannot
 * observe without mutating: a pending mutation journal or a missing managed
 * lock. The owning write and recovery lanes resolve the shape; read-path
 * consumers translate it to "state not observable" instead of a server fault.
 */
abstract class BlueGreenManagedRouteUnobservableException extends RuntimeException {}
