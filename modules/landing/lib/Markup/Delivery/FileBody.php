<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Delivery;

/**
 * Body of a stored package file, for the answers the controller sends itself (no X-Accel-Redirect).
 *
 * Only the requested part is read, so a range request of a video does not load the whole file.
 * A local file is read in place. A file of a cloud storage is downloaded by the core into a
 * temporary file first (CFile::makeFileArray()), so every request still downloads it whole:
 * portals with a cloud storage are expected to serve the binary files by X-Accel-Redirect.
 */
final class FileBody
{
	private function __construct(
		private readonly string $path,
		private readonly int $size
	)
	{
	}

	public static function open(int $fileId): ?self
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$fileArray = \CFile::makeFileArray($fileId);
		$path = is_array($fileArray) ? (string)($fileArray['tmp_name'] ?? '') : '';
		if ($path === '' || !is_file($path) || !is_readable($path))
		{
			return null;
		}

		$size = filesize($path);

		return $size === false ? null : new self($path, $size);
	}

	public function getSize(): int
	{
		return $this->size;
	}

	/**
	 * Reads $length bytes from $start; null when the part is outside the file or cannot be read.
	 */
	public function read(int $start, int $length): ?string
	{
		if ($start < 0 || $length < 0 || $start + $length > $this->size)
		{
			return null;
		}
		if ($length === 0)
		{
			return '';
		}

		$handle = @fopen($this->path, 'rb');
		if ($handle === false)
		{
			return null;
		}

		try
		{
			$content = stream_get_contents($handle, $length, $start);
		}
		finally
		{
			fclose($handle);
		}

		return is_string($content) && strlen($content) === $length ? $content : null;
	}
}
