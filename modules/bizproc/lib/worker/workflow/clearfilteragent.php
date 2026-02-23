<?php

namespace Bitrix\Bizproc\Worker\Workflow;

use Bitrix\Main;
use Bitrix\Main\Application;

class ClearFilterAgent
{
	protected const CLEAR_LOG_SELECT_LIMIT = 50000;
	protected const CLEAR_LOG_DELETE_LIMIT = 1000;
	private const AGENT_INTERVAL = 15 * 60;

	public static function getName()
	{
		return static::class . '::execute();';
	}

	public static function execute()
	{
		$days = 180;
		if (!Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			$days = (int)Main\Config\Option::get('bizproc', 'search_cleanup_days', 180);
		}

		static::clear($days);

		return static::getName();
	}

	private static function clear(int $days): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$limit = static::CLEAR_LOG_SELECT_LIMIT;
		$partLimit = static::CLEAR_LOG_DELETE_LIMIT;
		$sqlInterval = $helper->addDaysToDateTime(-1 * $days);

		$strSql = "SELECT WORKFLOW_ID FROM b_bp_workflow_filter "
			. "WHERE STARTED < {$sqlInterval} LIMIT {$limit}";
		$ids = $connection->query($strSql)->fetchAll();
		$idsCount = count($ids);

		if ($idsCount > 0)
		{
			while ($partIds = array_splice($ids, 0, $partLimit))
			{
				$inSql = "'" . implode("','", array_column($partIds, 'WORKFLOW_ID')) . "'";
				$connection->query(
					sprintf(
						'DELETE from b_bp_workflow_filter WHERE WORKFLOW_ID IN(%s)',
						$inSql,
					)
				);
			}
		}

		global $pPERIOD;
		if ($idsCount === $limit)
		{
			$pPERIOD = self::AGENT_INTERVAL;
		}
		else
		{
			$pPERIOD = strtotime('tomorrow 01:00') - time();
		}
	}
}
