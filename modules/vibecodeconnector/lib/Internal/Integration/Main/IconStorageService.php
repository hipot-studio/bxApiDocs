<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use Bitrix\Main\File\Image;
use Bitrix\Main\IO\File;
use Bitrix\Main\Web\HttpClient;
use CFile;

/**
 * Downloads an icon from a remote URL and stores it in b_file (\CFile::SaveFile).
 */
class IconStorageService
{
	public const MAX_BYTES = 5 * 1024 * 1024;
	public const MAX_SIDE = 256;

	private const MODULE_BUCKET = 'vibecodeconnector';
	private const ALLOWED_MIME_PREFIX = 'image/';

	public function __construct(
		private ?HttpClient $httpClient = null,
	) {}

	public function downloadAndSave(string $url): ?int
	{
		$httpClient = $this->httpClient();

		$body = $httpClient->get($url);
		if ($body === false || $httpClient->getStatus() !== 200)
		{
			return null;
		}

		if (strlen($body) > self::MAX_BYTES)
		{
			return null;
		}

		$contentType = strtolower((string)$httpClient->getHeaders()->get('Content-Type'));
		if (!str_starts_with($contentType, self::ALLOWED_MIME_PREFIX) || str_contains($contentType, 'svg'))
		{
			return null;
		}

		$tmp = CFile::GetTempName('', 'icon.tmp');

		// CFile::SaveFile copies the temp file into b_file storage (see main file.php),
		// so the stored file stays valid after the temp file is removed. File::putFileContents
		// creates the upload/tmp subdirectory itself (CFile::GetTempName only returns a path
		// inside a not-yet-existing random dir); the finally block guarantees cleanup on every
		// exit, including a failed write.
		try
		{
			if (File::putFileContents($tmp, $body) === false)
			{
				return null;
			}

			$fileArray = CFile::MakeFileArray($tmp);
			if (!is_array($fileArray))
			{
				return null;
			}

			return $this->saveImageFile($fileArray, shrink: false);
		}
		finally
		{
			File::deleteFile($tmp);
		}
	}

	public function getSupportedImageInfo(string $path): ?Image\Info
	{
		$info = $this->getImageInfo($path);
		if ($info === null || $this->resolveExtension($info) === null)
		{
			return null;
		}

		if (!$info->isSupported())
		{
			return null;
		}

		return $info;
	}

	/**
	 * @param array<string, mixed> $fileArray
	 */
	public function saveImageFile(array $fileArray, bool $shrink = true): ?int
	{
		$prepared = $this->prepareImage($fileArray, $shrink);

		return $prepared === null ? null : $this->store($prepared);
	}

	/**
	 * @param array<string, mixed> $fileArray
	 * @return array{fileId: int, content: string, format: string}|null
	 */
	public function saveImageFileWithContent(array $fileArray): ?array
	{
		$prepared = $this->prepareImage($fileArray, shrink: true);
		if ($prepared === null)
		{
			return null;
		}

		$fileId = $this->store($prepared);
		if ($fileId === null)
		{
			return null;
		}

		$content = File::getFileContents((string)$prepared['fileArray']['tmp_name']);
		if (!is_string($content))
		{
			$this->delete($fileId);

			return null;
		}

		return ['fileId' => $fileId, 'content' => $content, 'format' => $prepared['format']];
	}

	/**
	 * @param array<string, mixed> $fileArray
	 * @return array{fileArray: array<string, mixed>, format: string}|null
	 */
	private function prepareImage(array $fileArray, bool $shrink): ?array
	{
		$info = $this->getImageInfo((string)($fileArray['tmp_name'] ?? ''));
		if ($info === null)
		{
			return null;
		}

		if ($this->resolveExtension($info) === null)
		{
			return null;
		}

		if ($shrink && !$info->isSupported())
		{
			return null;
		}

		if ($shrink && !$this->shrinkToBoundingBox($fileArray, $info))
		{
			return null;
		}

		$storedInfo = $this->getImageInfo((string)($fileArray['tmp_name'] ?? ''));
		$extension = $storedInfo === null ? null : $this->resolveExtension($storedInfo);
		if ($extension === null)
		{
			return null;
		}

		$fileArray['name'] = 'icon.' . $extension;
		$fileArray['MODULE_ID'] = self::MODULE_BUCKET;

		return ['fileArray' => $fileArray, 'format' => $extension];
	}

	/**
	 * @param array{fileArray: array<string, mixed>, format: string} $prepared
	 */
	private function store(array $prepared): ?int
	{
		$fileId = CFile::SaveFile($prepared['fileArray'], self::MODULE_BUCKET);

		return $fileId > 0 ? (int)$fileId : null;
	}

	/**
	 * @param array<string, mixed> $fileArray
	 */
	private function shrinkToBoundingBox(array &$fileArray, Image\Info $info): bool
	{
		if ($info->getWidth() <= self::MAX_SIDE && $info->getHeight() <= self::MAX_SIDE)
		{
			return true;
		}

		return (bool)CFile::ResizeImage(
			$fileArray,
			['width' => self::MAX_SIDE, 'height' => self::MAX_SIDE],
			BX_RESIZE_IMAGE_PROPORTIONAL,
		);
	}

	public function delete(int $fileId): void
	{
		CFile::Delete($fileId);
	}

	public function getPublicUrl(int $fileId): ?string
	{
		$path = CFile::GetPath($fileId);

		return $path ?: null;
	}

	/**
	 * Batch counterpart of getPublicUrl(): resolves public URLs for many file ids
	 * with a single b_file query instead of one lookup per id.
	 *
	 * The URL is built exactly like CFile::GetPath() (CFile::GetFileSRC over the
	 * b_file row), so a list stays consistent with the single-item resolution.
	 *
	 * @param int[] $fileIds
	 * @return array<int, string> map of fileId => public URL; missing files are absent from the map
	 */
	public function getPublicUrls(array $fileIds): array
	{
		$fileIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $fileIds),
			static fn(int $id): bool => $id > 0,
		)));

		if ($fileIds === [])
		{
			return [];
		}

		$urls = [];
		$rows = CFile::GetList([], ['@ID' => $fileIds]);
		while ($row = $rows->Fetch())
		{
			$src = CFile::GetFileSRC($row);
			if ($src)
			{
				$urls[(int)$row['ID']] = $src;
			}
		}

		return $urls;
	}

	private function httpClient(): HttpClient
	{
		return $this->httpClient ??= new HttpClient([
			'redirect' => false,
			'bodyLengthMax' => self::MAX_BYTES,
			'streamTimeout' => 10,
			'socketTimeout' => 10,
		]);
	}

	private function getImageInfo(string $path): ?Image\Info
	{
		$info = (new Image($path))->getInfo();
		if ($info === null || $info->getWidth() <= 0 || $info->getHeight() <= 0)
		{
			return null;
		}

		return $info;
	}

	/**
	 * Maps a decoded image format to a file extension. A format outside the supported
	 * whitelist yields null, which makes downloadAndSave() reject the file instead of
	 * storing it under a guessed extension.
	 */
	private function resolveExtension(Image\Info $info): ?string
	{
		return match ($info->getFormat()) {
			Image::FORMAT_PNG => 'png',
			Image::FORMAT_JPEG => 'jpg',
			Image::FORMAT_GIF => 'gif',
			Image::FORMAT_WEBP => 'webp',
			Image::FORMAT_BMP => 'bmp',
			default => null,
		};
	}
}
