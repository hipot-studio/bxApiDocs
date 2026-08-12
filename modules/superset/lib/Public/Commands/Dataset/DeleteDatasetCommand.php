<?php

namespace Bitrix\Superset\Public\Commands\Dataset;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatasetService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class DeleteDatasetCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly int $datasetId,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DatasetService($this->resolveServer($this->server)))->delete($this->datasetId);
	}
}
