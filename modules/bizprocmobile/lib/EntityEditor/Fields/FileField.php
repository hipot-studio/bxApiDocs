<?php

namespace Bitrix\BizprocMobile\EntityEditor\Fields;

use Bitrix\Bizproc\FieldType;
use Bitrix\BizprocMobile\Fields\Iblock\DiskFile;
use Bitrix\Main\Loader;
use Bitrix\Mobile\UI\File;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\UploaderController;

Loader::requireModule('ui');

class FileField extends BaseField
{
	protected bool $isDiskFile;
	private int $attemptUserId = 0;
	private bool $accepted = false;
	private bool $compensated = false;
	private array $createdDiskIds = [];
	private array $tokenValues = [];
	private ?PendingFileCollection $sourceFiles = null;
	protected ?array $configFileInfo = null;
	protected string $endpoint = '';
	protected array $controllerOptionNames = [];
	protected ?UploaderController $controller = null;

	public function __construct(array $property, mixed $value, array $documentType)
	{
		parent::__construct($property, $value, $documentType);

		$this->isDiskFile = $property['Type'] === 'S:DiskFile';
		if ($this->isDiskFile && $this->fieldTypeObject)
		{
			$this->fieldTypeObject->setTypeClass(DiskFile::class);
		}
	}

	public function beginAttempt(int $userId): void
	{
		$this->attemptUserId = $userId;
	}

	public function acceptAttempt(): void
	{
		$this->accepted = true;
	}

	public function completeAttempt(): void
	{
		if (!$this->accepted || $this->sourceFiles === null)
		{
			return;
		}

		foreach ($this->sourceFiles as $pendingFile)
		{
			if ($this->isDiskFile)
			{
				$pendingFile->remove();
			}
			else
			{
				$pendingFile->makePersistent();
			}
		}
	}

	public function compensateAttempt(): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		if ($this->accepted || $this->compensated)
		{
			return $result;
		}

		foreach ($this->createdDiskIds as $id)
		{
			try
			{
				$file = \Bitrix\Disk\File::loadById($id);
				if ($file && !$file->delete($this->attemptUserId))
				{
					$result->addErrors($file->getErrors());
					$result->addError(new \Bitrix\Main\Error('Could not remove task upload', 'TASK_UPLOAD_CLEANUP'));
				}
				elseif (\Bitrix\Disk\File::loadById($id) === null)
				{
					unset($this->createdDiskIds[$id]);
				}
				else
				{
					$result->addError(new \Bitrix\Main\Error('Task upload still exists', 'TASK_UPLOAD_CLEANUP'));
				}
			}
			catch (\Throwable $exception)
			{
				$result->addError(new \Bitrix\Main\Error($exception->getMessage(), 'TASK_UPLOAD_CLEANUP'));
			}
		}

		$this->compensated = !$this->createdDiskIds;

		return $result;
	}

	private function copyPendingDiskFiles(PendingFileCollection $pendingFiles): array
	{
		Loader::requireModule('disk');
		$storage = \Bitrix\Disk\Driver::getInstance()->getStorageByUserId($this->attemptUserId);
		$folder = $storage ? $storage->getFolderForUploadedFiles() : null;
		if (!$folder || !$folder->canAdd($storage->getSecurityContext($this->attemptUserId)))
		{
			throw new \Bitrix\Main\SystemException('Cannot add task upload to Disk');
		}

		$values = [];
		foreach ($pendingFiles as $pendingFile)
		{
			if (!$pendingFile->isValid())
			{
				continue;
			}
			$token = $pendingFile->getId();
			if (!array_key_exists($token, $this->tokenValues))
			{
				$fileArray = \CFile::MakeFileArray($pendingFile->getFileId());
				$file = $folder->uploadFile(
					$fileArray,
					['NAME' => $fileArray['name'], 'CREATED_BY' => $this->attemptUserId],
					[],
					true,
				);
				if (!$file)
				{
					throw new \Bitrix\Main\SystemException('Could not copy task upload to Disk');
				}
				$id = (int)$file->getId();
				$this->createdDiskIds[$id] = $id;
				$property = \CIBlockProperty::getUserType('DiskFile');
				$documentType = $this->fieldTypeObject->getDocumentType();
				$type = explode('_', $documentType[2]);
				$value = isset($property['AttachFilesWorkflow'])
					? call_user_func($property['AttachFilesWorkflow'], (int)($type[1] ?? 0), 'n' . $id)
					: $id
				;
				if (!$value)
				{
					throw new \Bitrix\Main\SystemException('Could not attach task upload to workflow');
				}
				$this->tokenValues[$token] = $value;
			}
			$values[] = $this->tokenValues[$token];
		}

		return $values;
	}

	public function setEndpoint(string $endpoint): void
	{
		$this->endpoint = $endpoint;
	}

	public function setControllerOptionNames(array $optionNames): void
	{
		$this->controllerOptionNames = $optionNames;
	}

	public function setController(UploaderController $controller): void
	{
		$this->controller = $controller;
	}

	public function getType(): string
	{
		return 'file';
	}

	public function getConfig(): array
	{
		return [
			'fileInfo' => $this->getConfigFileInfo(),
			'mediaType' => 'file',
			'controller' => ['endpoint' => $this->endpoint],
			'controllerOptionNames' => $this->controllerOptionNames,
		];
	}

	protected function convertToMobileType($value): ?int
	{
		if (is_numeric($value) && (int)$value > 0)
		{
			return (int)$value;
		}

		return null;
	}

	public function convertValueToMobile(): ?array
	{
		$fileInfo = $this->getConfigFileInfo();
		if ($fileInfo)
		{
			return array_values($fileInfo);
		}

		return null;
	}

	protected function convertToWebType($value): ?int
	{
		return $this->convertToMobileType($value);
	}

	public function convertValueToWeb(): array
	{
		$value = $this->value;
		if (is_array($value) && (isset($value['token']) || isset($value['id'])))
		{
			$value = [$value];
		}

		if ($this->controller && is_array($value))
		{
			$ids = array_column(
				array_filter(
					$value,
					static fn($singleValue) => is_array($singleValue) && is_numeric($singleValue['id'] ?? null)
				),
				'id'
			);

			$uploader = new \Bitrix\UI\FileUploader\Uploader($this->controller);
			$pendingFiles = $uploader->getPendingFiles(
				array_column(array_filter($value), 'token'),
			);

			$this->sourceFiles ??= $pendingFiles;
			$value = $pendingFiles->getFileIds();
			if (!$this->isDiskFile)
			{
				$value = $this->addDefaultIdsFromRequestIds($value, $ids);
			}

			if ($this->isDiskFile && $this->fieldTypeObject)
			{
				$value = $this->attemptUserId > 0
					? $this->copyPendingDiskFiles($pendingFiles)
					: $this->fieldTypeObject->internalizeValue(FieldType::VALUE_CONTEXT_JN_MOBILE, $value)
				;
				if (!is_array($value))
				{
					$value = empty($value) ? [] : [$value];
				}

				if ($ids)
				{
					$defaultValue = (array)$this->property['Default'];
					foreach ($defaultValue as $singleValue)
					{
						$this->fieldTypeObject->setTypeClass(DiskFile::class);
						$fileId = $this->fieldTypeObject->convertValue($singleValue, \Bitrix\Bizproc\BaseType\File::class);
						$fileId = $this->convertToWebType(
							is_array($fileId) ? $fileId[array_key_first($fileId)] : $fileId
						);
						if ($fileId && in_array($fileId, $ids, false))
						{
							$value[] = $this->convertToWebType($singleValue);
						}
					}
				}
			}

			return [$value, $pendingFiles];
		}

		return [[], new PendingFileCollection()];
	}

	protected function getConfigFileInfo(): array
	{
		if ($this->configFileInfo === null)
		{
			$this->configFileInfo = $this->isDiskFile ? $this->getDiskFileInfo() : $this->getFileInfo();
		}

		return $this->configFileInfo;
	}

	protected function getFileInfo(array $value = null): array
	{
		$info = [];
		if ($value === null)
		{
			$value = (array)($this->value);
		}
		foreach ($value as $singleValue)
		{
			$id = $this->convertToMobileType($singleValue);
			if ($id)
			{
				$fileInfo = File::loadWithPreview($id);
				if ($fileInfo)
				{
					$info[$id] = $fileInfo->getInfo();
				}
			}
		}

		return $info;
	}

	protected function getDiskFileInfo(): array
	{
		$value = (array)$this->value;

		$diskIds = [];
		foreach ($value as $singleValue)
		{
			$id = $this->convertToMobileType($singleValue);
			if ($id)
			{
				$diskIds['n' . $id] = $id;
			}
		}

		$info = [];
		if ($diskIds && Loader::includeModule('disk'))
		{
			$diskUploader = new \Bitrix\Disk\Uf\Integration\DiskUploaderController([]);
			$loadResults = $diskUploader->load(array_keys($diskIds));
			foreach ($loadResults as $result)
			{
				if ($result->isSuccess())
				{
					$file = $result->getFile();
					if ($file)
					{
						$fileId = $file->getFileId();

						$fileInfo = $this->getFileInfo([$fileId]);
						$info[$diskIds[$file->getId()]] = $fileInfo[$fileId];
					}
				}
			}
		}

		return $info;
	}

	protected function addDefaultIdsFromRequestIds(array $value, array $ids): array
	{
		foreach ($ids as $id)
		{
			$id = $this->convertToWebType($id);
			if ($id && isset($this->property['Default']))
			{
				$defaultValue = (array)$this->property['Default'];
				if (in_array($id, $defaultValue, false))
				{
					$value[] = $id;
				}
			}
		}

		return $value;
	}
}
