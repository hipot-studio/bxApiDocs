<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

/**
 * The request of disk.sync.capabilities.get: no parameters at all.
 *
 * The class is empty on purpose and is still needed. A parameter of the contour exists by being
 * declared as a property here, so declaring none makes the allowlist empty and every parameter a
 * client sends a refusal. Were the action to take no request at all, the strict check would never
 * run and a client that mistyped a parameter - or built its request for a newer schema - would be
 * answered as if the portal had honoured it.
 */
final class CapabilitiesRequest extends StrictRequest
{
}
