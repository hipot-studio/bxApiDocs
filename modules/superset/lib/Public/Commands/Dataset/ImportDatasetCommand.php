<?php

namespace Bitrix\Superset\Public\Commands\Dataset;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatasetService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ArchiveFileDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\ArchiveFileNormalizer;

final class ImportDatasetCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ArchiveFileDto $uploadedFile,
		public readonly string $currency,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DatasetService($this->resolveServer($this->server)))->import(
			(new ArchiveFileNormalizer())->normalize($this->uploadedFile),
			$this->currency,
		);
	}
}
