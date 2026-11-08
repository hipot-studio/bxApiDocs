<?php

namespace Bitrix\BIConnector\Superset\Demo;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;

final class DemoCredentialsResult
{
	public function __construct(
		public readonly DemoState $state,
		public readonly ?Dto\DashboardEmbeddedCredentials $credentials = null,
	)
	{}
}
