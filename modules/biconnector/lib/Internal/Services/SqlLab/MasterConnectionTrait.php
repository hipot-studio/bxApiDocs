<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\Main\Application;

trait MasterConnectionTrait
{
	protected function withMaster(\Closure $operation): mixed
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $operation(Application::getConnection());
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}
}
