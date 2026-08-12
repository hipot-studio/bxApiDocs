<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Exception;

use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * Returned for non-admin callers both when:
 *   - the call exists but the caller is not a participant, and
 *   - the call does not exist at all.
 * This indistinguishability is intentional — it prevents ID enumeration.
 */
class FollowUpNotAccessibleException extends AccessDeniedException
{
	/**
	 * Stable machine-readable code published as part of the public REST contract.
	 * Default RestException::getRegistryCode() would emit
	 * "BITRIX_CALL_CONTROLLER_EXCEPTION_FOLLOWUPNOTACCESSIBLEEXCEPTION" which is not
	 * documented and would break client error-handling.
	 */
	public function getRegistryCode(): string
	{
		return 'access_denied';
	}
}
