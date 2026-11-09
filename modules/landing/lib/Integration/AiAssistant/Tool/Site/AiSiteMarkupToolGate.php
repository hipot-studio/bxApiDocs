<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Landing\Copilot\Manager;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteModeGuard;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;

/**
 * Runtime gate of the markup (custom code) tool family. The tools stay in the full
 * registry, but they are invisible and non-runnable while the common AI sites product
 * is unavailable or, for the tools that start new work, while markup is not the selected generation.
 *
 * The groups only describe what a tool serves: the create, change and read groups are new
 * operations of the markup generation, the job group polls or cancels an already accepted job
 * and therefore depends neither on the selection nor on the tariff. The job itself is checked by the handler.
 */
trait AiSiteMarkupToolGate
{
	public const MARKUP_TOOL_GROUP_CREATE = 'create';
	public const MARKUP_TOOL_GROUP_CHANGE = 'change';
	public const MARKUP_TOOL_GROUP_READ = 'read';
	public const MARKUP_TOOL_GROUP_JOB = 'job';

	public function canList(int $userId): bool
	{
		return parent::canList($userId) && $this->isAiSiteMarkupRuntimeAvailable();
	}

	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && $this->isAiSiteMarkupRuntimeAvailable();
	}

	protected function isAiSiteMarkupRuntimeAvailable(): bool
	{
		if ($this->isAcceptedMarkupJobTool())
		{
			// An accepted job is only read or cancelled: the tariff gate guards launches, and without
			// a tariff it closes as soon as the first free job itself is accepted.
			return $this->isAiSitesReleaseOptionEnabled()
				&& $this->getAiSiteRuntimeAvailabilityGuard()->checkStatusReadProductAvailability()->isAvailable()
			;
		}

		return $this->isAiSitesMarkupOptionEnabled()
			&& $this->isAiSitesReleaseOptionEnabled()
			&& $this->getAiSiteRuntimeAvailabilityGuard()->checkProductAvailability()->isAvailable()
		;
	}

	/**
	 * @return list<string>
	 */
	protected function getMarkupToolGroups(): array
	{
		return [self::MARKUP_TOOL_GROUP_READ];
	}

	protected function isAcceptedMarkupJobTool(): bool
	{
		return $this->getMarkupToolGroups() === [self::MARKUP_TOOL_GROUP_JOB];
	}

	/**
	 * The last server check of a run: a definition cached before the switch still reaches the tool.
	 *
	 * @throws McpException
	 */
	protected function assertAiSiteMarkupModeSelected(): void
	{
		try
		{
			(new AiSiteModeGuard())->assertModeSelected(Manager::AI_SITES_MODE_MARKUP);
		}
		catch (AiSiteModeDisabledException $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}

	protected function isAiSitesReleaseOptionEnabled(): bool
	{
		return Manager::isAiSitesEnabled();
	}

	protected function isAiSitesMarkupOptionEnabled(): bool
	{
		return Manager::getAiSitesMode() === Manager::AI_SITES_MODE_MARKUP;
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}
}
