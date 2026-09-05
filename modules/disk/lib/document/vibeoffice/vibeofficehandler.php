<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Contract\FileCreatable;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\FileData;
use Bitrix\Disk\Document\IViewer;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main;

class VibeofficeHandler extends DocumentHandler implements FileCreatable, IViewer
{
	public static function getCode()
	{
		return 'vibeoffice';
	}

	public static function getName()
	{
		return Main\Localization\Loc::getMessage('DISK_VIBEOFFICE_HANDLER_NAME');
	}

	/**
	 * The vibeoffice engine is reached indirectly: opening arrives under the
	 * "onlyoffice" code and DocumentHandlersManager::resolveEffectiveHandler()
	 * substitutes vibeoffice transparently. It must not appear as a separate
	 * item in user-facing "create/open with" pickers.
	 */
	public static function isSelectableForCreation(): bool
	{
		return false;
	}

	public static function isEnabled(): bool
	{
		/** @var Configuration $configuration */
		$configuration = Main\DI\ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
		$isEnabledOnPortal = $configuration->isEnabledOnPortal();
		$isEnabledDocuments = \Bitrix\Disk\Configuration::isEnabledDocuments();

		return $isEnabledOnPortal && $isEnabledDocuments;
	}

	/**
	 * Execute this method for check potential possibility get access token.
	 * @return bool
	 */
	public function checkAccessibleTokenService()
	{
		return self::isEnabled();
	}

	public static function listEditableExtensions(): array
	{
		return [
			'doc',
			'docx',
			'docm',
			'rtf',
			'xls',
			'xlsx',
			'xlt',
			'xlsm',
			'ppt',
			'pptx',
			'pptm',
			'xodt',
			'odt',
			'odp',
			'ods',
			'otp',
			'ots',
			'ott',
			'dot',
			'pot',
			'potx',
			'potm',
		];
	}

	/**
	 * Return link for authorize user in external service.
	 * @param string $mode
	 * @return string
	 */
	public function getUrlForAuthorizeInTokenService($mode = 'modal')
	{
		return '';
	}

	/**
	 * Requests and store access token (self::accessToken) for self::userId
	 * @return $this
	 */
	public function queryAccessToken()
	{
		return $this;
	}

	/**
	 * Creates new blank file in cloud service.
	 * It is not necessary set shared rights on file.
	 * @param FileData $fileData
	 * @return FileData|null
	 */
	public function createBlankFile(FileData $fileData)
	{
		return $this->createFile($fileData);
	}

	/**
	 * @param FileData $fileData
	 * @return FileData
	 */
	public function createFile(FileData $fileData)
	{
		return $fileData;
	}

	/**
	 * Downloads file from cloud service by FileData::id, put contents in FileData::src
	 * @param FileData $fileData
	 * @return FileData|null
	 */
	public function downloadFile(FileData $fileData)
	{
	}

	/**
	 * Gets a file's metadata by ID.
	 *
	 * @param FileData $fileData
	 * @return array|null Describes file (id, title, size)
	 */
	public function getFileMetadata(FileData $fileData)
	{
	}

	/**
	 * Downloads part of file from cloud service by FileData::id, put contents in FileData::src
	 * @param FileData $fileData
	 * @param          $startRange
	 * @param          $chunkSize
	 * @return FileData|null
	 */
	public function downloadPartFile(FileData $fileData, $startRange, $chunkSize)
	{
	}

	/**
	 * Deletes file from cloud service by FileData::id
	 * @param FileData $fileData
	 * @return bool
	 */
	public function deleteFile(FileData $fileData)
	{
	}

	/**
	 * Get data for showing preview file.
	 * Array must be contains keys: id, viewUrl, neededDelete, neededCheckView
	 * @param FileData $fileData
	 * @return array|null
	 */
	public function getDataForViewFile(FileData $fileData)
	{
		$this->errorCollection[] = new Error('Could not use preview');

		return null;
	}
}
