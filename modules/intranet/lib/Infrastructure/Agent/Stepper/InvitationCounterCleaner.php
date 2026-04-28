<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Agent\Stepper;

use Bitrix\Main\Application;
use Bitrix\Main\Update\Stepper;

class InvitationCounterCleaner extends Stepper
{
	protected static $moduleId = 'intranet';
	private int $limit = 500;

	public function execute(array &$option): bool
	{
		if (empty($option))
		{
			$option['steps'] = 0;
			$option['count'] = 1;
			$option['lastId'] = 0;
		}
		$counterIds = $this->getCounterId((int)$option['lastId']);

		if (empty($counterIds))
		{
			return self::FINISH_EXECUTION;
		}

		Application::getConnection()->query("
			DELETE FROM b_user_counter
			WHERE
			ID IN (" . implode(',', $counterIds) . ")
		");
		$option['lastId'] = end($counterIds);

		return count($counterIds) < $this->limit ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	private function getCounterId(int $lastId = 0): array
	{
		$result = Application::getConnection()->query("
			SELECT uc.ID
			FROM b_user_counter uc
					 LEFT JOIN b_hr_structure_node_member m
							   ON m.ENTITY_ID = uc.USER_ID
								   AND m.ENTITY_TYPE = 'USER'
			WHERE uc.CODE IN ('invited_users', 'wait_confirmation', 'total_invitation')
			  AND m.ENTITY_ID IS NULL
				AND uc.ID > " . $lastId . "
			ORDER BY uc.ID
			LIMIT " . $this->limit . "
		")->fetchAll();

		return array_column($result, 'ID');
	}
}
