<?php

namespace Bitrix\Transformer\Command;

use Bitrix\Main\IO\File;
use Bitrix\Transformer\Command;
use Bitrix\Transformer\Log;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class ResultFileResolver
{
	private LoggerInterface $logger;
	private CloudResultStorage $cloudStorage;
	private ?string $localRoot;

	public function __construct(
		?LoggerInterface $logger = null,
		?CloudResultStorage $cloudStorage = null,
		?string $localRoot = null,
	)
	{
		$this->logger = $logger ?? Log::logger();
		$this->cloudStorage = $cloudStorage ?? new CloudResultStorage();
		$this->localRoot = $localRoot;
	}

	/** @return array<string, ResultFile> */
	public function resolveAll(Command $command, mixed $files, string $branch): array
	{
		if (!is_array($files))
		{
			return [];
		}

		$area = new UploadArea($command, $this->localRoot, $this->cloudStorage);
		$resolved = [];
		foreach ($files as $format => $name)
		{
			try
			{
				$format = (string)$format;
				if (is_string($name) && $area->ownsLocalFile($name, $format))
				{
					$resolved[$format] = ResultFile::local(new File($name));
					continue;
				}

				if (is_string($name) && $area->ownsCloudName($name, $format))
				{
					$file = $this->cloudStorage->find($name);
					if ($file !== null)
					{
						$resolved[$format] = $file;
						continue;
					}
				}
			}
			catch (\Throwable)
			{
			}

			$this->logRejection($command, $branch, $format, $name);
		}

		return $resolved;
	}

	public function isUploadAllowed(Command $command, mixed $name, int $bucketId, string $branch = 'upload_part'): bool
	{
		$format = null;
		try
		{
			$format = is_string($name) ? pathinfo($name, PATHINFO_EXTENSION) : null;
			$area = new UploadArea($command, $this->localRoot, $this->cloudStorage);
			$allowed = $bucketId === 0
				? $area->ownsLocalName($name)
				: ($bucketId > 0 && $area->ownsCloudName($name))
			;
			if ($allowed)
			{
				return true;
			}
		}
		catch (\Throwable)
		{
		}

		$this->logRejection($command, $branch, $format, $name);
		return false;
	}

	private function logRejection(Command $command, string $branch, ?string $format, mixed $name): void
	{
		try
		{
			$this->logger->error('Result file rejected', [
				'guid' => $command->getGuid(),
				'branch' => $branch,
				'format' => $format,
				'name' => is_string($name) ? mb_substr($name, 0, 255) : get_debug_type($name),
			]);
		}
		catch (\Throwable)
		{
		}
	}
}
