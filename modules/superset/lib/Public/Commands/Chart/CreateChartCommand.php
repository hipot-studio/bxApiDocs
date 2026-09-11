<?php

namespace Bitrix\Superset\Public\Commands\Chart;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ChartService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class CreateChartCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly array $payload,
	)
	{
	}

	protected function execute(): Result
	{
		return (new ChartService($this->resolveServer($this->server)))->create($this->payload);
	}
}
