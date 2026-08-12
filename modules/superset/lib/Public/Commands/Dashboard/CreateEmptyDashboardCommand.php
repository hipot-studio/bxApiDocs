<?php

namespace Bitrix\Superset\Public\Commands\Dashboard;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class CreateEmptyDashboardCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly array $fields,
		public readonly int $ownerId,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DashboardService($this->resolveServer($this->server)))->createEmpty(
			$this->fields,
			$this->ownerId,
		);
	}
}
