<?php

namespace Bitrix\Transformer\Command;

use Bitrix\Main\IO\Path;
use Bitrix\Transformer\Command;

/**
 * @internal
 */
final class UploadArea
{
	public const FORMAT_PATTERN = '/^[A-Za-z0-9]{1,32}$/D';

	private ?string $localRoot;
	private CloudResultStorage $cloudStorage;

	public function __construct(
		private Command $command,
		?string $localRoot = null,
		?CloudResultStorage $cloudStorage = null,
	)
	{
		try
		{
			$this->localRoot = Path::normalize($localRoot ?? \CTempFile::GetAbsoluteRoot());
		}
		catch (\Throwable)
		{
			$this->localRoot = null;
		}

		$this->cloudStorage = $cloudStorage ?? new CloudResultStorage();
	}

	public function isRequestedFormat(mixed $format): bool
	{
		try
		{
			$params = $this->command->getParams();
			$formats = is_array($params) ? ($params['formats'] ?? []) : [];

			return is_string($format)
				&& preg_match(self::FORMAT_PATTERN, $format) === 1
				&& is_array($formats)
				&& in_array($format, $formats, true)
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function ownsLocalName(mixed $name, ?string $format = null): bool
	{
		try
		{
			if (!$this->ownsName($name, $format) || $this->localRoot === null)
			{
				return false;
			}

			$rootPrefix = rtrim($this->localRoot, '/') . '/';
			if (!str_starts_with($name, $rootPrefix))
			{
				return false;
			}

			$realRoot = $this->realPathWithMissingTail($this->localRoot);
			$realName = $this->realPathWithMissingTail($name);

			return $realRoot !== null
				&& $realName !== null
				&& str_starts_with($realName, rtrim($realRoot, '/') . '/')
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function ownsLocalFile(mixed $name, string $format): bool
	{
		try
		{
			if (!$this->ownsLocalName($name, $format) || !is_file($name))
			{
				return false;
			}

			$realRoot = $this->realPathWithMissingTail($this->localRoot);
			$realFile = realpath($name);

			return $realRoot !== null
				&& $realFile !== false
				&& str_starts_with($realFile, rtrim($realRoot, '/') . '/')
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function ownsCloudName(mixed $name, ?string $format = null): bool
	{
		try
		{
			return $this->ownsName($name, $format)
				&& $this->cloudStorage->isTempName($name)
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function ownsName(mixed $name, ?string $expectedFormat): bool
	{
		if (!is_string($name) || Path::normalize($name) !== $name)
		{
			return false;
		}

		if (basename(dirname($name)) !== 'transformer')
		{
			return false;
		}

		$fileName = basename($name);
		$separator = strrpos($fileName, '.');
		if ($separator === false || substr($fileName, 0, $separator) !== $this->command->getGuid())
		{
			return false;
		}

		$format = substr($fileName, $separator + 1);

		return $this->isRequestedFormat($format)
			&& ($expectedFormat === null || $format === $expectedFormat)
		;
	}

	private function realPathWithMissingTail(string $path): ?string
	{
		$tail = [];
		while (!file_exists($path) && !is_link($path))
		{
			$parent = dirname($path);
			if ($parent === $path)
			{
				return null;
			}

			array_unshift($tail, basename($path));
			$path = $parent;
		}

		$realPath = realpath($path);
		if ($realPath === false)
		{
			return null;
		}

		return $tail === [] ? $realPath : rtrim($realPath, '/') . '/' . implode('/', $tail);
	}
}
