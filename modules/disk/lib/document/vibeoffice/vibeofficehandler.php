<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Contract\FileCreatable;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\FileData;
use Bitrix\Disk\Document\IViewer;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
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
		$isEnabledOnPortal = Feature::isEnabledOnPortal();
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
			'csv',
			'docm',
			'docx',
			'dotm',
			'dotx',
			'epub',
			'fb2',
			'html',
			'odp',
			'ods',
			'odt',
			'otp',
			'ots',
			'ott',
			'pdf',
			'potm',
			'potx',
			'ppsm',
			'ppsx',
			'pptm',
			'pptx',
			'rtf',
			'tsv',
			'txt',
			'xlsb',
			'xlsm',
			'xlsx',
			'xltm',
			'xltx',
		];
	}

	public static function listViewableExtensions(): array
	{
		return array_merge(
			self::listEditableExtensions(),
			['doc', 'xls', 'xlt', 'ppt', 'xodt', 'dot', 'pot'],
		);
	}

	public static function listMimeRoutableExtensions(): array
	{
		// Keep the original office MIME route. VibeOffice-only formats (including
		// HTML, TXT, FB2 and EPUB) need their own file-scoped routing decision.
		// XODT is also file-scoped because its application/xml MIME is shared.
		return array_values(array_diff(OnlyOfficeHandler::listEditableExtensions(), ['xodt']));
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
