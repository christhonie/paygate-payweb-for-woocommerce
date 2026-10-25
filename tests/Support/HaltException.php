<?php

declare(strict_types=1);

/**
 * Thrown where the gateway would end the request. An Error, not an Exception, so that the
 * handlers' own catch (Exception) blocks cannot swallow it.
 */
final class HaltException extends Error
{
}
