<?php

namespace Bitrix\Superset\Public\Commands\Dataset;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\VirtualDatasetService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class CreateVirtualDatasetCommand extends AbstractServerCommand
{
	/**
	 * @param ServerReferenceDto $server
	 * @param string $datasetName Display name of the virtual dataset.
	 * @param string $sourceTable Source physical table.
	 * @param string[] $columns Column names to project.
	 * @param int $ownerId Superset owner id.
	 * @param string|null $sourceSchema Source table schema (defaults to bitrix24).
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $datasetName,
		public readonly string $sourceTable,
		public readonly array $columns,
		public readonly int $ownerId,
		public readonly ?string $sourceSchema = null,
	)
	{
	}

	protected function execute(): Result
	{
		return (new VirtualDatasetService($this->resolveServer($this->server)))->create(
			$this->datasetName,
			$this->sourceTable,
			$this->columns,
			$this->ownerId,
			$this->sourceSchema,
		);
	}
}
