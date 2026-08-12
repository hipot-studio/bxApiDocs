<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class JwtKeyPushService extends AbstractSupersetContext
{
	public function pushPublicKey(string $publicKey): Main\Result
	{
		$api = new Api\JwtKey($this->connector);
		$requestResult = $api->pushSecret($publicKey);

		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Failed to push JWT public key to Superset');
		}

		return new Main\Result();
	}
}
