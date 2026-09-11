<?php

namespace Bitrix\Superset\Public\Commands\Dataset;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\VirtualDatasetService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class CreateDatasetFromSqlCommand extends AbstractServerCommand
{
	/**
	 * @param ServerReferenceDto $server
	 * @param string $datasetName Display name of the virtual dataset.
	 * @param string $sql Raw SQL SELECT used as the dataset source.
	 * @param int $ownerId Superset owner id.
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $datasetName,
		public readonly string $sql,
		public readonly int $ownerId,
	)
	{
	}

	protected function execute(): Result
	{
		return (new VirtualDatasetService($this->resolveServer($this->server)))->createFromSql(
			$this->datasetName,
			$this->sql,
			$this->ownerId,
		);
	}
}
