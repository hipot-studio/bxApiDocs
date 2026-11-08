<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Error;

use Bitrix\Main\Localization\Loc;

// Registers lang/ru/lib/V2/Folder/Error/FolderError.php: phrases for these codes are looked up by
// Bitrix\Im\V2\Error, and its own lazy-load path points at lang/.../V2/Error.php, not at this file.
Loc::loadMessages(__FILE__);

final class FolderError
{
	public const FOLDER_LIMIT_EXCEEDED = 'FOLDER_LIMIT_EXCEEDED';
	public const FOLDER_CHATS_LIMIT_EXCEEDED = 'FOLDER_CHATS_LIMIT_EXCEEDED';
	public const FOLDER_PINS_LIMIT_EXCEEDED = 'FOLDER_PINS_LIMIT_EXCEEDED';
	public const FOLDER_CHAT_NOT_ELIGIBLE = 'FOLDER_CHAT_NOT_ELIGIBLE';
	public const FOLDER_CHAT_NOT_MEMBER = 'FOLDER_CHAT_NOT_MEMBER';
	public const FOLDER_CHAT_NOT_VISIBLE = 'FOLDER_CHAT_NOT_VISIBLE';
	public const FOLDER_SYSTEM_MUTATION_FORBIDDEN = 'FOLDER_SYSTEM_MUTATION_FORBIDDEN';
	public const FOLDER_ACCESS_DENIED = 'FOLDER_ACCESS_DENIED';
	public const FOLDER_NOT_FOUND = 'FOLDER_NOT_FOUND';
	public const FOLDER_OPERATION_NOT_SUPPORTED = 'FOLDER_OPERATION_NOT_SUPPORTED';
	public const FOLDER_SORT_INVALID = 'FOLDER_SORT_INVALID';

	private function __construct()
	{
	}
}
