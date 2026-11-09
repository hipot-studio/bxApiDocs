<?php

namespace Bitrix\Landing\Security;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;

/**
 * SSRF-safe download of an external file dictated by semi-trusted input.
 *
 * https only, private and reserved IPs rejected (including after DNS resolution - the
 * check is delegated to HttpClient private IP protection), redirects are not followed,
 * download size and time are limited. The caller verifies the actual content type.
 */
class RemoteFile
{
	private const SOCKET_TIMEOUT = 10;
	private const STREAM_TIMEOUT = 30;

	/**
	 * Downloads the url, returns Result with data ['content' => string].
	 */
	public static function download(string $url, int $sizeLimit): Result
	{
		$result = new Result();

		$uri = new Uri($url);
		if (mb_strtolower($uri->getScheme()) !== 'https')
		{
			return $result->addError(new Error('Only https urls are allowed', 'MARKUP_REMOTE_SCHEME'));
		}

		$httpClient = new HttpClient([
			'redirect' => false,
			'socketTimeout' => self::SOCKET_TIMEOUT,
			'streamTimeout' => self::STREAM_TIMEOUT,
			'bodyLengthMax' => $sizeLimit,
			'privateIp' => false,
		]);

		$content = $httpClient->get($url);
		$status = $httpClient->getStatus();
		if ($content === false || $status !== 200)
		{
			return $result->addError(new Error(
				'Download failed with status ' . $status,
				'MARKUP_REMOTE_FAILED'
			));
		}
		if (strlen($content) > $sizeLimit)
		{
			return $result->addError(new Error('Downloaded file is too big', 'MARKUP_REMOTE_TOO_BIG'));
		}

		$result->setData(['content' => $content]);

		return $result;
	}
}
