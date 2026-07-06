<?php

declare(strict_types=1);

namespace Bitrix\Rest\Update;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Models\SystemUserTable;

final class DeleteApplicationSystemUsers extends Stepper
{
	protected static $moduleId = 'rest';

	private const LIMIT = 50;

	public function execute(array &$option): bool
	{
		if (!Loader::includeModule('rest'))
		{
			return self::FINISH_EXECUTION;
		}

		$this->initializeOption($option);

		$systemUsers = $this->getSystemUsers((int)$option['lastId']);

		$systemUserIds = [];
		foreach ($systemUsers as $systemUser)
		{
			$systemUserId = (int)$systemUser['ID'];
			$userId = (int)$systemUser['USER_ID'];

			if ($userId > 0 && (\CUser::Delete($userId) || \CUser::GetByID($userId)->Fetch() === false))
			{
				$systemUserIds[] = $systemUserId;
			}
			else
			{
				(new \CUser())->Update($userId, ['ACTIVE' => 'N']);
			}
			$option['lastId'] = $systemUserId;
		}

		$this->deleteSystemUserRows($systemUserIds);

		return count($systemUsers) === self::LIMIT ? self::CONTINUE_EXECUTION : self::FINISH_EXECUTION;
	}

	private function initializeOption(array &$option): void
	{
		if (!empty($option))
		{
			return;
		}

		$option['steps'] = 0;
		$option['count'] = 1;
		$option['lastId'] = 0;
	}

	private function getSystemUsers(int $lastId): array
	{
		return SystemUserTable::query()
			->setSelect(['ID', 'USER_ID'])
			->where('RESOURCE_TYPE', ResourceType::APPLICATION->value)
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::LIMIT)
			->fetchAll()
		;
	}

	private function deleteSystemUserRows(array $systemUserIds): void
	{
		if (empty($systemUserIds))
		{
			return;
		}

		Application::getConnection()->queryExecute(
			'DELETE FROM ' . SystemUserTable::getTableName() . ' WHERE ID IN (' . implode(',', $systemUserIds) . ')'
		);
	}
}
