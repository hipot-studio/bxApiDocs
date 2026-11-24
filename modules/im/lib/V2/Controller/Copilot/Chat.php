<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Copilot;

use Bitrix\Im\V2\Analytics\VoiceWidgetLabels;
use Bitrix\Im\V2\Chat\Copilot\KeyedChatService;
use Bitrix\Im\V2\Controller\BaseController;

class Chat extends BaseController
{
	/**
	 * @restMethod im.v2.Copilot.Chat.getOrCreate
	 */
	public function getOrCreateAction(
		string $creationKey,
		?string $copilotMainRole = null,
		?array $copilotAnalytics = null,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();

		$addResult = (new KeyedChatService())->getOrCreate(
			$userId,
			$creationKey,
			$copilotMainRole,
			VoiceWidgetLabels::fromArray($copilotAnalytics),
		);
		if (!$addResult->isSuccess())
		{
			$this->addErrors($addResult->getErrors());

			return null;
		}

		return ['chatId' => $addResult->getChatId()];
	}
}
