<?php

namespace FahmiAzizHakim\LaravelAutoRollback;

enum Mode: string
{
    /** Roll back every request and log its queries. */
    case On = 'on';

    /** Leave requests alone. */
    case Off = 'off';

    /** Commit successful requests; roll back and log the ones that failed. */
    case OnError = 'on_error';

    /**
     * Read AUTO_ROLLBACK. true/false from v1.0 still work, case does not
     * matter, and anything unrecognised is Off.
     */
    public static function fromConfig(mixed $value): self
    {
        if (is_bool($value)) {
            return $value ? self::On : self::Off;
        }

        return match (strtolower(trim((string) $value))) {
            'on', 'true', '1', 'yes' => self::On,
            'on_error', 'on-error', 'onerror' => self::OnError,
            default => self::Off,
        };
    }
}
