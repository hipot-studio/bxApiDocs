<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Main\Loader;

class AiSiteChatAvailabilityService
{
	public const ERROR_TRIGGER_INACTIVE = 'trigger_inactive';
	public const ERROR_TRIGGER_UNAVAILABLE = 'trigger_unavailable';
	public const ERROR_ENTITY_REQUIRED = 'entity_required';
	public const ERROR_ENTITY_NOT_SUPPORTED = 'entity_not_supported';
	public const ERROR_FORBIDDEN_FOR_USER = 'forbidden_for_user';
	public const ERROR_NOT_EDITOR_SHELL = 'not_editor_shell';
	public const ERROR_PANEL_DISABLED = 'panel_disabled';
	public const ERROR_PRODUCT_UNAVAILABLE = 'product_unavailable';
	public const ERROR_FEATURE_DISABLED_BY_OPTION = 'feature_disabled_by_option';
	public const ERROR_FEATURE_LIMITED = 'feature_limited';
	public const ERROR_FEATURE_INACTIVE = 'feature_inactive';

	public function checkSitesAiProductAvailability(): AiSiteChatAvailabilityResult
	{
		if (!$this->isLandingCopilotAvailable())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_PRODUCT_UNAVAILABLE);
		}

		if (!$this->isLandingAiSitesEnabled())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_FEATURE_DISABLED_BY_OPTION);
		}

		if (!$this->isLandingCopilotFeatureEnabled())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_FEATURE_LIMITED);
		}

		if (!$this->isLandingCopilotActive())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_FEATURE_INACTIVE);
		}

		return AiSiteChatAvailabilityResult::available();
	}

	public function checkTriggerAvailability(int $bindingId): AiSiteChatAvailabilityResult
	{
		if ($bindingId <= 0)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_ENTITY_REQUIRED);
		}

		if (!$this->isAiAssistantTriggerRuntimeAvailable())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_TRIGGER_UNAVAILABLE);
		}

		try
		{
			return $this->isLandingAiSitesEnabled()
				? AiSiteChatAvailabilityResult::available()
				: AiSiteChatAvailabilityResult::unavailable(self::ERROR_TRIGGER_INACTIVE)
			;
		}
		catch (\Throwable)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_TRIGGER_UNAVAILABLE);
		}
	}

	public function checkSitesAiChatAvailability(int $bindingId): AiSiteChatAvailabilityResult
	{
		$productAvailability = $this->checkSitesAiProductAvailability();
		if (!$productAvailability->isAvailable())
		{
			return $productAvailability;
		}

		return $this->checkTriggerAvailability($bindingId);
	}

	public function isSitesAiChatAvailable(int $bindingId): bool
	{
		return $this->checkSitesAiChatAvailability($bindingId)->isAvailable();
	}

	public function getSitesAiChatUnavailableReason(int $bindingId): ?string
	{
		return $this->checkSitesAiChatAvailability($bindingId)->getReasonCode();
	}

	public function isTriggerAvailable(int $bindingId): bool
	{
		return $this->checkTriggerAvailability($bindingId)->isAvailable();
	}

	public function getTriggerUnavailableReason(int $bindingId): ?string
	{
		return $this->checkTriggerAvailability($bindingId)->getReasonCode();
	}

	public function checkEditorTriggerAvailability(
		int $siteId,
		int $pageId,
		bool $isAiSiteCreated,
		bool $canEditSite,
		bool $isEditorShellContext,
		bool $showAiAssistantPanel,
		int $bindingId,
	): AiSiteChatAvailabilityResult
	{
		if (!$isAiSiteCreated)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_ENTITY_NOT_SUPPORTED);
		}

		if (!$canEditSite)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_FORBIDDEN_FOR_USER);
		}

		if (!$isEditorShellContext)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_NOT_EDITOR_SHELL);
		}

		if (!$showAiAssistantPanel)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_PANEL_DISABLED);
		}

		if ($siteId <= 0 || $pageId <= 0)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_ENTITY_REQUIRED);
		}

		$productAvailability = $this->checkSitesAiProductAvailability();
		if (!$productAvailability->isAvailable())
		{
			return $productAvailability;
		}

		return $this->checkTriggerAvailability($bindingId);
	}

	public function isEditorTriggerAvailable(
		int $siteId,
		int $pageId,
		bool $isAiSiteCreated,
		bool $canEditSite,
		bool $isEditorShellContext,
		bool $showAiAssistantPanel,
		int $bindingId,
	): bool
	{
		return $this->checkEditorTriggerAvailability(
			$siteId,
			$pageId,
			$isAiSiteCreated,
			$canEditSite,
			$isEditorShellContext,
			$showAiAssistantPanel,
			$bindingId,
		)->isAvailable();
	}

	public function getEditorTriggerUnavailableReason(
		int $siteId,
		int $pageId,
		bool $isAiSiteCreated,
		bool $canEditSite,
		bool $isEditorShellContext,
		bool $showAiAssistantPanel,
		int $bindingId,
	): ?string
	{
		return $this->checkEditorTriggerAvailability(
			$siteId,
			$pageId,
			$isAiSiteCreated,
			$canEditSite,
			$isEditorShellContext,
			$showAiAssistantPanel,
			$bindingId,
		)->getReasonCode();
	}

	protected function isAiAssistantTriggerRuntimeAvailable(): bool
	{
		return Loader::includeModule('aiassistant')
			&& class_exists(\Bitrix\AiAssistant\Controller\Trigger::class)
			&& class_exists(\Bitrix\AiAssistant\Trigger\TriggerRegistry::class)
		;
	}

	protected function isLandingCopilotAvailable(): bool
	{
		return CopilotManager::isAvailable();
	}

	protected function isLandingAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	protected function isLandingCopilotFeatureEnabled(): bool
	{
		return CopilotManager::isFeatureEnabled();
	}

	protected function isLandingCopilotActive(): bool
	{
		return CopilotManager::isActive();
	}
}
