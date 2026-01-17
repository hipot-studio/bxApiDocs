<?php

namespace Bitrix\Rest\V3\Realisation;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\Validation\InvalidFileException;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;
use Bitrix\UI\FileUploader\ChunkFactory;
use Bitrix\UI\FileUploader\FileDataFactory;
use Bitrix\UI\FileUploader\Uploader;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\ConfigurationValidator;

final class FileDtoProcessor
{
	public readonly Uploader $uploader;
	public readonly ConfigurationValidator $configurationValidator;

	private array $pendingFilesIds = [];

	public function __construct(UploaderController $controller)
	{
		$this->uploader = new Uploader($controller);
		$this->configurationValidator = new ConfigurationValidator($controller->getConfiguration());
	}

	public function __destruct()
	{
		$this->uploader->getPendingFiles($this->pendingFilesIds)->makePersistent();
	}

	public function processDto(Dto $dto): void
	{
		if ($dto instanceof FileDto)
		{
			$this->processFileDto($dto);
			return;
		}

		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			if (!$field->isInitialized())
			{
				continue;
			}

			if ($field->getValue() === null)
			{
				continue;
			}

			if ($field->getPropertyType() === DtoCollection::class)
			{
				foreach ($field->getValue() as $valueItem)
				{
					$this->processDto($valueItem);
				}
				continue;
			}

			if ($field->getPropertyType() instanceof FileDto)
			{
				$this->processFileDto($field->getValue());
				continue;
			}

			if (is_subclass_of($field->getPropertyType(), Dto::class))
			{
				$this->processDto($field->getValue());
				continue;
			}
		}
	}

	protected function processFileDto(FileDto $dto): void
	{
		if (!isset($dto->upload))
		{
			return;
		}

		if ($dto->upload->getFields()['url']->isInitialized()) // upload by url
		{
			$fileDataResult = FileDataFactory::createFromUrl($dto->upload->url, $dto->upload->name);
			if (!$fileDataResult->isSuccess())
			{
				throw new InvalidFileException($fileDataResult->getErrors());
			}
			$fileData = $fileDataResult->getData()['fileData'] ?? null;
			if ($fileData === null)
			{
				throw new InvalidFileException([new Error('File data could not be retrieved from the provided URL.')]);
			}
			$fileDataValidationResult = $this->configurationValidator->validateFileData($fileData);
			if (!$fileDataValidationResult->isSuccess())
			{
				throw new InvalidFileException($fileDataValidationResult->getErrors());
			}
			$chunkResult = ChunkFactory::createFromUrl($dto->upload->url, $dto->upload->name);
		}
		else // upload by base64
		{
			$fileDataResult = FileDataFactory::createFromBase64($dto->upload->data, $dto->upload->name);
			if (!$fileDataResult->isSuccess())
			{
				throw new InvalidFileException($fileDataResult->getErrors());
			}
			$fileData = $fileDataResult->getData()['fileData'] ?? null;
			if ($fileData === null)
			{
				throw new InvalidFileException([new Error('File data could not be retrieved from the provided Base64.')]);
			}
			$fileDataValidationResult = $this->configurationValidator->validateFileData($fileData);
			if (!$fileDataValidationResult->isSuccess())
			{
				throw new InvalidFileException($fileDataValidationResult->getErrors());
			}
			$chunkResult = ChunkFactory::createFromBase64($dto->upload->data, $dto->upload->name);
		}
		if (!$chunkResult->isSuccess())
		{
			throw new InvalidFileException($chunkResult->getErrors());
		}
		$chunk = $chunkResult->getData()['chunk'] ?? null;
		if ($chunk === null)
		{
			throw new InvalidFileException([new Error('Chunk could not be retrieved')]);
		}

		$chunkValidateResult = $this->configurationValidator->validateChunk($chunk);
		if (!$chunkValidateResult->isSuccess())
		{
			throw new InvalidFileException($chunkValidateResult->getErrors());
		}

		$uploadResult = $this->uploader->upload($chunk);
		if (!$uploadResult->isSuccess())
		{
			throw new InvalidFileException($uploadResult->getErrors());
		}
		$dto->upload->setResult($uploadResult);
		$dto->id = $uploadResult->getFileInfo()->getFileId();
		$dto->url = $uploadResult->getFileInfo()->getDownloadUrl();
		$this->pendingFilesIds[] = $dto->id;
	}
}