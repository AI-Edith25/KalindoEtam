<?php

namespace App\Services\Import\Exceptions;

use RuntimeException;

/** Thrown when a commit is refused (e.g. all-or-nothing requested but the last preview had failures). Controller maps this to a 422. */
class ImportCommitBlockedException extends RuntimeException {}
