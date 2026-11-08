<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Avatar;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\File\Image;
use Bitrix\Main\Result;

final class AvatarImageProcessor
{
	public const MAX_FILE_SIZE = 5_242_880;
	public const MAX_DIMENSION = 4096;
	public const MAX_PIXEL_COUNT = 4_194_304;
	public const TARGET_DIMENSION = 512;

	public const ERROR_UPLOAD_INVALID = 'MAIL_AVATAR_UPLOAD_INVALID';
	public const ERROR_FORMAT_INVALID = 'MAIL_AVATAR_FORMAT_INVALID';
	public const ERROR_IMAGE_INVALID = 'MAIL_AVATAR_IMAGE_INVALID';
	public const ERROR_LIMIT_EXCEEDED = 'MAIL_AVATAR_LIMIT_EXCEEDED';
	public const ERROR_SAVE_FAILED = 'MAIL_AVATAR_SAVE_FAILED';

	private \Closure $imageFactory;

	public function __construct(?\Closure $imageFactory = null)
	{
		$this->imageFactory = $imageFactory ?? static fn(string $path): Image => new Image($path);
	}

	public static function findDimensionError(int $width, int $height): ?string
	{
		if ($width <= 0 || $height <= 0)
		{
			return self::ERROR_IMAGE_INVALID;
		}

		if (
			$width > self::MAX_DIMENSION
			|| $height > self::MAX_DIMENSION
			|| $width > intdiv(self::MAX_PIXEL_COUNT, $height)
		)
		{
			return self::ERROR_LIMIT_EXCEEDED;
		}

		return null;
	}

	public function process(array $file): Result
	{
		$content = $this->readContent($file);
		if ($content === null || $content === '')
		{
			return $this->failure(self::ERROR_UPLOAD_INVALID);
		}
		if (strlen($content) > self::MAX_FILE_SIZE)
		{
			return $this->failure(self::ERROR_LIMIT_EXCEEDED);
		}
		if (!str_starts_with($content, "\xFF\xD8\xFF") && !str_starts_with($content, "\x89PNG\r\n\x1A\n"))
		{
			return $this->failure(self::ERROR_FORMAT_INVALID);
		}

		$sourcePath = false;
		$destinationPath = false;
		$image = null;
		try
		{
			$sourcePath = tempnam(sys_get_temp_dir(), 'mail-avatar-source-');
			$destinationPath = tempnam(sys_get_temp_dir(), 'mail-avatar-png-');
			if (
				$sourcePath === false
				|| $destinationPath === false
				|| file_put_contents($sourcePath, $content) !== strlen($content)
			)
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}
			unset($content);

			$image = ($this->imageFactory)($sourcePath);
			$info = $this->readImageInfo($image);
			if ($info === null)
			{
				return $this->failure(self::ERROR_IMAGE_INVALID);
			}
			if (!in_array($info->getFormat(), [Image::FORMAT_JPEG, Image::FORMAT_PNG], true))
			{
				return $this->failure(self::ERROR_FORMAT_INVALID);
			}

			$dimensionError = self::findDimensionError((int)$info->getWidth(), (int)$info->getHeight());
			if ($dimensionError !== null)
			{
				return $this->failure($dimensionError);
			}
			if (!$this->loadImage($image))
			{
				return $this->failure(self::ERROR_IMAGE_INVALID);
			}

			$orientation = $info->getFormat() === Image::FORMAT_JPEG
				? (int)($image->getExifData()['Orientation'] ?? 1)
				: 1
			;
			if ($orientation >= 2 && $orientation <= 8 && !$image->autoRotate($orientation))
			{
				return $this->failure(self::ERROR_IMAGE_INVALID);
			}

			$source = $image->getDimensions();
			$destination = new Image\Rectangle(self::TARGET_DIMENSION, self::TARGET_DIMENSION);
			if ($source->resize($destination, Image::RESIZE_PROPORTIONAL) && !$image->resize($source, $destination))
			{
				return $this->failure(self::ERROR_IMAGE_INVALID);
			}
			if (!$image->saveAs($destinationPath, 95, Image::FORMAT_PNG))
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}

			$normalizedContent = file_get_contents($destinationPath);
			if ($normalizedContent === false || $normalizedContent === '')
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}

			return (new Result())->setData([
				'file' => [
					'name' => 'avatar.png',
					'type' => 'image/png',
					'size' => strlen($normalizedContent),
					'content' => $normalizedContent,
				],
			]);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_SAVE_FAILED);
		}
		finally
		{
			try
			{
				$image?->clear();
			}
			finally
			{
				foreach ([$sourcePath, $destinationPath] as $path)
				{
					if (is_string($path) && is_file($path))
					{
						@unlink($path);
					}
				}
			}
		}
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

			return is_string($content) ? $content : null;
		}
		finally
		{
			fclose($stream);
		}
	}

	private function loadImage(Image $image): bool
	{
		$hasWarning = false;
		set_error_handler(static function () use (&$hasWarning): bool {
			$hasWarning = true;

			return true;
		});
		try
		{
			return $image->load() && !$hasWarning;
		}
		finally
		{
			restore_error_handler();
		}
	}

	private function readImageInfo(Image $image): ?Image\Info
	{
		set_error_handler(static fn(): bool => true);
		try
		{
			return $image->getInfo();
		}
		finally
		{
			restore_error_handler();
		}
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
