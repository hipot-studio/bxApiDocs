<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\BaseObject;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Ui\Text;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CFile;

class CreateByContentCommandHandler
{
	/**
	 * @param CreateByContentCommand $command
	 * @return Result
	 * @throws ArgumentException
	 */
	public function __invoke(CreateByContentCommand $command): Result
	{
		$result = new Result();

		$command->content->registerDelayedDeleteOnShutdown();

		$storage = $command->folder->getStorage();

		if (!$storage)
		{
			return $result->addError(new Error('Could not find storage for folder.'));
		}

		$securityContext = $storage->getSecurityContext(
			user: $command->createdBy,
			shouldCheckUnifiedLink: $command->shouldCheckUnifiedLink,
		);

		if (!$command->folder->canAdd($securityContext))
		{
			return $result->addError(new Error(Loc::getMessage('DISK_COMMAND_CREATE_BY_CONTENT_ERROR_MESSAGE_DENIED')));
		}

		if ($command->content->isCloud() && $command->content->getContentType())
		{
			$file = $this->createCloudFile($command, $result);
		}
		else
		{
			$file = $this->uploadFile($command);
		}

		if (!$file instanceof File)
		{
			if ($result->isSuccess())
			{
				$result->addErrors($command->folder->getErrors());
			}

			return $result;
		}

		if (is_array($command->previewFileData))
		{
			$file->attachImagePreview($command->previewFileData);
		}

		$result->setData([
			'file' => $file,
		]);

		return $result;
	}

	/**
	 * @param CreateByContentCommand $command
	 * @param Result $result
	 * @return File|null
	 * @throws ArgumentException
	 */
	protected function createCloudFile(CreateByContentCommand $command, Result $result): ?File
	{
		$fileId = CFile::saveFile(
			[
				'name' => $command->content->getFilename(),
				'tmp_name' => $command->content->getAbsolutePath(),
				'type' => $command->contentType,
				'width' => $command->content->getWidth(),
				'height' => $command->content->getHeight(),
				'MODULE_ID' => Driver::INTERNAL_MODULE_ID,
			],
			Driver::INTERNAL_MODULE_ID,
			true,
			true,
		);

		if (!$fileId)
		{
			$result->addError(new Error('Could not save file data by \CFile::saveFile'));

			return null;
		}

		$filename = Text::correctFilename($command->filename);

		return $command->folder->addFile(
			data: [
				'NAME' => $filename,
				'FILE_ID' => $fileId,
				'SIZE' => $command->content->getSize(),
				'CREATED_BY' => $command->createdBy,
			],
			generateUniqueName: $command->generateUniqueName,
		);
	}

	/**
	 * @param CreateByContentCommand $command
	 * @return File|null
	 * @throws ArgumentException
	 */
	protected function uploadFile(CreateByContentCommand $command): ?File
	{
		$fileArray = CFile::makeFileArray($command->content->getAbsolutePath());
		$fileArray['type'] = $command->contentType;
		$fileArray['name'] = $command->filename;

		return $command->folder->uploadFile(
			fileArray: $fileArray,
			data: [
				'NAME' => $command->filename,
				'CREATED_BY' => $command->createdBy,
			],
			generateUniqueName: $command->generateUniqueName,
		);
	}
}
