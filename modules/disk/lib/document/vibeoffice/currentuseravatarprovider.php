<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\User;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;

final class CurrentUserAvatarProvider
{
	private const SIZE = 58;
	private const MAX_BYTES = 65536;
	private const MAX_SOURCE_BYTES = 5242880;
	private const MAX_SOURCE_DIMENSION = 4096;
	private const MIME_TYPES = [
		IMAGETYPE_JPEG => 'image/jpeg',
		IMAGETYPE_PNG => 'image/png',
		IMAGETYPE_GIF => 'image/gif',
		IMAGETYPE_WEBP => 'image/webp',
	];

	public function __construct(private readonly ?\Closure $httpClientFactory = null)
	{
	}

	public function getImage(int $userId): ?string
	{
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$user = User::loadById($userId);
			if (!$user || $user->getExternalAuthId() === DocumentEditorUser::EXTERNAL_AUTH_ID)
			{
				return null;
			}

			$file = \CFile::GetFileArray((int)$user->getPersonalPhoto());
			if (!$file)
			{
				return null;
			}

			$bytes = empty($file['HANDLER_ID'])
				? $this->readLocalThumbnail($file)
				: $this->readCloudThumbnail($file)
			;
			$info = $bytes === null ? null : $this->getRasterInfo($bytes, self::SIZE, self::MAX_BYTES);
			if ($info === null)
			{
				return null;
			}

			$image = @imagecreatefromstring($bytes);
			if ($image === false)
			{
				return null;
			}
			imagedestroy($image);

			return 'data:' . self::MIME_TYPES[$info[2]] . ';base64,' . base64_encode($bytes);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function readLocalThumbnail(array $file): ?string
	{
		$uploadDir = trim(Option::get('main', 'upload_dir', 'upload'), '/');
		$source = '/' . $uploadDir . '/' . $file['SUBDIR'] . '/' . $file['FILE_NAME'];
		$path = $this->getLocalPath($source);
		$bytes = $path === null ? null : $this->readFile($path, self::MAX_SOURCE_BYTES);
		$info = $bytes === null
			? null
			: $this->getRasterInfo($bytes, self::MAX_SOURCE_DIMENSION, self::MAX_SOURCE_BYTES)
		;
		if ($info === null)
		{
			return null;
		}

		$file['SRC'] = $source;
		$file['WIDTH'] = $info[0];
		$file['HEIGHT'] = $info[1];
		$thumbnail = \CFile::ResizeImageGet(
			$file,
			['width' => self::SIZE, 'height' => self::SIZE],
			BX_RESIZE_IMAGE_EXACT,
			true,
			false,
			true,
		);
		$path = $this->getLocalPath((string)($thumbnail['src'] ?? ''));

		return $path === null ? null : $this->readFile($path, self::MAX_BYTES);
	}

	private function readCloudThumbnail(array $file): ?string
	{
		$source = (string)($file['SRC'] ?? '');
		$uri = new Uri($source);
		if (
			!in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
			|| $uri->getHost() === ''
			|| $uri->getUser() !== ''
			|| $uri->getPass() !== ''
			|| preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $source)
		)
		{
			return null;
		}

		$http = $this->httpClientFactory === null ? new HttpClient() : ($this->httpClientFactory)();
		$http->setPrivateIp(false);
		$http->setRedirect(false);
		$http->setBodyLengthMax(self::MAX_SOURCE_BYTES);
		$http->setTimeout(2);
		$http->setStreamTimeout(3);
		$bytes = $http->get($source);
		if (!is_string($bytes) || $http->getStatus() !== 200 || $http->getError())
		{
			return null;
		}

		if ($this->getRasterInfo($bytes, self::MAX_SOURCE_DIMENSION, self::MAX_SOURCE_BYTES) === null)
		{
			return null;
		}

		// Cloud resize hooks download originals without these transport limits.
		$sourcePath = false;
		$thumbnailPath = false;
		try
		{
			$sourcePath = tempnam(sys_get_temp_dir(), 'vo-avatar-');
			$thumbnailPath = tempnam(sys_get_temp_dir(), 'vo-avatar-');
			if (
				$sourcePath === false
				|| $thumbnailPath === false
				|| file_put_contents($sourcePath, $bytes) !== strlen($bytes)
			)
			{
				return null;
			}

			if (!\CFile::ResizeImageFile(
				$sourcePath,
				$thumbnailPath,
				['width' => self::SIZE, 'height' => self::SIZE],
				BX_RESIZE_IMAGE_EXACT,
			))
			{
				return null;
			}

			return $this->readFile($thumbnailPath, self::MAX_BYTES);
		}
		finally
		{
			foreach ([$sourcePath, $thumbnailPath] as $path)
			{
				if ($path !== false && is_file($path))
				{
					unlink($path);
				}
			}
		}
	}

	private function getLocalPath(string $source): ?string
	{
		if (
			!str_starts_with($source, '/')
			|| str_starts_with($source, '//')
			|| str_contains($source, '\\')
			|| preg_match('/[\\x00-\\x1f\\x7f?#]/', $source)
			|| preg_match('~(?:^|/)\\.\\.?(?:/|$)~', $source)
		)
		{
			return null;
		}

		$root = realpath(Application::getDocumentRoot());
		$uploadDir = trim(Option::get('main', 'upload_dir', 'upload'), '/');
		$upload = $root === false ? false : realpath($root . '/' . $uploadDir);
		$path = $root === false ? false : realpath($root . $source);
		if (
			$upload === false
			|| $path === false
			|| !str_starts_with($upload, $root . '/')
			|| !str_starts_with($path, $upload . '/')
			|| !is_file($path)
		)
		{
			return null;
		}

		return $path;
	}

	private function readFile(string $path, int $limit): ?string
	{
		$bytes = @file_get_contents($path, false, null, 0, $limit + 1);

		return is_string($bytes) && strlen($bytes) <= $limit ? $bytes : null;
	}

	private function getRasterInfo(string $bytes, int $maxDimension, int $maxBytes): ?array
	{
		if ($bytes === '' || strlen($bytes) > $maxBytes)
		{
			return null;
		}

		$info = @getimagesizefromstring($bytes);
		if (
			$info === false
			|| !isset(self::MIME_TYPES[$info[2]])
			|| $info[0] < 1 || $info[0] > $maxDimension
			|| $info[1] < 1 || $info[1] > $maxDimension
		)
		{
			return null;
		}

		return $info;
	}
}
