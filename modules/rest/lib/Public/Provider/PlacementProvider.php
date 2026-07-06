<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use CRestProvider;
use IRestService;

class PlacementProvider
{
	private ?array $serviceDescription = null;

	public function __construct(
		private IRestService $restService = new CRestProvider(),
	)
	{
	}

	public function getAll(bool $withPrivate = false): array
	{
		return $this->getList([], true, $withPrivate);
	}

	public function getList(array $scopeList = [], bool $showAll = false, bool $withPrivate = false): array
	{
		$serviceDescription = $this->getServiceDescription();

		if (empty($serviceDescription)) {
			return [];
		}

		if ($showAll)
		{
			$scopeList = array_keys($serviceDescription);
		}

		if (empty($scopeList))
		{
			return [];
		}

		$result = [];

		foreach($scopeList as $scope)
		{
			if (!empty($serviceDescription[$scope][\CRestUtil::PLACEMENTS])
				&& is_array($serviceDescription[$scope][\CRestUtil::PLACEMENTS])
			)
			{
				$result = array_merge($result, $serviceDescription[$scope][\CRestUtil::PLACEMENTS]);
			}
		}

		if (!$withPrivate)
		{
			$result = array_filter($result, static fn (array $item): bool => !($item['private'] ?? false));
		}

		return $result;
	}

	private function getServiceDescription(): array
	{
		if ($this->serviceDescription === null)
		{
			$serviceDescription = $this->restService->getDescription();
			$this->serviceDescription = is_array($serviceDescription) ? $serviceDescription : [];
		}

		return $this->serviceDescription;
	}
}
