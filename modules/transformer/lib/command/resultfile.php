<?php

namespace Bitrix\Transformer\Command;

use Bitrix\Main\IO;

/**
 * @internal
 */
final class ResultFile
{
	private function __construct(
		private ?IO\File $localFile,
		private ?\CCloudStorageBucket $bucket,
		private string $name,
		private string $absolutePath,
	)
	{
	}

	public static function local(IO\File $file): self
	{
		return new self($file, null, $file->getPath(), $file->getPath());
	}

	public static function cloud(\CCloudStorageBucket $bucket, string $name, string $uri): self
	{
		return new self(null, $bucket, $name, $uri);
	}

	public function getAbsolutePath(): string
	{
		return $this->absolutePath;
	}

	public function delete(): bool
	{
		try
		{
			if ($this->localFile !== null)
			{
				return $this->localFile->delete();
			}

			return $this->bucket !== null && $this->bucket->DeleteFile($this->name);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
