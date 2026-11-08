<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\BaseObject;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

class AddSubFolderCommandHandler
{
	/**
	 * @param AddSubFolderCommand $command
	 * @return Result
	 * @throws ArgumentException
	 */
	public function __invoke(AddSubFolderCommand $command): Result
	{
		$result = new Result();

		if (empty($command->name))
		{
			return $result->addError(new Error('Could not create folder. Name is empty.'));
		}

		$storage = $command->folder->getStorage();

		if (!$storage)
		{
			return $result->addError(new Error('Could not find storage for folder.'));
		}

		$securityContext = $storage->getSecurityContext(
			$command->createdBy,
			$command->shouldCheckUnifiedLink,
		);

		if (!$command->folder->canAdd($securityContext))
		{
			return $result->addError(new Error('Could not create folder. Access denied.'));
		}

		$subFolder = $command->folder->addSubFolder(
			data: [
				'NAME' => $command->name,
				'CREATED_BY' => $command->createdBy,
			],
			generateUniqueName: $command->generateUniqueName,
		);

		if (!$subFolder instanceof Folder)
		{
			$result->addError(new Error('Could not create folder.'));
			$result->addErrors($command->folder->getErrors());

			return $result;
		}

		$result->setData([
			'folder' => $subFolder,
		]);

		return $result;
	}
}
