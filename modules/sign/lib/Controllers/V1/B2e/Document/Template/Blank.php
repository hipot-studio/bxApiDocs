<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document\Template;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Result\Service\Sign\Document\Template\BlankDownloadResult;
use Bitrix\Sign\Result\Service\Sign\Document\Template\PrepareBlankDownloadResult;
use Bitrix\Sign\Service\Sign\BlankArchiveService;

/**
 * Downloading of unfilled template files for a set of entities of the KEDO template list.
 * The permission attribute is only a cheap entry to the surface: per-entity access and the
 * operation limit are checked by the service on every call, the download one included.
 */
class Blank extends Controller
{
	/**
	 * The number of templates left out of the answer travels with the file itself: the client warns
	 * about them without a second call, and the resolve behind it is done once per operation.
	 */
	public const HEADER_SKIPPED_COUNT = 'X-Sign-Skipped-Count';

	/**
	 * Advisory pre-check: tells the client how many templates the download will serve and how many
	 * are skipped. No product consumer calls it - the count of the skipped ones reaches the client
	 * with the file itself - so the action is kept as the declared contract.
	 *
	 * @param array<array{id?: mixed, entityType?: mixed}> $entities
	 * @return array{templatesCount?: int, skippedCount?: int}
	 */
	#[ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_READ)]
	public function prepareDownloadAction(array $entities = []): array
	{
		$result = $this->container->getTemplateBlankDownloadService()->prepare($entities);
		if (!$result instanceof PrepareBlankDownloadResult)
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		return $result->getData();
	}

	/**
	 * @param array<array{id?: mixed, entityType?: mixed}> $entities
	 */
	#[ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_READ)]
	public function downloadAction(array $entities = []): Main\Engine\Response\BFile|Main\Engine\Response\File|array
	{
		$result = $this->container->getTemplateBlankDownloadService()->download($entities);
		if (!$result instanceof BlankDownloadResult)
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		if ($result->isArchive())
		{
			return (new Main\Engine\Response\File($result->archivePath, $result->fileName, 'application/zip'))
				->showInline(false)
				->addHeader(self::HEADER_SKIPPED_COUNT, (string)$result->skippedCount)
			;
		}

		$fileArray = \CFile::GetFileArray($result->fileId);
		if (!is_array($fileArray))
		{
			$this->addError(
				new Error(
					(string)Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_TEMPLATE_BLANK_ERROR_FILE_MISSING'),
					BlankArchiveService::ERROR_CODE_BLANK_FILE_NOT_FOUND,
				),
			);

			return [];
		}

		return Main\Engine\Response\BFile::createByFileData($fileArray, $result->fileName)
			->showInline(false)
			->addHeader(self::HEADER_SKIPPED_COUNT, (string)$result->skippedCount)
		;
	}
}
