<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Main\Loader;

class ChatbotDeleteService
{
	/**
	 * Unregisters bots by already resolved IDs (see {@see AgentChatbotsExtractor::getCreatedBotIds()}).
	 *
	 * @param array{bizproc?: list<int>, openlines?: list<int>} $botIds
	 */
	public function deleteBots(array $botIds): void
	{
		$bizprocIds = $botIds[AgentChatbotsExtractor::KIND_BIZPROC] ?? [];
		$openLinesIds = $botIds[AgentChatbotsExtractor::KIND_OPENLINES] ?? [];

		if (empty($bizprocIds) && empty($openLinesIds))
		{
			return;
		}

		if (!$this->areBotModulesAvailable())
		{
			return;
		}

		$this->unregisterBots($this->getBizprocBotClass(), $bizprocIds);
		$this->unregisterBots($this->getOpenLinesBotClass(), $openLinesIds);
	}

	/**
	 * @return class-string
	 */
	protected function getBizprocBotClass(): string
	{
		return BizprocBot::class;
	}

	/**
	 * @return class-string
	 */
	protected function getOpenLinesBotClass(): string
	{
		return AgentChatbotsExtractor::OPENLINES_BOT_CLASS;
	}

	protected function areBotModulesAvailable(): bool
	{
		return Loader::includeModule('im') && Loader::includeModule('imbot');
	}

	/**
	 * @param class-string $botClass bot class exposing a static unRegister(int) method
	 * @param list<int> $botIds
	 */
	private function unregisterBots(string $botClass, array $botIds): void
	{
		if (!class_exists($botClass))
		{
			return;
		}

		foreach ($botIds as $botId)
		{
			$id = (int)$botId;
			if ($id > 0)
			{
				$botClass::unRegister($id);
			}
		}
	}
}
