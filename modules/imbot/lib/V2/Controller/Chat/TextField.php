<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckEntityAccess;
use Bitrix\Imbot\V2\Controller\BotController;

class TextField extends BotController
{
	public function configureActions(): array
	{
		return [
			'enabled' => [
				'+prefilters' => [
					new CheckEntityAccess(),
				],
			],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.TextField.enabled
	 */
	public function enabledAction(
		Chat $chat,
		$enabled = 'Y',
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$chat->getTextFieldEnabled()->set(self::normalizeBooleanVariable($enabled));

		return ['result' => true];
	}
}
