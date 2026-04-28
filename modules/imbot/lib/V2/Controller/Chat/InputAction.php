<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller\Chat;

use Bitrix\Im\Bot;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckEntityAccess;
use Bitrix\Imbot\V2\Controller\BotController;

class InputAction extends BotController
{
	public function configureActions(): array
	{
		return [
			'notify' => [
				'+prefilters' => [
					new CheckEntityAccess(),
				],
			],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.InputAction.notify
	 */
	public function notifyAction(
		Chat $chat,
		?string $statusMessageCode = null,
		?int $duration = null,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		Bot::startWriting(
			['BOT_ID' => $this->getBotId()],
			'chat' . $chat->getChatId(),
			'',
			$statusMessageCode,
			$duration,
		);

		return ['result' => true];
	}
}
