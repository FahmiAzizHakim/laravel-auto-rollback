<?php

namespace FahmiAzizHakim\LaravelAutoRollback;

enum QueryLog: string
{
    /** Log the requests whose writes were lost: rolled back, or a failed commit. */
    case Rollback = 'rollback';

    /** Log every request, committed or not -- even under AUTO_ROLLBACK=OFF. */
    case Always = 'always';

    /** Log nothing. */
    case Never = 'never';

    /**
     * Read RBQUERY_LOG. Case does not matter, true/false (AUTO_ROLLBACK_LOG
     * from v1.1) mean ROLLBACK and NEVER, and anything unrecognised is ROLLBACK.
     */
    public static function fromConfig(mixed $value): self
    {
        if (is_bool($value)) {
            return $value ? self::Rollback : self::Never;
        }

        return match (strtolower(trim((string) $value))) {
            'always', 'alway' => self::Always,
            'never', 'false', '0', 'off', 'no' => self::Never,
            default => self::Rollback,
        };
    }
}
