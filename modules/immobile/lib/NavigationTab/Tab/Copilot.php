<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AI\CopilotNameResolver;
use Bitrix\ImMobile\NavigationTab\MessengerComponentTitle;

class Copilot extends BaseRecent
{
	use MessengerComponentTitle;

	public function isAvailable(): bool
	{
		return CopilotChat::isActive();
	}

	public function isPreload(): bool
	{
		return false;
	}

	public function getComponentCode(): string
	{
		return '';
	}

	protected function getParams(): array
	{
		return [];
	}

	protected function getWidgetSettings(): array
	{
		return [
			'useSearch' => true,
			'preload' => $this->isPreload(),
			'titleParams' => [
				'useLargeTitleMode' => true,
				'text' => $this->getTitle(),
			],
		];
	}

	public function getId(): string
	{
		return 'copilot';
	}

	protected function getTabTitle(): ?string
	{
		return CopilotNameResolver::getInstance()->getName();
	}

	protected function getComponentName(): string
	{
		return 'im:copilot-messenger';
	}

	public function getWidgetObjectName(): string
	{
		return 'copilotRecentList';
	}

	protected function isWidgetSupported(): bool
	{
		return true;
	}
}
