<?php
namespace Bitrix\Crm\Agent\Filter;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Main\Config\Option;

class CleanWrongFilterStateAgent extends AgentBase
{
	public static function doRun(): bool
	{
		$instance = new self();

		return $instance->execute();
	}

	public function execute(): bool
	{
		$idsToDelete = $this->getIds();
		if (!empty($idsToDelete))
		{
			$sql = 'delete from b_user_option where ID in (' . implode(', ', $idsToDelete) . ')';
			\Bitrix\Main\Application::getConnection()->query($sql);

			return true;
		}

		global $CACHE_MANAGER;
		$CACHE_MANAGER->cleanDir("user_option");

		return false;
	}

	private function getIds(): array
	{
		$limit = (int)Option::get('crm', 'filter_wrong_state_cleanup_limit', 1000);

		$sql = "select ID from b_user_option where CATEGORY='main.ui.filter' and NAME like '%_timeline_history' limit " . $limit;
		$iterator = \Bitrix\Main\Application::getConnection()->query($sql);

		$result = [];
		while ($row = $iterator->fetch())
		{
			$result[] = $row['ID'];
		}

		return $result;
	}
}
