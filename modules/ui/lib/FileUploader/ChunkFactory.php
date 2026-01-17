<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\IO\File;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Encoding;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\Web\Uri;

final class ChunkFactory
{
	use HttpFactoryTrait;

	public static function createFromRequest(HttpRequest $request): Result
	{
		$result = new Result();

		$contentType = (string)$request->getHeader('Content-Type');
		$fileMimeType = MimeType::normalize($contentType);
		if (!MimeType::isValid($fileMimeType))
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CONTENT_TYPE));
		}

		$contentLength = $request->getHeader('Content-Length');
		if ($contentLength === null)
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CONTENT_LENGTH));
		}

		$contentLength = (int)$contentLength;
		$filename = self::normalizeFilename((string)$request->getHeader('X-Upload-Content-Name'));
		if (empty($filename))
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CONTENT_NAME));
		}

		if (!self::isValidFilename($filename))
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_FILENAME));
		}

		$contentRangeResult = self::getContentRange($request);
		if (!$contentRangeResult->isSuccess())
		{
			return $result->addErrors($contentRangeResult->getErrors());
		}

		$file = self::getFileFromHttpInput();
		$contentRange = $contentRangeResult->getData();
		$rangeChunkSize = empty($contentRange) ? 0 : ($contentRange['endRange'] - $contentRange['startRange'] + 1);

		if ($rangeChunkSize && $contentLength !== $rangeChunkSize)
		{
			return $result->addError(new UploaderError(
				UploaderError::INVALID_RANGE_SIZE,
				[
					'rangeChunkSize' => $rangeChunkSize,
					'contentLength' => $contentLength,
				]
			));
		}

		$chunk = new Chunk($file);
		if ($chunk->getSize() !== $contentLength)
		{
			return $result->addError(new UploaderError(
				UploaderError::INVALID_CHUNK_SIZE,
				[
					'chunkSize' => $chunk->getSize(),
					'contentLength' => $contentLength,
				]
			));
		}

		$chunk->setName($filename);
		$chunk->setType($fileMimeType);

		if (!empty($contentRange))
		{
			$chunk->setStartRange($contentRange['startRange']);
			$chunk->setEndRange($contentRange['endRange']);
			$chunk->setFileSize($contentRange['fileSize']);
		}

		$result->setData(['chunk' => $chunk]);

		return $result;
	}

	// Create chunk from external URL with validation
	public static function createFromUrl(string $url, string $filename): Result
	{
		$result = new Result();

		$fileDataResult = FileDataFactory::createFromUrl($url, $filename);
		if (!$fileDataResult->isSuccess())
		{
			return $result->addErrors($fileDataResult->getErrors());
		}
		/** @var FileData $fileData */
		$fileData = $fileDataResult->getData()['fileData'];
		$fileResult = self::getFileFromDownloadUrl($url);
		if (!$fileResult->isSuccess())
		{
			return $result->addErrors($fileResult->getErrors());
		}

		$data = $fileResult->getData();
		if (!isset($data['file']) || !$data['file'] instanceof File)
		{
			return $result->addError(new Error('File could not be retrieved from the provided URL.'));
		}

		$file = $data['file'];

		$chunk = new Chunk($file);
		$chunk->setName($fileData->getName());
		$chunk->setType($fileData->getContentType());

		return $result->setData(['chunk' => $chunk]);
	}

	// Create chunk from Base64 with validation
	public static function createFromBase64(string $data, string $filename): Result
	{
		$result = new Result();

		$fileDataResult = FileDataFactory::createFromBase64($data, $filename);
		if (!$fileDataResult->isSuccess())
		{
			return $result->addErrors($fileDataResult->getErrors());
		}
		/** @var FileData $fileData */
		$fileData = $fileDataResult->getData()['fileData'];

		$fileResult = self::getFileFromBase64($data);
		if (!$fileResult->isSuccess())
		{
			return $result->addErrors($fileResult->getErrors());
		}
		/** @var File $file */
		$file = $fileResult->getData()['file'];

		$chunk = new Chunk($file);
		$chunk->setName($fileData->getName());
		$chunk->setType($fileData->getContentType());

		return $result->setData(['chunk' => $chunk]);
	}

	private static function getContentRange(HttpRequest $request): Result
	{
		$contentRange = $request->getHeader('Content-Range');
		if ($contentRange === null)
		{
			return new Result();
		}

		$result = new Result();
		if (!preg_match('/(\d+)-(\d+)\/(\d+)$/', $contentRange, $match))
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CONTENT_RANGE));
		}

		[$startRange, $endRange, $fileSize] = [(int)$match[1], (int)$match[2], (int)$match[3]];

		if ($startRange > $endRange)
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CONTENT_RANGE));
		}

		if ($fileSize <= $endRange)
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CONTENT_RANGE));
		}

		$result->setData([
			'startRange' => $startRange,
			'endRange' => $endRange,
			'fileSize' => $fileSize,
		]);

		return $result;
	}

	private static function normalizeFilename(string $filename): string
	{
		$filename = urldecode($filename);
		$filename = Encoding::convertEncodingToCurrent($filename);

		return \getFileName($filename);
	}

	private static function getFileFromHttpInput(): File
	{
		// This file will be automatically removed on shutdown
		$tmpFilePath = TempFile::generateLocalTempFile();
		$file = new File($tmpFilePath);
		$file->putContents(HttpRequest::getInput());

		return $file;
	}

	private static function isValidFilename(string $filename): bool
	{
		if (mb_strlen($filename) > 255)
		{
			return false;
		}

		if (mb_strpos($filename, '\0') !== false)
		{
			return false;
		}

		return true;
	}

	private static function getFileFromDownloadUrl(string $downloadUrl): Result
	{
		$result = new Result();

		$uri = new Uri($downloadUrl);
		if (!in_array($uri->getScheme(), ['http', 'https'], true) || empty($uri->getHost()))
		{
			return $result->addError(new Error('Invalid URL'));
		}

		$tmpFilePath = TempFile::generateLocalTempFile();
		$file = new File($tmpFilePath);
		$handler = $file->open("w+");

		$http = self::getClient();
		$http->setPrivateIp(false);
		$http->setOutputStream($handler);
		$http->query('GET', $downloadUrl);

		$statusCode = $http->getStatus();
		if ($statusCode !== 200)
		{
			$file->close();
			$file->delete();

			return $result->addError(new Error("Service response status code is {$statusCode}"));
		}

		$http->getResult();
		$file->close();

		return $result->setData(['file' => $file]);
	}

	private static function getFileFromBase64(string $base64): Result
	{
		$result = new Result();
		$tmpFilePath = TempFile::generateLocalTempFile();
		$file = new File($tmpFilePath);

		$cleanBase64 = preg_replace('/^data:.*?;base64,/', '', $base64);
		$data = base64_decode($cleanBase64);

		if ($data === false)
		{
			return $result->addError(new Error('Invalid base64 data'));
		}

		$file->putContents($data);

		return $result->setData(['file' => $file]);
	}
}