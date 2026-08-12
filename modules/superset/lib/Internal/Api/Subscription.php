<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Subscription
{
	private const SUBSCRIPTION_API_LINK = '/api/v1/bitrix/subscription/';
	private const DATE_PARAMETER = 'date';
	private const DASHBOARDS_PARAMETER = 'dashboards';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function setExpirationDate(int $timestamp): RequestResult
	{
		$url = self::SUBSCRIPTION_API_LINK . "date/";
		$payload = [
			self::DATE_PARAMETER => $timestamp,
		];

		return $this->connector->post($url, $payload);
	}

	public function syncRequire(array $marketDashboardsIdList): RequestResult
	{
		$url = self::SUBSCRIPTION_API_LINK . "require/";
		$payload = [
			self::DASHBOARDS_PARAMETER => $marketDashboardsIdList,
		];

		return $this->connector->post($url, $payload);
	}
}
