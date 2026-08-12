<?php

namespace Bitrix\Superset\Public\Commands\Dataset;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatasetService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ReplaceDatasetOwnerCommand extends AbstractServerCommand
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
		return (new DatasetService($this->resolveServer($this->server)))->replaceOwner(
			$this->fromOwnerId,
			$this->replacementOwnerIds,
			$this->maxExecutionTime,
		);
	}
}
