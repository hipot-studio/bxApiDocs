<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

interface UserAwareConnectorInterface
{
	/**
	 * Makes the next requests on behalf of the user instead of the user of the hit.
	 *
	 * @param int $userId User id, 0 for a request without a user.
	 * @return void
	 */
	public function setUserId(int $userId): void;
}
