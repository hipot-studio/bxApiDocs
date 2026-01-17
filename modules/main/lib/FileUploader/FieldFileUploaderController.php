<?php

namespace Bitrix\Main\FileUploader;

use Bitrix\Main\Application;
use Bitrix\Main\UserField\File\UploadSession;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadResult;
use CUser;
use Bitrix\Main\UserField\File\UploaderFileSigner;

class FieldFileUploaderController extends UploaderController
{
	private array $signedOptions = [];
	public function __construct(array $rawOptions)
	{
		$signedOptions = $rawOptions['signed'] ?? '';
		$this->signedOptions = (new UploaderFileSigner())->unsign($signedOptions);

		parent::__construct([
			'signed' => $signedOptions,
		]);
	}

	public function isAvailable(): bool
	{
		return $this->isAuthorized();
	}

	protected function parseFileExtensions(array $extensionsSetting): array
	{
		$result = [];
		foreach($extensionsSetting as $key => $extension)
		{
			if($extension === true)
			{
				$extension = trim((string)$key);
			}
			else
			{
				$extension = trim((string)$extension);
			}
			$dotPos = mb_strrpos($extension, '.');
			if ($dotPos !== false)
			{
				$extension = mb_substr($extension, $dotPos + 1);
			}
			if($extension !== '')
			{
				$result[".$extension"] = true;
			}
		}
		if (!empty($result))
		{
			$result = array_keys($result);
		}

		return $result;
	}

	public function getConfiguration(): Configuration
	{
		/** @global \CUserTypeManager $USER_FIELD_MANAGER */
		global $USER_FIELD_MANAGER;

		$configuration = new Configuration();

		$isSetMaxAllowedSize = false;
		$fieldName = $this->getSignedOption('fieldName', '');
		$fieldInfo = $USER_FIELD_MANAGER->GetUserFields($this->getSignedOption('entityId', ''));

		if (is_array($fieldInfo[$fieldName]['SETTINGS'] ?? null))
		{
			$fieldSettings = $fieldInfo[$fieldName]['SETTINGS'];
			if (
				isset($fieldSettings['MAX_ALLOWED_SIZE'])
				&& $fieldSettings['MAX_ALLOWED_SIZE'] > 0
			)
			{
				$configuration->setMaxFileSize((int)$fieldSettings['MAX_ALLOWED_SIZE']);
				$isSetMaxAllowedSize = true;
			}
			if (
				isset($fieldSettings['EXTENSIONS'])
				&& is_array($fieldSettings['EXTENSIONS'])
				&& !empty($fieldSettings['EXTENSIONS'])
			)
			{
				$fileExtensions = $this->parseFileExtensions($fieldSettings['EXTENSIONS']);
				if (!empty($fileExtensions))
				{
					$configuration->setAcceptedFileTypes($fileExtensions);
				}
			}
		}

		if (!$isSetMaxAllowedSize)
		{
			$configuration->setMaxFileSize(null);
		}

		$configuration->setTreatOversizeImageAsFile(true);

		return $configuration;
	}

	public function canUpload()
	{
		return (bool)$this->loadSession();
	}

	protected function registerFileInSession(int $fileId, string $tempFileToken): void
	{
		$sessionId = $this->getSignedOption('sessionId');
		$connection = Application::getConnection();
		$connection->startTransaction();

		if ($sessionId)
		{
			$tempSession = \Bitrix\Main\UserField\File\UploadSession::getBySessionIdBypassingCache($sessionId);
			$tempSession->registerFile(
				$fileId,
				[
					'FIELD_ID' => $this->getSignedOption('id', 0),
					'ENTITY_VALUE_ID' =>$this->getSignedOption('entityValueId', 0),
					'TMP_FILE_TOKEN' => $tempFileToken,
				]
			);
			$tempSession->save();
		}

		$connection->commitTransaction();
	}

	public function canView(): bool
	{
		return true;
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$fileId = $this->getSignedOption('fileId');
		$sessionId =  $this->getSignedOption('sessionId');

		if ($fileId) // view mode
		{
			foreach ($files as $file)
			{
				if ($file->getId() === $fileId)
				{
					$file->markAsOwn();
				}
			}
		}

		if ($sessionId) // edit mode
		{
			$tempSession = $this->loadSession();
			if (!$tempSession)
			{
				return;
			}

			foreach ($files as $file)
			{
				if ($tempSession->hasRegisteredFile($file->getId()))
				{
					$file->markAsOwn();
				}
			}
		}
	}

	public function canRemove(): bool
	{
		return true;
	}

	public function onUploadComplete(UploadResult $uploadResult): void
	{
		$fileInfo = $uploadResult->getFileInfo();

		if ($fileInfo === null)
		{
			return;
		}

		$fileId = $fileInfo->getFileId();
		$downloadUrl = $fileInfo->getPreviewUrl();
		if (is_string($downloadUrl) && $downloadUrl !== '')
		{
			$fileInfo->setDownloadUrl('');
		}
		$previewUrl = $fileInfo->getPreviewUrl();
		if (is_string($previewUrl) && $previewUrl !== '')
		{
			$fileInfo->setPreviewUrl('', 0, 0);
		}
		$this->registerFileInSession($fileId, $uploadResult->getToken());
		$fileInfo->setCustomData(['realFileId' => $fileId]);
	}

	private function loadSession(): ?UploadSession
	{
		$sessionId = $this->getSignedOption('sessionId');
		if (!$sessionId)
		{
			return null;
		}

		$fileUploaderSession = \Bitrix\Main\UserField\File\UploadSession::loadBySessionId($sessionId);
		if (!$fileUploaderSession)
		{
			$fileUploaderSession = \Bitrix\Main\UserField\File\UploadSession::getInstance($sessionId);
		}

		return $fileUploaderSession;
	}

	private function getSignedOption(string $option, $defaultValue = null)
	{
		return array_key_exists($option, $this->signedOptions) ? $this->signedOptions[$option] : $defaultValue;
	}

	private function isAuthorized(): bool
	{
		$currentUser = (isset($USER) && $USER instanceof CUser) ? $USER : new CUser();

		return $currentUser->IsAuthorized();
	}
}
