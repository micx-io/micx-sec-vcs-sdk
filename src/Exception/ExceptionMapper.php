<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Converts wire protocol codes to stable public SDK exceptions. */
final class ExceptionMapper
{
    /** @param array<string, mixed> $details */
    public static function fromWorker(
        string $code,
        string $message,
        string $operation,
        array $details = [],
    ): VcsException {
        $class = match ($code) {
            'SSH_KEY_MISSING', 'SSH_KEY_INVALID', 'SSH_AUTH_FAILED', 'SSH_HOST_KEY_FAILED',
            'KNOWN_HOSTS_MISSING', 'AUTHENTICATION_FAILED' => AuthenticationException::class,
            'ACCESS_DENIED', 'PERMISSION_DENIED', 'FORBIDDEN', 'PUSH_DENIED' => AccessDeniedException::class,
            'REPOSITORY_UNAVAILABLE', 'REPOSITORY_NOT_FOUND', 'REMOTE_NOT_FOUND', 'BRANCH_NOT_FOUND' => RepositoryNotFoundException::class,
            'CONFLICT', 'DIRTY_WORKTREE', 'PUSH_REJECTED' => ConflictException::class,
            'TIMEOUT', 'GIT_TIMEOUT', 'PULL_TIMEOUT', 'PUSH_TIMEOUT' => RepositoryTimeoutException::class,
            'INVALID_RESPONSE', 'UNAVAILABLE', 'OUTCOME_UNKNOWN', 'WORKER_UNAVAILABLE',
            'INTERNAL_ERROR', 'UNSUPPORTED', 'INVALID_REQUEST' => InternalException::class,
            default => OperationFailedException::class,
        };

        $explanation = match ($code) {
            'SSH_KEY_MISSING' => 'Kein SSH-Key für den VCS-Worker konfiguriert.',
            'SSH_AUTH_FAILED' => 'Der Git-Server hat die SSH-Anmeldung abgelehnt.',
            'SSH_HOST_KEY_FAILED' => 'Der SSH-Host-Key konnte nicht verifiziert werden.',
            'ACCESS_DENIED', 'PERMISSION_DENIED', 'FORBIDDEN', 'PUSH_DENIED' =>
                'Die Berechtigung für das Repository oder die Git-Operation fehlt.',
            'PUSH_REJECTED' => 'Der Remote-Branch weist den Push zurück; Branchschutz oder neue Remote-Commits prüfen.',
            'DIRTY_WORKTREE' => 'Arbeitsverzeichnis enthält nicht committete Änderungen.',
            'REPOSITORY_UNAVAILABLE', 'REPOSITORY_NOT_FOUND' =>
                'Repository nicht erreichbar oder nicht vorhanden; URL und Berechtigungen prüfen.',
            'TIMEOUT', 'GIT_TIMEOUT', 'PULL_TIMEOUT', 'PUSH_TIMEOUT' =>
                'Git-Operation hat ihr Zeitlimit überschritten; Ergebnis am Remote prüfen, bevor erneut gesendet wird.',
            default => '',
        };

        $description = $explanation === '' ? $message : $explanation . ' Worker: ' . $message;
        return new $class($code, $description, $operation, $details);
    }
}
