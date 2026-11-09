<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Manager;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;

/**
 * Refuses a new operation of the AI sites generation that is not selected.
 *
 * Reads the selection only: the common gate, rights, tariff and page format stay with their own
 * checks. Status and cancel of accepted jobs are not new operations and never ask it.
 */
class AiSiteModeGuard
{
	/**
	 * @param string $expectedMode Manager::AI_SITES_MODE_LEGACY or Manager::AI_SITES_MODE_MARKUP.
	 *
	 * @throws AiSiteModeDisabledException
	 */
	public function assertModeSelected(string $expectedMode): void
	{
		if (!in_array($expectedMode, [Manager::AI_SITES_MODE_LEGACY, Manager::AI_SITES_MODE_MARKUP], true))
		{
			throw new \InvalidArgumentException('Unsupported AI sites mode.');
		}

		if ($this->getSelectedMode() !== $expectedMode)
		{
			throw new AiSiteModeDisabledException();
		}
	}

	protected function getSelectedMode(): string
	{
		return Manager::getAiSitesMode();
	}
}
