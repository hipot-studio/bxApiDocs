<?php
namespace Bitrix\ImOpenLines\V2\Message\Modifier;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Chat;

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

		$entityData = $chat->getEntityData();
		$silentMode = $entityData['entityData3']['silentMode'] ?? 'N';

		return $silentMode === 'Y';
	}

	public function modify(Message $message): void
	{
		$message->addParam(
			self::PARAM_NAME,
			self::PARAM_VALUE
		);
	}
}