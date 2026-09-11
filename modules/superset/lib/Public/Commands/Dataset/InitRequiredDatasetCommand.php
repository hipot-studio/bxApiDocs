<?php

namespace Bitrix\Superset\Public\Commands\Dataset;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatasetService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerConnectionDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\TokenWriterInterface;

final class InitRequiredDatasetCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto|ServerConnectionDto $server,
		public readonly array $tables = [],
		public readonly ?TokenWriterInterface $tokenWriter = null,
	)
	{
	}

	protected function execute(): Result
	{
		$resolvedServer = $this->resolveServer($this->server);

		return (new DatasetService($resolvedServer, $this->createConnector($resolvedServer, $this->tokenWriter)))
			->initRequiredDataset($this->tables)
		;
	}
}
