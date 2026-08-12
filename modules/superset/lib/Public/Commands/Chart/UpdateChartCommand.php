<?php

namespace Bitrix\Superset\Public\Commands\Chart;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ChartService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class UpdateChartCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly int $chartId,
		public readonly array $payload,
	)
	{
	}

	protected function execute(): Result
	{
		return (new ChartService($this->resolveServer($this->server)))->update($this->chartId, $this->payload);
	}
}
