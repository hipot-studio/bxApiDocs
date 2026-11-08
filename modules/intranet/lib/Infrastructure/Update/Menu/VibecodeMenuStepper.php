<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Update\Menu;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;
use CBitrixComponent;
use CUserOptions;

class VibecodeMenuStepper extends Stepper
{
	protected static $moduleId = 'intranet';
	protected int $limit = 50;

	public function setLimit(int $limit): void
	{
		$this->limit = $limit;
	}

	public function execute(array &$option): bool
	{
		$siteId = $this->getMainSiteId();
		$option += ['steps' => 0, 'count' => 1, 'lastId' => 0];
		if ((int)$option['lastId'] === 0)
		{
			if (!$this->convertCommonSort($siteId))
			{
				return self::CONTINUE_EXECUTION;
			}
			$this->convertCustomSort($siteId);
		}

		$userIds = $this->getUserIdsByLastId((int)$option['lastId']);
		foreach ($userIds as $userId)
		{
			$sort = CUserOptions::GetOption('intranet', 'left_menu_sorted_items_' . $siteId, null, $userId);
			if (!$this->convertUserSort($siteId, $userId, $sort))
			{
				return self::CONTINUE_EXECUTION;
			}
			$this->clearUserCompositeCache($userId);
			$option['lastId'] = $userId;
		}

		if (count($userIds) < $this->limit)
		{
			$this->clearMenuCache();

			return self::FINISH_EXECUTION;
		}

		return self::CONTINUE_EXECUTION;
	}

	protected function getMainSiteId(): string
	{
		return (string)(\CSite::GetDefSite() ?: 's1');
	}

	protected function getUserIdsByLastId(int $lastId): array
	{
		$rows = UserTable::query()
			->setSelect(['ID'])
			->where('REAL_USER', 'expr', true)
			->addFilter('>ID', $lastId)
			->addOrder('ID', 'ASC')
			->setLimit($this->limit)
			->exec()
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	protected function convertCommonSort(string $siteId): bool
	{
		$row = CUserOptions::GetList([], [
			'USER_ID' => 0,
			'CATEGORY' => 'intranet',
			'NAME' => 'left_menu_sorted_items_' . $siteId,
		])->Fetch();

		return !$row || $this->convertUserSort(
			$siteId,
			0,
			unserialize($row['VALUE'], ['allowed_classes' => false]),
			$row['COMMON'] === 'Y',
		);
	}

	protected function convertUserSort(string $siteId, int $userId, mixed $sort, bool $common = false): bool
	{
		if (!is_array($sort) || !in_array($sort['version'] ?? null, [2, '2'], true))
		{
			return true;
		}

		$updated = (new VibecodeMenuConverter())->convert($sort);
		if ($updated === $sort)
		{
			return true;
		}

		return CUserOptions::SetOption(
			'intranet',
			'left_menu_sorted_items_' . $siteId,
			$updated,
			$common,
			$userId,
		);
	}

	protected function convertCustomSort(string $siteId): void
	{
		foreach (['', $siteId] as $scope)
		{
			$serialized = Option::getRealValue('intranet', 'left_menu_custom_preset_sort', $scope);
			if ($serialized === null || $serialized === '')
			{
				continue;
			}

			$sort = unserialize($serialized, ['allowed_classes' => false]);
			if (!is_array($sort) || !$sort)
			{
				continue;
			}

			$updated = (new VibecodeMenuConverter())->convert($sort);
			if ($updated !== $sort)
			{
				Option::set('intranet', 'left_menu_custom_preset_sort', serialize($updated), $scope);
			}
		}
	}

	protected function clearMenuCache(): void
	{
		global $CACHE_MANAGER;

		$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
		$CACHE_MANAGER->CleanDir('menu');
		CBitrixComponent::clearComponentCache('bitrix:menu');
	}

	protected function clearUserCompositeCache(int $userId): void
	{
		CacheProvider::deleteUserCache($userId, false, false);
	}
}
