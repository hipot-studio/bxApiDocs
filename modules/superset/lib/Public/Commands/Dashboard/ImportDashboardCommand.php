<?php

namespace Bitrix\Superset\Public\Commands\Dashboard;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ArchiveFileDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\ArchiveFileNormalizer;

final class ImportDashboardCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ArchiveFileDto $uploadedFile,
		public readonly string $currency = '',
		public readonly string $langCode = '',
		public readonly ?string $appCode = null,
		public readonly bool $requiresSubscription = false,
		public readonly bool $forceImportDatasets = false,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DashboardService($this->resolveServer($this->server)))->import(
			(new ArchiveFileNormalizer())->normalize($this->uploadedFile),
			$this->currency,
			$this->langCode,
			$this->appCode,
			$this->requiresSubscription,
			$this->forceImportDatasets,
		);
	}
}
