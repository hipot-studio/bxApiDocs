<?php

namespace Bitrix\Superset\Public\Commands\Dashboard;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ArchiveFileDto;
use Bitrix\Superset\Public\Dto\ServerConnectionDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\ArchiveFileNormalizer;
use Bitrix\Superset\Public\Support\TokenWriterInterface;

final class ImportDashboardCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto|ServerConnectionDto $server,
		public readonly ArchiveFileDto $uploadedFile,
		public readonly string $currency = '',
		public readonly string $langCode = '',
		public readonly ?string $appCode = null,
		public readonly bool $requiresSubscription = false,
		public readonly bool $forceImportDatasets = false,
		public readonly ?TokenWriterInterface $tokenWriter = null,
	)
	{
	}

	protected function execute(): Result
	{
		$resolvedServer = $this->resolveServer($this->server);

		return (new DashboardService($resolvedServer, $this->createConnector($resolvedServer, $this->tokenWriter)))->import(
			(new ArchiveFileNormalizer())->normalize($this->uploadedFile),
			$this->currency,
			$this->langCode,
			$this->appCode,
			$this->requiresSubscription,
			$this->forceImportDatasets,
		);
	}
}
