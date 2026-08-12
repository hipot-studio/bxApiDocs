<?php

namespace Bitrix\Superset\Public\Commands\Dashboard;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ReplaceDashboardOwnerCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly int $fromOwnerId,
		public readonly array $replacementOwnerIds,
		public readonly int $maxExecutionTime = 0,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DashboardService($this->resolveServer($this->server)))->replaceOwner(
			$this->fromOwnerId,
			$this->replacementOwnerIds,
			$this->maxExecutionTime,
		);
	}
}
