<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Error;

use Bitrix\Main\SystemException;

/**
 * Thrown when a requested chat composition is rejected while the request is still being bound:
 * it resolves to no eligible chats, or it is larger than the folder can hold. The rejection reason
 * travels as a {@see FolderError} code, so the two cases stay distinguishable for the client.
 */
class IneligibleChatsException extends SystemException
{
	public function __construct(
		string $message,
		private readonly string $errorCode = FolderError::FOLDER_CHAT_NOT_ELIGIBLE,
	)
	{
		parent::__construct($message);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
