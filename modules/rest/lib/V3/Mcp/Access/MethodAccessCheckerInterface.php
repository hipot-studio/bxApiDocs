<?php

namespace Bitrix\Rest\V3\Mcp\Access;

use Bitrix\Rest\V3\Schema\MethodDescription;

/**
 * Pre-filter of the tool list: it keeps methods the user has no rights for out of the published index
 * and off the executable set. It is not the access boundary itself: every call is still checked by the
 * controller and the domain commands.
 *
 * A module whose methods are guarded by entity-level rights supplies its own implementation through
 * its tool set provider; the module owns that knowledge, rest does not.
 */
interface MethodAccessCheckerInterface
{
	public function canAccess(MethodDescription $methodDescription, int $userId): bool;
}
