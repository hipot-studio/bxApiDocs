<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Agent\Stepper;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;

class CopilotMenuConverter extends Stepper
{
	private int $limit = 50;
	protected static $moduleId = 'intranet';

	public static function getTitle(): string
	{
		return Loc::getMessage('INTRANET_COPILOT_MENU_CONVERTER_STEPPER_TITLE') ?? parent::getTitle() ?? '';
	}

	public function execute(array &$option): bool
	{
		if (empty($option))
		{
			$option['steps'] = 0;
			$option['count'] = 1;
			$option['lastId'] = 0;
		}

		$menuConverter = new \Bitrix\Intranet\Internal\Service\LeftMenu\CopilotMenuConverter();
		$userIds = $this->getUserIdsByLastId((int)($option['lastId'] ?? 0));

		foreach ($userIds as $id)
		{
			$menuConverter->convertForUser($id);
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
			->where('REAL_USER', 'expr', true)
			->addFilter('>ID', $lastId)
			->setLimit($this->limit)
			->addOrder('ID')
			->exec()
			->fetchAll()
		;

		return array_map(static fn($item) => (int)$item['ID'], $result);
	}

	public function clearMenuCache(): void
	{
		global $CACHE_MANAGER;

		$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
		$CACHE_MANAGER->CleanDir('menu');
		\CBitrixComponent::clearComponentCache('bitrix:menu');
	}
}