<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\SqlExecutionContext;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;

class SqlLabIntegratorTransport
{
	public function execute(string $sql, ?int $limit, SqlExecutionContext $context): IntegratorResponse
	{
		return IntegratorFactory::getInstance()->executeSql($sql, $limit, $context);
	}
}
