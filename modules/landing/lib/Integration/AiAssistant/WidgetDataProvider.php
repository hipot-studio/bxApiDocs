<?php

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Main\Loader;
use Throwable;

final class WidgetDataProvider
{
	public function getData(): array
	{
		return $this->buildData($this->getAiAssistantDialogId());
	}

	public function getDataWithoutRecentDialog(): array
	{
		return $this->buildData('');
	}

	private function buildData(string $dialogId): array
	{
		return [
			'AI_ASSISTANT_DIALOG_ID' => $dialogId,
			'AI_ASSISTANT_CHAT_IS_OPEN' => $this->isAiAssistantChatOpen(),
			'IM_APPLICATION_DATA' => $this->getImApplicationData(),
		];
	}

	public function getAiAssistantDialogId(): string
	{
		if (!Loader::includeModule('im'))
		{
			return '';
		}

		try
		{
			$recent = \Bitrix\Im\Recent::getList(null, [
				'ONLY_COPILOT' => 'Y',
				'LIMIT' => 1,
				'JSON' => 'Y',
				'GET_ORIGINAL_TEXT' => 'Y',
				'SHORT_INFO' => 'Y',
				'WITH_COUNTERS' => 'N',
				'SKIP_OPENLINES' => 'Y',
			]);
		}
		catch (Throwable)
		{
			return '';
		}

		$dialogId = is_array($recent) ? ($recent['items'][0]['id'] ?? '') : '';

		if (!is_string($dialogId) || !preg_match('/^chat\d+$/', $dialogId))
		{
			return '';
		}

		return $dialogId;
	}

	public function getAiAssistantBotId(): int
	{
		return (new WidgetBotResolver())->resolve();
	}

	public function getImApplicationData(): array
	{
		return (new WidgetApplicationDataResolver())->resolve();
	}

	public function isAiAssistantChatOpen(): bool
	{
		return \CUserOptions::GetOption('aiassistant', 'marta_is_open', 'N') === 'Y';
	}
}
