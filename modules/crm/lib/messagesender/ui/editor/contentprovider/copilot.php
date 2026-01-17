<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\AI\SharePrompt\Enums\Category;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

final class Copilot extends ContentProvider
{
	/** @see Category::CRM_MESSAGE_EDITOR */
	private const CATEGORY = 'crm_message_editor';

	/**
	 * @inheritDoc
	 */
	public function getKey(): string
	{
		return 'copilot';
	}

	public function isShown(): bool
	{
		return
			AIManager::isAvailable()
			// to remove dependency
			&& Category::tryFrom(self::CATEGORY) !== null
		;
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::MessageSenderEditor);
	}

	public function isLocked(): bool
	{
		return !$this->isEnabled();
	}

	public function jsonSerialize(): array
	{
		$json = parent::jsonSerialize();

		$json['category'] = self::CATEGORY;
		if ($this->isLocked())
		{
			$json['sliderCode'] = 'limit_copilot_off';
		}

		return $json;
	}
}
