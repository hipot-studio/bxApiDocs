<?php

namespace Bitrix\Main\UserField\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\UI\FileUploader\Uploader;

class UiFileUploaderResultValidator
{
	public function __construct(private readonly array $userField)
	{

	}
	public function validate(int $value): ?bool
	{
		if (!Loader::includeModule('ui'))
		{
			return null;
		}

		$sessionId = $this->getSessionIdFromRequest();
		if (!$sessionId)
		{
			return null;
		}

		$fileUploaderSession = \Bitrix\Main\UserField\File\UploadSession::loadBySessionId($sessionId);
		if (!$fileUploaderSession)
		{
			return null;
		}

		$this->tryDeleteFiles($fileUploaderSession);
		if ($fileUploaderSession->wasFileDeleted($value))
		{
			return false;
		}

		$fileContext = $this->getValidatedFileContext($fileUploaderSession, $value);
		if (!$fileContext)
		{
			return null;
		}

		if ($fileContext['TMP_FILE_TOKEN'] ?? null) // this is a new uploaded file
		{
			$userFieldDataForContextGenerator = $this->userField;
			if ($this->isFileInNewItem($fileContext))
			{
				$userFieldDataForContextGenerator['ENTITY_VALUE_ID'] = 0; // FieldFileUploaderController params must be exactly same when upload and when make persistent
			}
			$uploaderContextGenerator = (new \Bitrix\Main\UserField\File\UploaderContextGenerator($userFieldDataForContextGenerator));
			$uploader = new Uploader(
				new \Bitrix\Main\FileUploader\FieldFileUploaderController($uploaderContextGenerator->getContextInEditMode($fileUploaderSession)['controllerOptions'])
			);
			$uploader->getPendingFiles([$fileContext['TMP_FILE_TOKEN']])->makePersistent();
		}

		$fileUploaderSession->unregisterFile($value);

		return true;
	}

	public function getDeletedFileIdsFromRequest(): array
	{
		$deletedFiles = $this->getValueFromRequest(($this->userField['FIELD_NAME'] ?? '') . '_del');
		$deletedFiles = is_array($deletedFiles) ? $deletedFiles : [$deletedFiles];

		return array_values(array_filter(array_map('intval', $deletedFiles)));
	}


	public function updateEntityValueId(array $fileIds): void
	{
		$sessionId = $this->getSessionIdFromRequest();
		if (!$sessionId)
		{
			return;
		}
		$fileUploaderSession = \Bitrix\Main\UserField\File\UploadSession::loadBySessionId($sessionId);
		if (!$fileUploaderSession)
		{
			return;
		}
		foreach ($fileIds as $fileId)
		{
			$fileContext = $fileUploaderSession->getFileContext($fileId);
			if (!$fileContext)
			{
				continue;
			}
			$fileContext['ENTITY_VALUE_ID'] = $this->userField['ENTITY_VALUE_ID'];
			$fileUploaderSession->registerFile($fileId, $fileContext);
		}
	}

	private function tryDeleteFiles(UploadSession $fileUploaderSession): void
	{
		$deletedFiles = $this->getDeletedFileIdsFromRequest();

		foreach ($deletedFiles as $deletedFileId)
		{
			if ($fileUploaderSession->wasFileDeleted($deletedFileId))
			{
				continue;
			}
			$fileContext = $this->getValidatedFileContext($fileUploaderSession, $deletedFileId);
			if (!$fileContext)
			{
				continue;
			}

			if (!$this->isFileInNewItem($fileContext))
			{
				\CFile::Delete($deletedFileId);
			}

			$fileUploaderSession->markFileAsDeleted($deletedFileId);
		}
	}

	/**
	 * @param array $fileContext
	 * @return bool
	 */
	private function isFileInNewItem(array $fileContext): bool
	{
		return isset($fileContext['ENTITY_VALUE_ID']) && (int)$fileContext['ENTITY_VALUE_ID'] === 0;
	}

	private function getValidatedFileContext(UploadSession $fileUploaderSession, int $value): ?array
	{
		$fileContext = $fileUploaderSession->getFileContext($value);

		if (!$fileContext)
		{
			return null;
		}

		$isFileInNewItem = $this->isFileInNewItem($fileContext);

		if (
			!$isFileInNewItem // $fileContext['ENTITY_VALUE_ID'] = 0 means new item, but validation executed after item was created so we have to ignore
			&& (int)$this->userField['ENTITY_VALUE_ID'] !== (int)($fileContext['ENTITY_VALUE_ID'] ?? 0)
		)
		{
			return null;
		}
		if (!$fileContext['FIELD_ID'] || (int)$fileContext['FIELD_ID'] !== (int)($this->userField['ID'] ?? 0))
		{
			return null;
		}

		return $fileContext;
	}

	private function getValueFromRequest(string $paramName): mixed
	{
		$context = Context::getCurrent();
		if (!$context)
		{
			return null;
		}
		$request = $context->getRequest();
		$requestData = (array)($request['data'] ?? []);

		return $request[$paramName] ?? $requestData[$paramName] ?? null;
	}

	public function getSessionIdFromRequest(): ?string
	{
		$sessionId = $this->getValueFromRequest(($this->userField['FIELD_NAME'] ?? '') . '_session_id');
		if (!is_string($sessionId))
		{
			return null;
		}

		return $sessionId;
	}
}
