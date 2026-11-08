<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SignatureImageValidator
{
	public const MAX_FILE_SIZE = 2 * 1024 * 1024;
	public const MAX_SIGNATURE_FILE_COUNT = 10;
	public const MAX_SIGNATURE_TOTAL_SIZE = 5 * 1024 * 1024;
	public const MAX_PIXEL_COUNT = 20_000_000;

	public const ERROR_UPLOAD_INVALID = 'SIGNATURE_IMAGE_UPLOAD_INVALID';
	public const ERROR_TOO_LARGE = 'SIGNATURE_IMAGE_TOO_LARGE';
	public const ERROR_UNSUPPORTED_FORMAT = 'SIGNATURE_IMAGE_UNSUPPORTED_FORMAT';
	public const ERROR_EXTENSION_MISMATCH = 'SIGNATURE_IMAGE_EXTENSION_MISMATCH';
	public const ERROR_INVALID_DIMENSIONS = 'SIGNATURE_IMAGE_INVALID_DIMENSIONS';
	public const ERROR_PIXEL_LIMIT_EXCEEDED = 'SIGNATURE_IMAGE_PIXEL_LIMIT_EXCEEDED';
	public const ERROR_COUNT_LIMIT_EXCEEDED = 'SIGNATURE_IMAGE_COUNT_LIMIT_EXCEEDED';
	public const ERROR_TOTAL_SIZE_LIMIT_EXCEEDED = 'SIGNATURE_IMAGE_TOTAL_SIZE_LIMIT_EXCEEDED';

	private const FORMAT_MAP = [
		IMAGETYPE_JPEG => [
			'mime' => 'image/jpeg',
			'extensions' => ['jpg', 'jpeg'],
		],
		IMAGETYPE_PNG => [
			'mime' => 'image/png',
			'extensions' => ['png'],
		],
		IMAGETYPE_GIF => [
			'mime' => 'image/gif',
			'extensions' => ['gif'],
		],
	];

	private const DISPLAY_WIDTHS = [
		'small' => 160,
		'medium' => 320,
		'original' => 600,
	];

	public function validate(array $file): Result
	{
		$name = isset($file['name']) && is_string($file['name']) ? basename($file['name']) : '';
		$declaredMime = isset($file['type']) && is_string($file['type']) ? strtolower(trim($file['type'])) : '';
		if ($name === '' || $declaredMime === '')
		{
			return $this->failure(self::ERROR_UPLOAD_INVALID);
		}

		$content = $this->readContent($file);
		if ($content === null || $content === '')
		{
			return $this->failure(self::ERROR_UPLOAD_INVALID);
		}

		$fileSize = strlen($content);
		if ($fileSize > self::MAX_FILE_SIZE)
		{
			return $this->failure(self::ERROR_TOO_LARGE);
		}

		$imageInfo = $this->readImageInfo($content);
		if (!is_array($imageInfo))
		{
			return $this->failure(self::ERROR_UNSUPPORTED_FORMAT);
		}

		$format = (int)($imageInfo[2] ?? 0);
		if (!isset(self::FORMAT_MAP[$format]))
		{
			return $this->failure(self::ERROR_UNSUPPORTED_FORMAT);
		}

		$formatData = self::FORMAT_MAP[$format];
		if ($declaredMime !== $formatData['mime'])
		{
			return $this->failure(self::ERROR_UNSUPPORTED_FORMAT);
		}

		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
		if (!in_array($extension, $formatData['extensions'], true))
		{
			return $this->failure(self::ERROR_EXTENSION_MISMATCH);
		}

		$width = (int)($imageInfo[0] ?? 0);
		$height = (int)($imageInfo[1] ?? 0);
		if ($width <= 0 || $height <= 0)
		{
			return $this->failure(self::ERROR_INVALID_DIMENSIONS);
		}
		if ($width > intdiv(self::MAX_PIXEL_COUNT, $height))
		{
			return $this->failure(self::ERROR_PIXEL_LIMIT_EXCEEDED);
		}

		$decodedImage = $this->decodeImage($content);
		if ($decodedImage === false)
		{
			return $this->failure(self::ERROR_INVALID_DIMENSIONS);
		}
		imagedestroy($decodedImage);

		$normalizedFile = [
			'name' => $name,
			'type' => $formatData['mime'],
			'size' => $fileSize,
			'content' => $content,
		];

		return (new Result())->setData([
			'file' => $normalizedFile,
			'contentType' => $formatData['mime'],
			'width' => $width,
			'height' => $height,
			'size' => $fileSize,
			'animated' => $format === IMAGETYPE_GIF && $this->countGifFrames($content) > 1,
			'variants' => $this->getDisplayVariants($width),
		]);
	}

	public function validateSignatureLimits(int $uniqueResourceCount, int $totalSize): Result
	{
		if ($uniqueResourceCount < 0 || $totalSize < 0)
		{
			return $this->failure(self::ERROR_UPLOAD_INVALID);
		}
		if ($uniqueResourceCount > self::MAX_SIGNATURE_FILE_COUNT)
		{
			return $this->failure(self::ERROR_COUNT_LIMIT_EXCEEDED);
		}
		if ($totalSize > self::MAX_SIGNATURE_TOTAL_SIZE)
		{
			return $this->failure(self::ERROR_TOTAL_SIZE_LIMIT_EXCEEDED);
		}

		return new Result();
	}

	private function readContent(array $file): ?string
	{
		if (array_key_exists('content', $file))
		{
			return is_string($file['content']) ? $file['content'] : null;
		}

		$path = $file['tmp_name'] ?? null;
		if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path))
		{
			return null;
		}

		$stream = @fopen($path, 'rb');
		if (!is_resource($stream))
		{
			return null;
		}

		try
		{
			$content = stream_get_contents($stream, self::MAX_FILE_SIZE + 1);
		}
		finally
		{
			fclose($stream);
		}

		return is_string($content) ? $content : null;
	}

	private function readImageInfo(string $content): array|false
	{
		set_error_handler(static fn(): bool => true);
		try
		{
			return getimagesizefromstring($content);
		}
		finally
		{
			restore_error_handler();
		}
	}

	private function decodeImage(string $content): \GdImage|false
	{
		if (!function_exists('imagecreatefromstring'))
		{
			return false;
		}

		set_error_handler(static fn(): bool => true);
		try
		{
			return imagecreatefromstring($content);
		}
		finally
		{
			restore_error_handler();
		}
	}

	private function getDisplayVariants(int $width): array
	{
		$variants = [];
		foreach (self::DISPLAY_WIDTHS as $name => $maxWidth)
		{
			$variants[$name] = min($width, $maxWidth);
		}

		return $variants;
	}

	private function countGifFrames(string $content): int
	{
		if (strlen($content) < 13 || !in_array(substr($content, 0, 6), ['GIF87a', 'GIF89a'], true))
		{
			return 0;
		}

		$offset = 13;
		$packed = ord($content[10]);
		if (($packed & 0x80) !== 0)
		{
			$offset += 3 * (2 ** (($packed & 0x07) + 1));
		}

		$frameCount = 0;
		$length = strlen($content);
		while ($offset < $length)
		{
			$marker = ord($content[$offset]);
			$offset++;

			if ($marker === 0x3B)
			{
				break;
			}
			if ($marker === 0x21)
			{
				$offset++;
				$offset = $this->skipGifSubBlocks($content, $offset);
			}
			elseif ($marker === 0x2C)
			{
				if ($offset + 9 > $length)
				{
					break;
				}

				$frameCount++;
				$imagePacked = ord($content[$offset + 8]);
				$offset += 9;
				if (($imagePacked & 0x80) !== 0)
				{
					$offset += 3 * (2 ** (($imagePacked & 0x07) + 1));
				}
				$offset++;
				$offset = $this->skipGifSubBlocks($content, $offset);
			}
			else
			{
				break;
			}
		}

		return $frameCount;
	}

	private function skipGifSubBlocks(string $content, int $offset): int
	{
		$length = strlen($content);
		while ($offset < $length)
		{
			$blockSize = ord($content[$offset]);
			$offset++;
			if ($blockSize === 0)
			{
				break;
			}

			$offset += $blockSize;
		}

		return $offset;
	}

	private function failure(string $code): Result
	{
		$result = new Result();
		$result->addError(new Error($code, $code));

		return $result;
	}
}
