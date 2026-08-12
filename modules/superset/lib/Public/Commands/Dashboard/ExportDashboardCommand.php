<?php

namespace Bitrix\Superset\Public\Commands\Dashboard;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ExportDashboardCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly int $dashboardId,
		public readonly array $dashboardSettings = [],
	)
	{
	}

	protected function execute(): Result
	{
		return (new DashboardService($this->resolveServer($this->server)))->export(
			$this->dashboardId,
			$this->dashboardSettings,
		);
	}
}
