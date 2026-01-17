<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Update\Menu;

use CBitrixComponent;

use Bitrix\Intranet\Integration\Bizproc;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;

class AiAgentMenuConverter extends Stepper
{
	protected static $moduleId = 'intranet';
	protected int $limit = 50;

	public function setLimit(int $limit): void
	{
		$this->limit = $limit;
	}

	protected function processForUser(Bizproc\Menu\MenuConverter $menuConverter, int $userId): void
	{
		$menuConverter->convertForUser($userId);
	}

	public function execute(array &$option): bool
	{
		if (\Bitrix\Main\Config\Option::get('bizproc', 'feature_ai_agents', 'N') !== 'Y')
		{
			return self::FINISH_EXECUTION;
		}

		$menuConverter = new Bizproc\Menu\MenuConverter();

		if (empty($option))
		{
			$option['steps'] = 0;
			$option['count'] = 1;
			$option['lastId'] = 0;
		}

		$userIds = $this->getUserIdsByLastId((int)($option['lastId'] ?? 0));

		foreach ($userIds as $id)
		{
			$this->processForUser($menuConverter, $id);
		}

		if (count($userIds) < $this->limit)
		{
			$this->clearMenuCache();
			return self::FINISH_EXECUTION;
		}

		$option['lastId'] = $userIds[array_key_last($userIds)];

		$this->clearMenuCache();

		return self::CONTINUE_EXECUTION;
	}

	protected function getUserIdsByLastId(int $lastId): array
	{
		$result = UserTable::query()
			->setSelect(['ID'])
			->addFilter('=IS_REAL_USER', 'Y')
			->addFilter('>ID', $lastId)
			->setLimit($this->limit)
			->exec()
			->fetchAll();

		return array_map(static fn($item) => (int)$item['ID'], $result);
	}

	public function clearMenuCache(): void
	{
		global $CACHE_MANAGER;

		$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
		$CACHE_MANAGER->CleanDir('menu');
		CBitrixComponent::clearComponentCache('bitrix:menu');
	}
}
