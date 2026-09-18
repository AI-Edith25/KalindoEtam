<?php

namespace App\Services\Import;

/**
 * Internal control-flow signal only — thrown from inside a StreamedRowImport
 * callback to abort a chunked read early (e.g. once a small header peek has
 * enough rows) and always caught right around the same Excel::import() call
 * that could throw it. Never escapes to a controller/job.
 */
final class StopStreamingException extends \RuntimeException {}
