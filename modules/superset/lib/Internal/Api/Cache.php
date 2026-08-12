<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Cache
{
	private const CACHE_API_LINK = '/api/v1/bitrix_cache/clear_all';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function clearAll(): RequestResult
	{
		return $this->connector->post(self::CACHE_API_LINK);
	}
}
