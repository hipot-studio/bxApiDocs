<?php

namespace Bitrix\Superset\Public\Commands\Dashboard;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ChangeDashboardOwnerCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly int $dashboardId,
		public readonly int $fromUserId,
		public readonly int $toUserId,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DashboardService($this->resolveServer($this->server)))->changeOwner(
			$this->dashboardId,
			$this->fromUserId,
			$this->toUserId,
		);
	}
}
