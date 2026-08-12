<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Version
{
	private const VERSION_API_LINK = '/api/v1/superset_version/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function getVersion(): RequestResult
	{
		return $this->connector->get(self::VERSION_API_LINK);
	}
}
