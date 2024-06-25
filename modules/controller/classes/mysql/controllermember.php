<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/controller/classes/general/controllermember.php';

class CControllerMember extends CAllControllerMember
{
	public static function _CheckCommandId($member_guid, $command_id)
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		$sql = "
			SELECT C.ID, C.COMMAND, M.SECRET_ID, C.ADD_PARAMS
			FROM
				b_controller_command C
				INNER JOIN b_controller_member M ON C.MEMBER_ID = M.MEMBER_ID
			WHERE
				C.MEMBER_ID = '" . $helper->forSql($member_guid, 32) . "'
				AND C.COMMAND_ID = '" . $helper->forSql($command_id, 32) . "'
				AND C.DATE_EXEC IS NULL
				AND C.DATE_INSERT > " . $helper->addSecondsToDateTime('-60') . "
		";

		$command = $connection->query($sql)->fetch();
		if (!$command)
		{
			return false;
		}

		$connection->queryExecute('UPDATE b_controller_command SET DATE_EXEC = ' . $helper->getCurrentDateTimeFunction() . ' WHERE ID = ' . $command['ID']);

		return $command;
	}

	public static function UnregisterExpiredAgent($id = false)
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		$handledMembers = [];

		if ($id > 0)
		{
			$strAddWhere = 'AND M.ID = ' . intval($id);
		}
		else
		{
			$strAddWhere = '';
		}

		$rsTrialGroups = $connection->query('SELECT ID FROM b_controller_group WHERE TRIAL_PERIOD > 0');
		$arTrialGroups = [];
		while ($arGroup = $rsTrialGroups->fetch())
		{
			$arTrialGroups[] = $arGroup['ID'];
		}

		if ($arTrialGroups)
		{
			$strSql = '
				SELECT M.ID
				FROM b_controller_member M
				INNER JOIN b_controller_group G ON M.CONTROLLER_GROUP_ID = G.ID
				WHERE
					G.ID in (' . implode(',', $arTrialGroups) . ')
					AND G.TRIAL_PERIOD > 0
					AND M.IN_GROUP_FROM < ' . $helper->addDaysToDateTime('-G.TRIAL_PERIOD') . '
					AND SITE_ACTIVE = \'Y\'
					AND DISCONNECTED = \'N\'
					' . $strAddWhere . '
			';

			$dbr = $connection->query($strSql);
			while ($ar = $dbr->fetch())
			{
				if ($id > 0)
				{
					CControllerMember::CloseMember($id, true);

					return true;
				}
				elseif (!isset($handledMembers[$ar['ID']]))
				{
					$handledMembers[$ar['ID']] = $ar['ID'];
					CControllerTask::Add([
						'TASK_ID' => 'CLOSE_MEMBER',
						'CONTROLLER_MEMBER_ID' => $ar['ID'],
						'INIT_EXECUTE_PARAMS' => true,
					]);
				}
			}
		}

		$strSql = '
			SELECT M.ID
			FROM b_controller_member M
			WHERE
				(DATE_ACTIVE_FROM IS NULL OR DATE_ACTIVE_FROM <= ' . $helper->getCurrentDateTimeFunction() . ')
				AND (DATE_ACTIVE_TO IS NULL OR DATE_ACTIVE_TO >= ' . $helper->getCurrentDateTimeFunction() . ')
				AND ACTIVE = \'Y\'
				AND SITE_ACTIVE <> \'Y\'
				AND DISCONNECTED = \'N\'
				' . $strAddWhere . '
		';

		$dbr = $connection->query($strSql);
		while ($ar = $dbr->fetch())
		{
			if ($id > 0)
			{
				CControllerMember::CloseMember($id, false);

				return true;
			}
			elseif (!isset($handledMembers[$ar['ID']]))
			{
				$handledMembers[$ar['ID']] = $ar['ID'];
				CControllerTask::Add([
					'TASK_ID' => 'CLOSE_MEMBER',
					'CONTROLLER_MEMBER_ID' => $ar['ID'],
					'INIT_EXECUTE_PARAMS' => false,
				]);
			}
		}

		$strSql = '
			SELECT M.ID
			FROM b_controller_member M
			WHERE
				(
					DATE_ACTIVE_FROM > ' . $helper->getCurrentDateTimeFunction() . '
					OR DATE_ACTIVE_TO < ' . $helper->getCurrentDateTimeFunction() . '
					OR ACTIVE = \'N\'
				)
				AND SITE_ACTIVE <> \'N\'
				AND DISCONNECTED = \'N\'
				' . $strAddWhere . '
		';

		$dbr = $connection->query($strSql);
		while ($ar = $dbr->fetch())
		{
			if ($id > 0)
			{
				CControllerMember::CloseMember($id, true);

				return true;
			}
			elseif (!isset($handledMembers[$ar['ID']]))
			{
				$handledMembers[$ar['ID']] = $ar['ID'];
				CControllerTask::Add([
					'TASK_ID' => 'CLOSE_MEMBER',
					'CONTROLLER_MEMBER_ID' => $ar['ID'],
					'INIT_EXECUTE_PARAMS' => true,
				]);
			}
		}

		if ($id > 0)
		{
			return true;
		}

		return 'CControllerMember::UnregisterExpiredAgent();';
	}
}
