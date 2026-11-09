<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Markup\MarkupPage;
use Bitrix\Main\Loader;

/**
 * @internal
 */
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
	public const ERROR_PAGE_MODE_MISMATCH = 'ai_sites_page_mode_mismatch';

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

	public function isAiSiteEditorChatProductAvailable(): bool
	{
		return $this->isLandingCopilotAvailable()
			&& $this->isLandingAiSitesEnabled()
			&& $this->isLandingCopilotActive();
	}

	// Lightweight slice of the product check for read-only (status) scenarios:
	// only the product and rollout option gates, without the tariff/tuning gates
	// that must guard generation launches.
	public function checkSitesAiStatusReadAvailability(): AiSiteChatAvailabilityResult
	{
		if (!$this->isLandingCopilotAvailable())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_PRODUCT_UNAVAILABLE);
		}

		if (!$this->isLandingAiSitesEnabled())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_FEATURE_DISABLED_BY_OPTION);
		}

		return AiSiteChatAvailabilityResult::available();
	}

	public function checkTriggerAvailability(int $bindingId): AiSiteChatAvailabilityResult
	{
		if ($bindingId <= 0)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_ENTITY_REQUIRED);
		}

		return $this->checkTriggerRuntimeAvailability();
	}

	// Render/UI probe: not bound to any entity and not permission-aware.
	// Guard actions on a concrete site with the binding-aware checks instead.
	public function isSitesAiChatPotentiallyAvailable(): bool
	{
		return $this->checkSitesAiProductAvailability()->isAvailable()
			&& $this->checkTriggerRuntimeAvailability()->isAvailable();
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

	/**
	 * Format of an existing AI page against the selected generation. It does not replace the AI origin,
	 * rights and product gates: call it after them, never for an ordinary page or a new draft.
	 */
	public function checkPageModeAvailability(int $pageId): AiSiteChatAvailabilityResult
	{
		if ($pageId <= 0)
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_ENTITY_REQUIRED);
		}

		return $this->checkPageFormatModeAvailability($this->isMarkupLanding($pageId));
	}

	// Same check for a caller that already knows the real format of the page.
	public function checkPageFormatModeAvailability(bool $isMarkupPage): AiSiteChatAvailabilityResult
	{
		$isMarkupSelected = $this->getSelectedAiSitesMode() === CopilotManager::AI_SITES_MODE_MARKUP;

		return $isMarkupPage === $isMarkupSelected
			? AiSiteChatAvailabilityResult::available()
			: AiSiteChatAvailabilityResult::unavailable(self::ERROR_PAGE_MODE_MISMATCH)
		;
	}

	// Single source of truth for the entity-independent part of the trigger gates.
	protected function checkTriggerRuntimeAvailability(): AiSiteChatAvailabilityResult
	{
		if (!$this->isAiAssistantTriggerRuntimeAvailable())
		{
			return AiSiteChatAvailabilityResult::unavailable(self::ERROR_TRIGGER_UNAVAILABLE);
		}

		return $this->isLandingAiSitesEnabled()
			? AiSiteChatAvailabilityResult::available()
			: AiSiteChatAvailabilityResult::unavailable(self::ERROR_TRIGGER_INACTIVE)
		;
	}

	protected function isAiAssistantTriggerRuntimeAvailable(): bool
	{
		return Loader::includeModule('aiassistant')
			&& class_exists(\Bitrix\AiAssistant\Controller\Trigger::class)
			&& class_exists(\Bitrix\AiAssistant\Trigger\TriggerRegistry::class)
		;
	}

	protected function isMarkupLanding(int $landingId): bool
	{
		return MarkupPage::isMarkupLanding($landingId);
	}

	protected function getSelectedAiSitesMode(): string
	{
		return CopilotManager::getAiSitesMode();
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
