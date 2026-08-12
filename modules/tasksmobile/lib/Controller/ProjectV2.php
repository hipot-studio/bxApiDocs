<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\TasksMobile\Dto\ProjectV2RequestFilter;
use Bitrix\TasksMobile\Provider\ProjectV2Provider;

Loader::requireModule('socialnetwork');

final class ProjectV2 extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'loadItems',
			'loadByIds',
			'getSearchBarPresets',
		];
	}

	/**
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws LoaderException
	 */
	public function loadItemsAction(
		ProjectV2RequestFilter $projectSearchParams,
		?PageNavigation $pageNavigation = null,
		string $mode = ProjectV2Provider::MODE_PROJECT,
		array $extra = [],
	): array
	{
		$provider = new ProjectV2Provider(
			userId: (int)$this->getCurrentUser()?->getId(),
			mode: $mode,
			pageNavigation: $pageNavigation,
		);

		$ids = $this->getIdsFromExtra($extra);
		if (!empty($ids))
		{
			return $this->convertKeysToCamelCase(
				$provider->loadByIds($ids),
			);
		}

		return $this->convertKeysToCamelCase(
			$provider->loadItems($projectSearchParams),
		);
	}

	/**
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws LoaderException
	 */
	public function loadByIdsAction(
		array $ids = [],
		string $mode = ProjectV2Provider::MODE_PROJECT,
	): array
	{
		$provider = new ProjectV2Provider(
			userId: (int)$this->getCurrentUser()?->getId(),
			mode: $mode,
		);

		return $this->convertKeysToCamelCase(
			$provider->loadByIds($ids),
		);
	}

	public function getSearchBarPresetsAction(
		string $mode = ProjectV2Provider::MODE_PROJECT,
	): array
	{
		$provider = new ProjectV2Provider(
			userId: (int)$this->getCurrentUser()?->getId(),
			mode: $mode,
		);

		return $this->convertKeysToCamelCase(
			$provider->getSearchBarPresets(),
		);
	}

	private function getIdsFromExtra(array $extra): array
	{
		$ids = $extra['filterParams']['ID'] ?? [];
		$ids = is_array($ids) ? $ids : [$ids];

		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[$id] = $id;
			}
		}

		return array_values($normalizedIds);
	}
}
