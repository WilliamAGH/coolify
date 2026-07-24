<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Admission-guard rejection for blue-green configuration and settings
 * mutations (opt-in/opt-out, routing, lifecycle, and topology guards).
 *
 * Extends RuntimeException so existing generic catch sites keep working,
 * while boundaries that translate admission rejections into user-facing
 * validation errors (e.g. the API controller) can catch this exact type
 * without swallowing unrelated runtime failures.
 */
class BlueGreenAdmissionException extends RuntimeException {}
