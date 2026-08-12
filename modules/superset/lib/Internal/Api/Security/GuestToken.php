<?php

namespace Bitrix\Superset\Internal\Api\Security;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class GuestToken
{
	private const GUEST_TOKEN_API_LINK = '/api/v1/security/guest_token/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function createGuestToken(array $payload): RequestResult
	{
		return $this->connector->post(self::GUEST_TOKEN_API_LINK, $payload);
	}
}
