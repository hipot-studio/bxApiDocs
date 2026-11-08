<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Name rules of a folder created from the destination picker.
 *
 * Only this surface rejects a name: every other caller of Folder::addSubFolder() keeps taking names as
 * they come. A taken name stays the rule of the name service; the validator only re-codes it, so that
 * the input field tells "this character is not allowed" apart from "this name is already here".
 */
class FolderNameValidator
{
	public const ERROR_NAME_EMPTY = 'name_empty';
	public const ERROR_INVALID_CHARACTERS = 'invalid_characters';
	public const ERROR_NAME_TAKEN = 'name_taken';

	public const FORBIDDEN_CHARACTERS = '\\/:*?"<>|';

	private const ERROR_MESSAGES = [
		self::ERROR_NAME_EMPTY => 'DISK_DESTINATION_FOLDER_NAME_ERROR_EMPTY',
		self::ERROR_INVALID_CHARACTERS => 'DISK_DESTINATION_FOLDER_NAME_ERROR_INVALID_CHARACTERS',
		self::ERROR_NAME_TAKEN => 'DISK_DESTINATION_FOLDER_NAME_ERROR_TAKEN',
	];

	/**
	 * A refusal carries a single error, its code names the reason the field reports to the person.
	 *
	 * @param string $name Name asked for the new folder.
	 * @param int $parentFolderId Id of the folder the new one goes into.
	 * @return Result
	 */
	public function validate(string $name, int $parentFolderId): Result
	{
		$result = new Result();

		if (trim($name) === '')
		{
			return $result->addError($this->createError(self::ERROR_NAME_EMPTY));
		}

		if (strpbrk($name, self::FORBIDDEN_CHARACTERS) !== false)
		{
			return $result->addError($this->createError(self::ERROR_INVALID_CHARACTERS));
		}

		if (!$this->isNameFree($name, $parentFolderId))
		{
			return $result->addError($this->createError(self::ERROR_NAME_TAKEN));
		}

		return $result;
	}

	protected function isNameFree(string $name, int $parentFolderId): bool
	{
		return (new ObjectNameService($name, $parentFolderId, ObjectTable::TYPE_FOLDER))
			->isUniqueName()
			->isSuccess()
		;
	}

	private function createError(string $code): Error
	{
		return new Error((string)Loc::getMessage(self::ERROR_MESSAGES[$code]), $code);
	}
}
