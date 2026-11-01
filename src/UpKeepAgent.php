<?php

namespace Passioncorners\UpKeepAgent;

/**
 * Names and limits shared across the agent. Constants are untyped on purpose: typed
 * class constants need PHP 8.3, and the agent supports 8.2.
 */
final class UpKeepAgent
{
    public const VERSION = '1.0.1';

    /** The shape of the agent page. up-keep checks it and ignores fields it doesn't know. */
    public const CONTRACT = 1;

    /** The agent's own copy of the app's default database connection. */
    public const CONNECTION = 'upkeep-agent';

    /** The agent's own copy of the app's default Redis connection, with short timeouts. */
    public const REDIS_CONNECTION = 'upkeep-agent';

    /** A reading older than this (CPU, a queue's front job) no longer describes now. */
    public const STALE_SECONDS = 180;

    /** Error groups per page; up-keep asks again for the rest a minute later. */
    public const PAGE_SIZE = 200;
}
