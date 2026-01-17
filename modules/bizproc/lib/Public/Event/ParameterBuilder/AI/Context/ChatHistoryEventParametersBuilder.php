<?php

namespace Bitrix\Bizproc\Public\Event\ParameterBuilder\AI\Context;

use Bitrix\Bizproc\Internal\Integration\AI\Event\ChatHistoryAiEventHandler;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Internal\Integration\ImBot\Builder\ChatHistory;
use Bitrix\Bizproc\Internal\Integration\AI\Event\EventHandler;
use Bitrix\Main\Loader;

class ChatHistoryEventParametersBuilder
{
	public function __construct(
		private readonly array $workflowTriggerData,
	) {}

	public function isSupported(): bool
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return false;
		}

		return ChatHistory::isSupported();
	}

	public function getContextId(): string
	{
		return EventHandler::CONTEXT_ID_CHAT_HISTORY;
	}

	public function getContextModule(): string
	{
		return EventHandler::CONTEXT_MODULE;
	}

	public function getParams(): array
	{
		if (!$this->isSupported())
		{
			return [];
		}

		$botId = (int)($this->workflowTriggerData[BizprocBot::FIELD_BOT_ID] ?? 0);
		$messageId = (int)($this->workflowTriggerData[BizprocBot::FIELD_ID] ?? 0);
		if (!$botId || !$messageId)
		{
			return [];
		}

		return [
			ChatHistoryAiEventHandler::PARAM_MESSAGE_ID => $messageId,
			ChatHistoryAiEventHandler::PARAM_BOT_ID => $botId,
		];
	}
}