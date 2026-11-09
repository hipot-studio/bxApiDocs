<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Landing\Copilot\Manager;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteModeGuard;

/**
 * Selection gate of the legacy tools that start new work (create, change).
 *
 * It answers the selected generation only and is combined with the common
 * AiSiteRuntimeAvailabilityToolGate by the tool itself: the common gate also serves the CRM and
 * status tools and must not learn about the generation.
 */
trait AiSiteLegacyModeToolGate
{
	protected function isAiSiteLegacyModeSelected(): bool
	{
		return Manager::getAiSitesMode() === Manager::AI_SITES_MODE_LEGACY;
	}

	/**
	 * The last server check of a run: a definition cached before the switch still reaches the tool.
	 *
	 * @throws McpException
	 */
	protected function assertAiSiteLegacyModeSelected(): void
	{
		try
		{
			(new AiSiteModeGuard())->assertModeSelected(Manager::AI_SITES_MODE_LEGACY);
		}
		catch (AiSiteModeDisabledException $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
