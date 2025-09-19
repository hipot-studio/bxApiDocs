<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Ai;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\DI\ServiceLocator;

abstract class AiAssistantBaseChat extends GroupChat
{
	protected AiAssistantService $aiAssistantService;

	public function __construct($source = null)
	{
		$this->aiAssistantService = ServiceLocator::getInstance()->get(AiAssistantService::class);
		parent::__construct($source);
	}

	public function add(array $params, ?Context $context = null): Result
	{
		if (!Features::isAiAssistantChatCreationAvailable())
		{
			return (new Result())->addError(new ChatError(ChatError::AI_ASSISTANT_NOT_AVAILABLE));
		}

		$params['USERS'][] = $this->aiAssistantService->getBotId();

		return parent::add($params, $context);
	}

	protected function sendMessageUsersAdd(array $usersToAdd, AddUsersConfig $config): void
	{
		$aiAssistantBotId = $this->aiAssistantService->getBotId();
		unset($usersToAdd[$aiAssistantBotId]);
		parent::sendMessageUsersAdd($usersToAdd, $config);
	}
}
