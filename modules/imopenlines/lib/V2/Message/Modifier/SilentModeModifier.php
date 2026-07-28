<?php
namespace Bitrix\ImOpenLines\V2\Message\Modifier;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Chat;
use Bitrix\ImOpenLines\SilentMode\PersonalSilentMode;

class SilentModeModifier implements MessageModifierInterface
{
	private const PARAM_NAME = 'COMPONENT_ID';
	private const PARAM_VALUE = 'HiddenMessage';

	public function supports(Message $message): bool
	{
		$chat = $message->getChat();

		if ($chat->getType() !== Chat::IM_TYPE_OPEN_LINE)
		{
			return false;
		}

		$author = $message->getAuthor();

		if ($author === null || $author->isConnector())
		{
			return false;
		}

		// Hide the message only if its AUTHOR has the personal mode enabled in this chat.
		return PersonalSilentMode::isEnabled($message->getAuthorId(), $message->getChatId());
	}

	public function modify(Message $message): void
	{
		$message->addParam(
			self::PARAM_NAME,
			self::PARAM_VALUE
		);
	}
}