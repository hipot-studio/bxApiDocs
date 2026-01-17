<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\File\Image;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Chunk;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileData;
use Bitrix\UI\FileUploader\UploaderError;

final class ConfigurationValidator
{
	private const BASE_MIME_TYPE_REGEX = '/\/.*$/';

	public function __construct(private readonly Configuration $configuration)
	{
	}

	public function validateChunk(Chunk $chunk): Result
	{
		$fileData = new FileData($chunk->getName(), $chunk->getType(), $chunk->getFileSize());
		$result = $this->validateFileData($fileData);
		$width = 0;
		$height = 0;
		if (\CFile::isImage($chunk->getName(), $chunk->getType()))
		{
			$image = new Image($chunk->getFile()->getPhysicalPath());
			$imageInfo = $image->getInfo(false);
			if (!$imageInfo)
			{
				if ($this->configuration->getIgnoreUnknownImageTypes())
				{
					$result->setData(['width' => $width, 'height' => $height]);

					return $result;
				}
				else
				{
					return $result->addError(new UploaderError(UploaderError::IMAGE_TYPE_NOT_SUPPORTED));
				}
			}

			$width = $imageInfo->getWidth();
			$height = $imageInfo->getHeight();
			if ($imageInfo->getFormat() === Image::FORMAT_JPEG)
			{
				$exifData = $image->getExifData();
				if (isset($exifData['Orientation']) && $exifData['Orientation'] >= 5 && $exifData['Orientation'] <= 8)
				{
					[$width, $height] = [$height, $width];
				}
			}

			if (!$this->configuration->shouldTreatOversizeImageAsFile())
			{
				$fileData->setWidth($width);
				$fileData->setHeight($height);

				$validationResult = $this->configuration->validateImage($fileData);
				if (!$validationResult->isSuccess())
				{
					return $result->addErrors($validationResult->getErrors());
				}
			}
		}

		$result->setData(['width' => $width, 'height' => $height]);
		return $result;
	}

	public function validateFileData(FileData $fileData): Result
	{
		$result = new Result();

		if (in_array(mb_strtolower($fileData->getName()), $this->configuration->getIgnoredFileNames()))
		{
			return $result->addError(new UploaderError(UploaderError::FILE_NAME_NOT_ALLOWED));
		}

		if ($this->configuration->getMaxFileSize() !== null && $fileData->getSize() > $this->configuration->getMaxFileSize())
		{
			return $result->addError(
				new UploaderError(
					UploaderError::MAX_FILE_SIZE_EXCEEDED,
					[
						'maxFileSize' => \CFile::formatSize($this->configuration->getMaxFileSize()),
						'maxFileSizeInBytes' => $this->configuration->getMaxFileSize(),
					]
				)
			);
		}

		if ($fileData->getSize() < $this->configuration->getMinFileSize())
		{
			return $result->addError(
				new UploaderError(
					UploaderError::MIN_FILE_SIZE_EXCEEDED,
					[
						'minFileSize' => \CFile::formatSize($this->configuration->getMinFileSize()),
						'minFileSizeInBytes' => $this->configuration->getMinFileSize(),
					]
				)
			);
		}

		if (!$this->validateFileType($fileData->getName(), $fileData->getContentType(), $this->configuration->getAcceptedFileTypes()))
		{
			return $result->addError(new UploaderError(UploaderError::FILE_TYPE_NOT_ALLOWED));
		}

		return $result;
	}

	private function validateFileType(string $filename, string $mimeType, array $fileTypes): bool
	{
		if (count($fileTypes) === 0)
		{
			return true;
		}

		$baseMimeType = preg_replace(self::BASE_MIME_TYPE_REGEX, '', $mimeType);

		foreach ($fileTypes as $type)
		{
			if (!is_string($type) || mb_strlen($type) === 0)
			{
				continue;
			}

			$type = mb_strtolower(trim($type));
			if ($type[0] === '.') // extension case
			{
				$filename = mb_strtolower($filename);
				$offset = mb_strlen($filename) - mb_strlen($type);
				if (mb_strpos($filename, $type, $offset) !== false)
				{
					return true;
				}
			}
			elseif (preg_match('/\/\*$/', $type)) // image/* mime type case
			{
				if ($baseMimeType === preg_replace(self::BASE_MIME_TYPE_REGEX, '', $type))
				{
					return true;
				}
			}
			elseif ($mimeType === $type)
			{
				return true;
			}
		}

		return false;
	}
}