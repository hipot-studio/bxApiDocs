<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\Error;

class ReadingError extends Error
{
	public const MESSAGE_LIST_EMPTY = 'READ_MESSAGE_LIST_EMPTY_ERROR';
	public const CHAT_NOT_FOUND = 'READ_CHAT_NOT_FOUND_ERROR';
	public const RECENT_ITEM_NOT_FOUND = 'READ_RECENT_ITEM_NOT_FOUND_ERROR';
	public const CHAT_NOT_READABLE = 'READ_CHAT_NOT_READABLE_ERROR';
	public const USER_NOT_IN_CHAT = 'READ_USER_NOT_IN_CHAT_ERROR';
	public const TOO_MANY_CHATS = 'READ_TOO_MANY_CHATS_ERROR';
	public const WRONG_CHAT_TYPE = 'READ_WRONG_CHAT_TYPE_ERROR';
	public const USER_ID_EMPTY = 'READ_USER_ID_EMPTY_ERROR';
	public const USER_NOT_FOUND = 'READ_USER_NOT_FOUND_ERROR';

	public function __construct(string $code)
	{
		parent::__construct($code);
	}

	protected function loadErrorMessage($code, $replacements): string
	{
		return '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return '';
	}
}
