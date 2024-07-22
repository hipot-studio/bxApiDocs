<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware\Queue;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;

class BeforeRequestQueue extends Queue
{
	public function execute(IntegratorRequest $request): ?IntegratorResponse
	{
		foreach ($this->getQueue() as $middleware)
		{
			$response = $middleware->beforeRequest($request);
			if ($response)
			{
				return $response;
			}
		}

		return null;
	}
}
