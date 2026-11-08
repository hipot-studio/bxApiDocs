<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Send;

use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;

final class DiskFileAccessValidator
{
	public function __construct(private readonly int $actorUserId)
	{
	}

	/**
	 * @param int[] $diskFileIds
	 */
	public function validate(array $diskFileIds): Result
	{
		$result = new Result();

		if ($this->actorUserId <= 0)
		{
			return $result->addError($this->createAccessError());
		}

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new FileError(
				FileError::DISK_NOT_INSTALLED,
				'Module "disk" is not installed.',
			));
		}

		foreach ($diskFileIds as $diskFileId)
		{
			if (\CIMDisk::getFileModel((int)$diskFileId, $this->actorUserId) === null)
			{
				return $result->addError($this->createAccessError());
			}
		}

		return $result;
	}

	private function createAccessError(): FileError
	{
		return new FileError(FileError::ACCESS_ERROR, "You don't have access to the specified file");
	}
}
