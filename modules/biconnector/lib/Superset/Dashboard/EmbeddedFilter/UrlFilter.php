<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;

abstract class UrlFilter
{
	protected readonly Dashboard $dashboard;
	protected readonly ?string $filterId;

	public function __construct(Dashboard $dashboard, ?string $filterId = null)
	{
		$this->dashboard = $dashboard;
		$this->filterId = $filterId;
	}

	abstract public static function getFilterType(): string;

	public function getCode(): string
	{
		if ($this->filterId)
		{
			return $this->filterId;
		}

		$config = $this->dashboard->getNativeFiltersConfig();
		$timeFilter = array_filter($config, static fn($item) => $item['filterType'] === static::getFilterType());
		$timeFilter = array_pop($timeFilter);

		return (string)$timeFilter['id'];
	}

	abstract public function getFormatted(): string;

	public function isAvailable(): bool
	{
		return !empty($this->getCode());
	}
}
