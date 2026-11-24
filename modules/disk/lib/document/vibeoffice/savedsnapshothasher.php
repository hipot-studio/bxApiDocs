<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;

final class SavedSnapshotHasher
{
	public function __construct(private readonly ?\Closure $cloudDownloader = null)
	{
	}

	public function hash(int $fileId): Result
	{
		$target = null;
		try
		{
			$file = $fileId > 0 ? \CFile::GetList([], ['ID' => $fileId])->Fetch() : false;
			if (!$file)
			{
				return $this->failure();
			}
			if ((int)($file['HANDLER_ID'] ?? 0) > 0)
			{
				$target = \CTempFile::GetFileName('vo-snapshot-' . Random::getString(16));
				checkDirPath($target);
				if (!$this->downloadCloudFile($file, $target))
				{
					return $this->failure();
				}
				$path = $target;
			}
			else
			{
				$local = \CFile::MakeFileArray($fileId);
				$path = is_array($local) ? (string)($local['tmp_name'] ?? '') : '';
			}
			$sha256 = is_file($path) && is_readable($path) ? @hash_file('sha256', $path) : false;

			return $sha256 === false ? $this->failure() : (new Result())->setData(['sha256' => $sha256]);
		}
		catch (\Throwable)
		{
			return $this->failure();
		}
		finally
		{
			if ($target !== null && is_file($target))
			{
				unlink($target);
			}
		}
	}

	private function downloadCloudFile(array $file, string $target): bool
	{
		if ($this->cloudDownloader !== null)
		{
			return ($this->cloudDownloader)($file, $target);
		}
		if (!Loader::includeModule('clouds'))
		{
			return false;
		}
		$bucket = new \CCloudStorageBucket((int)$file['HANDLER_ID']);

		return $bucket->Init() && $bucket->DownloadToFile($file, $target);
	}

	private function failure(): Result
	{
		return (new Result())->addError(new Error(
			'Could not read the saved snapshot content.', 'vibeoffice_saved_hash_failed',
		));
	}
}
