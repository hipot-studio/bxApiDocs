<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Error;

use Bitrix\Main\SystemException;

/**
 * Thrown when a non-empty chat composition resolves to no eligible chats —
 * the request is rejected instead of silently clearing the folder.
 */
class IneligibleChatsException extends SystemException
{
}
