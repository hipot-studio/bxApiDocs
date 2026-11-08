<?php

namespace Bitrix\Rest\V3\Mcp\Access;

use Bitrix\Rest\V3\Schema\MethodDescription;

/**
 * Module-agnostic default: a signed user may see and run every exported method, an unauthorized one may not.
 * Rights on the entity itself are unknown here, so the module that has them supplies its own checker.
 */
final class DefaultMethodAccessChecker implements MethodAccessCheckerInterface
{
	public function canAccess(MethodDescription $methodDescription, int $userId): bool
	{
		return $userId > 0;
	}
}
