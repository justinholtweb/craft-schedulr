<?php

/**
 * Bootstrap for the unit suite.
 *
 * Deliberately does not boot Craft. Everything under `tests/unit` is chosen to be testable without an
 * application — the encryptor, the recurrence arithmetic, the edition boundary — because a unit suite
 * that needs a database is a slower integration suite with fewer guarantees.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
