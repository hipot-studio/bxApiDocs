<?php
namespace Bitrix\ImOpenLines\V2\Message\Modifier;

use Bitrix\Im\V2\Message;

interface MessageModifierInterface
{
	/**
	 * Determines whether the modifier should be applied to the current message.
	 * * @param Message $message
	 * @return bool
	 */
	public function supports(Message $message): bool;

	/**
	 * Modifies the message object (adds parameters, changes text, etc.).
	 * * @param Message $message
	 * @return void
	 */
	public function modify(Message $message): void;
}
