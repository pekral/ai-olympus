<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use Closure;
use stdClass;
use Throwable;

/**
 * The `PreToolUse` hook contract: one JSON payload in, exactly one JSON object out, exit 0 always.
 *
 * Every rule below exists because the alternative fails open or fails wrong:
 *
 * - **Exit 0, always.** Exit 2 makes the harness block the call but discards stdout, which throws
 *   away the structured reason and the ability to answer `ask` at all. Every *other* non-zero exit
 *   is a non-blocking error — the command then simply runs. So the only exit code that can carry a
 *   decision is 0, and the whole body sits in a `catch (Throwable)` that answers `ask` rather than
 *   letting an exception decide by accident.
 * - **Never `allow`.** `BashBoundaryDecision` cannot express it; see that enum for why.
 * - **No I/O of any kind.** No log file, no network call, no temp file. A Bash command may carry a
 *   token or a secret, so writing one anywhere would create a secrets-at-rest surface that does
 *   not exist today; it also keeps the validator fast enough to run before every Bash call and
 *   unable to hang on the hook timeout.
 * - **A capped payload.** stdin is read with a ceiling by the caller and re-checked here, so a
 *   pathological payload answers `ask` instead of exhausting memory.
 */
final class AgentBashBoundaryHook
{

    /**
     * 1 MiB — orders of magnitude above any real Bash command, far below anything harmful.
     */
    public const int MAX_PAYLOAD_BYTES = 1_048_576;

    /**
     * Emitted when the validator itself failed. Written as a literal so the last-resort path
     * cannot depend on the encoder that may have just thrown.
     */
    private const string FALLBACK = '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask",'
        . '"permissionDecisionReason":"The agent Bash boundary validator could not evaluate this call."}}';

    /**
     * The edge entry point: reading stdin is itself a failure path, and an exception escaping it
     * would end the process with a non-zero exit — which the harness treats as a non-blocking
     * error, running the command. So the read is inside the same guarantee as the evaluation.
     *
     * @param \Closure(): string $payloadReader
     */
    public static function guard(Closure $payloadReader): string
    {
        try {
            $payload = $payloadReader();
        } catch (Throwable) {
            return self::FALLBACK;
        }

        return self::handle($payload);
    }

    public static function handle(string $payload): string
    {
        try {
            return self::encode(self::evaluate($payload));
        } catch (Throwable) {
            return self::FALLBACK;
        }
    }

    private static function evaluate(string $payload): BashBoundaryVerdict
    {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return new BashBoundaryVerdict(BashBoundaryDecision::Ask, 'The hook payload is larger than this validator will parse.');
        }

        $data = json_decode($payload, associative: false, depth: 64, flags: JSON_THROW_ON_ERROR);

        if (!$data instanceof stdClass) {
            return new BashBoundaryVerdict(BashBoundaryDecision::Ask, 'The hook payload is not a JSON object.');
        }

        if (self::stringField($data, 'hook_event_name') !== 'PreToolUse' || self::stringField($data, 'tool_name') !== 'Bash') {
            return new BashBoundaryVerdict(BashBoundaryDecision::Defer, 'This validator evaluates PreToolUse Bash calls only.');
        }

        $command = self::commandOf($data);

        if ($command === null) {
            return new BashBoundaryVerdict(BashBoundaryDecision::Ask, 'The PreToolUse payload carries no Bash command string.');
        }

        return AgentBashBoundaryGuard::evaluate(self::stringField($data, 'agent_type'), $command);
    }

    private static function stringField(stdClass $data, string $field): ?string
    {
        $value = $data->{$field} ?? null;

        return is_string($value) ? $value : null;
    }

    private static function commandOf(stdClass $data): ?string
    {
        $input = $data->tool_input ?? null;

        return $input instanceof stdClass ? self::stringField($input, 'command') : null;
    }

    private static function encode(BashBoundaryVerdict $verdict): string
    {
        $hookOutput = new stdClass();
        $hookOutput->hookEventName = 'PreToolUse';
        $hookOutput->permissionDecision = $verdict->decision->value;
        $hookOutput->permissionDecisionReason = $verdict->reason;

        $payload = new stdClass();
        $payload->hookSpecificOutput = $hookOutput;

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

}
