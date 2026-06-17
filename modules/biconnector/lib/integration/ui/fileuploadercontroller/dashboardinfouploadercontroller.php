<?php

namespace Bitrix\BIConnector\Integration\UI\FileUploaderController;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadResult;

class DashboardInfoUploaderController extends UploaderController
{
	public function __construct(array $options = [])
	{
		$options['dashboardId'] = (int)($options['dashboardId'] ?? 0);
		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);
	}

	public function getConfiguration(): Configuration
	{
		$size = 10485760; // 10 * 1024 * 1024

		$configuration = new Configuration();
		$configuration->setAcceptOnlyImages(true);
		$configuration->setMaxFileSize($size);
		$configuration->setImageMaxFileSize($size);

		return $configuration;
	}

	public function canUpload()
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);
	}

	public function canView(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW);
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		foreach ($files as $file)
		{
			$fileData = \CFile::GetFileArray($file->getId());
			if (!is_array($fileData))
			{
				continue;
			}

			if (($fileData['MODULE_ID'] ?? '') === 'biconnector')
			{
				$file->markAsOwn();
			}
		}
	}

	public function canRemove(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);
	}

	public function onUploadComplete(UploadResult $uploadResult): void
	{
		$fileInfo = $uploadResult->getFileInfo();
		if ($fileInfo === null)
		{
			return;
		}

		$fileInfo->setCustomData([
			'realFileId' => $fileInfo->getFileId(),
		]);
	}
}
