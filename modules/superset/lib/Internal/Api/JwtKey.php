<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class JwtKey
{
	private const JWT_SECRET_API_LINK = '/api/v1/bitrix/jwt/secret/';

	private SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	/**
	 * Pushes JWT public key to Superset instance.
	 *
	 * @param string $publicKey Original public key.
	 * @return RequestResult
	 */
	public function pushSecret(string $publicKey): RequestResult
	{
		return $this->connector->post(self::JWT_SECRET_API_LINK, ['secret' => $publicKey]);
	}
}
