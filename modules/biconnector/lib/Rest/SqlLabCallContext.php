<?php

namespace Bitrix\BIConnector\Rest;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;

final class SqlLabCallContext
{
	public function __construct(
		public readonly User $user,
		public readonly string $callerKey,
		public readonly string $authType,
	)
	{}
}
