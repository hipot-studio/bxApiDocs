<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\TasksMobile\Feature\ChatFeature;

class Settings extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'isBetaAvailable',
			'isBetaActive',
			'isChatFeatureEnabled',
		];
	}

	public function isBetaAvailableAction(): bool
	{
		return \Bitrix\TasksMobile\Settings::getInstance()->isBetaAvailable();
	}

	public function isBetaActiveAction(): bool
	{
		return \Bitrix\TasksMobile\Settings::getInstance()->isBetaActive();
	}

	public function activateBetaAction(): void
	{
		\Bitrix\TasksMobile\Settings::getInstance()->activateBeta();
	}

	public function deactivateBetaAction(): void
	{
		\Bitrix\TasksMobile\Settings::getInstance()->deactivateBeta();
	}

	/**
	 * @ajaxAction tasksmobile.Settings.isChatFeatureEnabled
	 * @return bool
	 */
	public function isChatFeatureEnabledAction(): bool
	{
		return (new ChatFeature())->isEnabled();
	}
}
