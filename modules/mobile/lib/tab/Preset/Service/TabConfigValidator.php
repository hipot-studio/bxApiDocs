<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Tabable;

final class TabConfigValidator
{
	public function __construct(private readonly Manager $manager)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public function normalize(array $config): array
	{
		if (empty($config))
		{
			throw new ArgumentException('Tab config must not be empty');
		}

		$availableTabIds = array_flip($this->manager->getAllTabIDs(true));
		$normalized = [];

		foreach ($config as $tabId => $sort)
		{
			if (!is_string($tabId) || $tabId === '' || !isset($availableTabIds[$tabId]))
			{
				throw new ArgumentException("Unknown tab id '{$tabId}'");
			}

			$tab = $this->manager->getTabInstance($tabId);
			if (!$tab instanceof Tabable)
			{
				throw new ArgumentException("Unknown tab id '{$tabId}'");
			}

			if (!$tab->isAvailable())
			{
				throw new ArgumentException("Tab '{$tabId}' is not available");
			}

			$sort = (int)$sort;
			if (!$tab->canChangeSort())
			{
				$sort = $tab->defaultSortValue();
			}
			elseif ($sort < 0)
			{
				$sort = 0;
			}
			elseif ($sort >= Manager::maxSortValue)
			{
				$sort = Manager::maxSortValue - 1;
			}

			$normalized[$tabId] = $sort;
		}

		foreach ($this->getRequiredTabIds() as $requiredTabId)
		{
			if (!array_key_exists($requiredTabId, $normalized))
			{
				throw new ArgumentException("Required tab '{$requiredTabId}' must be present");
			}
		}

		if (count($normalized) > Manager::maxCount)
		{
			throw new ArgumentException('Tab config exceeds max tab count');
		}

		asort($normalized);

		return $normalized;
	}

	private function getRequiredTabIds(): array
	{
		$required = [];
		foreach ($this->manager->getAllTabIDs(true) as $tabId)
		{
			$tab = $this->manager->getTabInstance($tabId);
			if ($tab instanceof Tabable && $tab->isAvailable() && !$tab->canBeRemoved())
			{
				$required[] = $tabId;
			}
		}

		return $required;
	}
}
