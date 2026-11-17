<?php

namespace Bitrix\Transformer\Command;

use Bitrix\Main\Loader;
use Bitrix\Transformer\FileUploader;

/**
 * @internal
 */
class CloudResultStorage
{
	public function find(string $name): ?ResultFile
	{
		try
		{
			if (!Loader::includeModule('clouds'))
			{
				return null;
			}

			$uri = \CCloudStorage::FindFileURIByURN($name, FileUploader::MODULE_ID);
			if (!is_string($uri) || $uri === '')
			{
				return null;
			}

			$bucket = \CCloudStorage::FindBucketByFile($uri);
			return $bucket instanceof \CCloudStorageBucket
				? ResultFile::cloud($bucket, $name, $uri)
				: null
			;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public function isTempName(string $name): bool
	{
		try
		{
			return Loader::includeModule('clouds') && \CCloudTempFile::IsTempFile($name);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
