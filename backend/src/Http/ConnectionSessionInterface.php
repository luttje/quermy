<?php declare(strict_types=1);

namespace Quermy\Http;

use Quermy\Drivers\DriverInterface;

/**
 * Describes the session-backed connection lifecycle.
 *
 * Controllers and other services depend on this interface, not the concrete
 * ConnectionSession, so tests can swap in a fake without touching the
 * session superglobal.
 */
interface ConnectionSessionInterface
{
    /**
     * Persist ad-hoc connection credentials into the session.
     */
    public function bindAdhoc(array $creds): void;

    /**
     * Remove any stored connection from the session.
     */
    public function clear(): void;

    /**
     * Whether credentials are currently stored in the session.
     */
    public function isBound(): bool;

    /**
     * Return a safe (password-omitted) description of the active connection,
     * or null if nothing is bound.
     *
     * @return array{mode:string, engine:string, host:string|null, port:int|null, username:string|null, database:string|null}|null
     */
    public function describe(): ?array;

    /**
     * Open and return a driver connection for this request using the stored
     * credentials.
     *
     * @throws \RuntimeException when no connection is bound.
     */
    public function open(): DriverInterface;
}
