<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\Web\Uri;

final class FileDataFactory
{
	use HttpFactoryTrait;
	public static function createFromUrl(string $url, string $filename): Result
	{
		$result = new Result();

		$uri = new Uri($url);
		if (!in_array($uri->getScheme(), ['http', 'https'], true) || empty($uri->getHost()))
		{
			return $result->addError(new Error('Invalid URL'));
		}

		$http = self::getClient();

		if($http->query('HEAD', $url) === false)
		{
			$errorString = implode('; ', array_keys($http->getError()));
			return $result->addError(new Error($errorString));
		}

		$statusCode = $http->getStatus();

		if ($statusCode !== 200)
		{
			return $result->addError(new Error("Service response status code is {$statusCode}"));
		}

		$contentType = $http->getHeaders()->get('Content-Type');
		$mimeType = MimeType::normalize($contentType);
		if (!MimeType::isValid($mimeType))
		{
			return $result->addError(new Error('Invalid content type'));
		}

		$fileData = new FileData($filename, $http->getHeaders()->get('Content-Type'), (int)$http->getHeaders()->get('Content-Length'));

		return $result->setData(['fileData' => $fileData]);
	}

	public static function createFromBase64(?string $data, string $filename): Result
	{
		$result = new Result();
		if (empty($data) || empty($filename))
		{
			return $result->addError(new Error('Empty data or filename'));
		}

		$contentType = MimeType::getFromBase64($data);
		if (!MimeType::isValid($contentType))
		{
			return $result->addError(new Error('Invalid content type'));
		}
		$size = self::getFileSizeFromBase64($data);

		return $result->setData(['fileData' => new FileData($filename, $contentType, $size)]);
	}

	private static function getFileSizeFromBase64($base64String): int
	{
		$cleanBase64 = preg_replace('/^data:.*?;base64,/', '', $base64String);

		$base64Length = strlen($cleanBase64);

		$size = ($base64Length * 3) / 4;

		$paddingCount = substr_count($cleanBase64, '=');
		$size -= $paddingCount;

		return (int)$size;
	}
}