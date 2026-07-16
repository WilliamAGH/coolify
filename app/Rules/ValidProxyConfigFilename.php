<?php

namespace App\Rules;

use App\Support\ProxyDynamicConfigurationFilenamePolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidProxyConfigFilename implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates proxy configuration filename:
     * - Must be 1-255 characters
     * - No path separators (/, \) to prevent path traversal
     * - Cannot start with a dot (hidden files)
     * - Only alphanumeric characters, dashes, underscores, and dots allowed
     * - Must have a basename before any extension
     * - Cannot use reserved filenames
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $filename = trim($value);

        // Check length (filesystem limit is typically 255 bytes)
        if (strlen($filename) > 255) {
            $fail('The :attribute must not exceed 255 characters.');

            return;
        }

        // Check for path separators (prevent path traversal)
        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            $fail('The :attribute cannot contain path separators.');

            return;
        }

        // Check for hidden files (starting with dot)
        if (str_starts_with($filename, '.')) {
            $fail('The :attribute cannot start with a dot (hidden files not allowed).');

            return;
        }

        // Check for valid characters only: alphanumeric, dashes, underscores, dots
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            $fail('The :attribute may only contain letters, numbers, dashes, underscores, and dots.');

            return;
        }

        if (ProxyDynamicConfigurationFilenamePolicy::isReadOnly($filename)) {
            $fail('The :attribute uses a reserved filename.');

            return;
        }
    }
}
