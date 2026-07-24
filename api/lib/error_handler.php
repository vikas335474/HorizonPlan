<?php
declare(strict_types=1);

/**
 * Turn uncaught exceptions and fatal errors into a structured JSON 500 instead
 * of a blank PHP fatal.
 *
 * PDO runs in ERRMODE_EXCEPTION (see db_config), so any DB problem — a missing
 * table because a migration wasn't run on the server, a bad credential, a
 * dropped connection — throws a PDOException. Without a handler that becomes a
 * non-JSON 500, which the frontend fetch layer can only report as an opaque
 * "Request failed (500)" (see frontend/src/lib/api.js) with no clue to the
 * cause. Here we:
 *   - log the real detail to the PHP error log (server-side only), tagged with
 *     a short reference id, and
 *   - return a generic JSON message + that same ref to the client.
 * The user can then quote the ref to find the exact exception in the host's
 * error log, without the app ever leaking DB internals to the browser.
 *
 * Call installApiErrorHandlers() once, as early in the request as possible.
 */
function installApiErrorHandlers(): void
{
    set_exception_handler(static function (\Throwable $e): void {
        hp_emit_500($e);
    });

    // register_shutdown_function fires even on a fatal error (E_ERROR etc.)
    // that never reaches the exception handler — this is the only way to turn
    // those into JSON too.
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (in_array($err['type'], $fatalTypes, true)) {
            hp_emit_500(new \ErrorException(
                $err['message'], 0, $err['type'], $err['file'], $err['line']
            ));
        }
    });
}

/**
 * Emit a JSON 500 for a throwable and log the detail. Guards against emitting
 * twice (e.g. exception handler followed by the shutdown function).
 */
function hp_emit_500(\Throwable $e): void
{
    static $emitted = false;
    if ($emitted) {
        return;
    }
    $emitted = true;

    $ref = bin2hex(random_bytes(4));

    // Full detail to the server log ONLY — never to the client.
    error_log(sprintf(
        '[HorizonPlan %s] %s: %s in %s:%d',
        $ref,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode([
        'status'  => 'error',
        'message' => 'Internal server error.',
        'ref'     => $ref,
    ]);
}
