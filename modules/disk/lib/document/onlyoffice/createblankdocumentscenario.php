<?php

namespace Bitrix\Disk\Document\OnlyOffice;

use Bitrix\Disk\Analytics\DiskAnalytics;
use Bitrix\Disk\Analytics\Enum\DocumentHandlerType;
use Bitrix\Disk\Analytics\Enum\DocumentTypeEnum;
use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\User;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class CreateBlankDocumentScenario
{
	/**
	 * @var int
	 */
	private $userId;
	/**
	 * @var string
	 */
	private $language;

	public function __construct(int $userId, string $language)
	{
		$this->userId = $userId;
		$this->language = $language;
	}

	protected function getDefaultFolderForUser(): Folder
	{
		$userStorage = Driver::getInstance()->getStorageByUserId($this->userId);

		return $userStorage->getFolderForCreatedFiles();
	}

	public function createBlank(
		string $typeFile,
		Folder $targetFolder,
		array $analytics = [],
	): Result
	{
		$result = new Result();

		$storage = $targetFolder->getStorage();
		if (!$targetFolder->canAdd($storage->getSecurityContext($this->userId)))
		{
			$result->addError(new Error('Bad rights. Could not add file to the folder.'));

			return $result;
		}

		if ($typeFile === 'board')
		{
			return BoardService::createNewDocument(User::loadById($this->userId), $targetFolder);
		}

		$fileData = new BlankFileData($typeFile, $this->language);

		$newFile = $targetFolder->uploadFile(
			\CFile::makeFileArray($fileData->getSrc()),
			[
				'NAME' => $fileData->getName(),
				'CREATED_BY' => $this->userId,
			],
			[],
true
		);

		if (!$newFile)
		{
			$result->addErrors($targetFolder->getErrors());

			return $result;
		}

		Application::getInstance()->addBackgroundJob(function () use ($newFile, $analytics) {
			$analyticsEvent = (new AnalyticsEvent(
				event: 'create',
				tool: 'docs',
				category: 'docs',
			))
				->setP4("fileId_{$newFile->getId()}")
			;

			$cElement = $analytics['c_element'] ?? null;

			if (is_string($cElement))
			{
				$analyticsEvent->setElement($cElement);
			}

			$openFromDetail = $analytics['p2'] ?? null;

			if (is_string($openFromDetail))
			{
				$analyticsEvent->setP2($openFromDetail);
			}

			$docType = DocumentTypeEnum::getByExtension($newFile->getExtension());

			if ($docType instanceof DocumentTypeEnum)
			{
				$analyticsEvent->setP3($docType->value);
			}

			$analyticsEvent->send();
		});

		$result->setData([
			'file' => $newFile,
		]);

		return $result;
	}

	public function createBlankInDefaultFolder(
		string $typeFile,
		array $analytics = [],
	): Result
	{
		return $this->createBlank($typeFile, $this->getDefaultFolderForUser(), $analytics);
	}
}