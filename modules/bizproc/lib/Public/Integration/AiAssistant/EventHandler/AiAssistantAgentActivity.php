<?php

namespace Bitrix\Bizproc\Public\Integration\AiAssistant\EventHandler;

use Bitrix\Bizproc\Public\Integration\AI\Service\ChatHistoryService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class AiAssistantAgentActivity
{
	public static function onCollectCustomContext(Event $event): EventResult
	{
		$triggerEventData = $event->getParameter('triggerEventData');
		if (empty($triggerEventData))
		{
			return new EventResult(EventResult::ERROR);
		}

		$salt = $event->getParameter('salt');
		$salt = is_numeric($salt) ? (int)$salt : 0;

		$usePseudonymizer = $event->getParameter('usePseudonymizer') === true;

		$chatHistory = ServiceLocator::getInstance()
			->get(ChatHistoryService::class)
			?->setUsePseudonymizer($usePseudonymizer)
			->getByWorkflowTriggerData($triggerEventData, $salt)
		;

		if (empty($chatHistory))
		{
			return new EventResult(EventResult::ERROR);
		}

		return new EventResult(EventResult::SUCCESS, [
			'chatHistory' => $chatHistory,
		]);
	}
}
