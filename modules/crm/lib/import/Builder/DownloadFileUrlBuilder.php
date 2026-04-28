<?php

namespace Bitrix\Crm\Import\Builder;

use Bitrix\Crm\Import\Enum\TemporaryFileType;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Web\Uri;

final class DownloadFileUrlBuilder
{
	private readonly UrlManager $urlManager;

	public function __construct()
	{
		$this->urlManager = UrlManager::getInstance();
	}

	public function getDownloadDuplicateUrl(int $entityTypeId, string $importFileId): Uri
	{
		return $this->urlManager
			->create(
				action: 'crm.item.import.downloadImportResultFile',
				params: [
					'entityTypeId' => $entityTypeId,
					'importFileId' => $importFileId,
					'rawType' => TemporaryFileType::Duplicate->value,
				],
			)
		;
	}

	public function getDownloadFailImportUrl(int $entityTypeId, string $importFileId): Uri
	{
		return $this->urlManager
			->create(
				action: 'crm.item.import.downloadImportResultFile',
				params: [
					'entityTypeId' => $entityTypeId,
					'importFileId' => $importFileId,
					'rawType' => TemporaryFileType::Error->value,
				],
			)
		;
	}
}
