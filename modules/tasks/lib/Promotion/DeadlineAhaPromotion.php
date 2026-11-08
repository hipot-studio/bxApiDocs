<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Tasks\Onboarding\Counter\OnboardingCounterService;

/**
 * SC-003: deadline "aha" moment for the "new user" segment.
 *
 * Shown once: it relies on the boolean is_viewed flag (inherited from
 * AbstractPromotion), so after setViewed() it is never shown again.
 */
class DeadlineAhaPromotion extends AbstractPromotion
{
	/**
	 * Aha fires on the 3rd day (since registration, not necessarily
	 * consecutive) the user created a task without manually setting a deadline
	 * (PRD AC-013).
	 */
	private const TRIGGER_THRESHOLD = 3;

	public function __construct(
		private readonly OnboardingCounterService $counterService = new OnboardingCounterService(),
		private readonly NewUserSegment $newUserSegment = new NewUserSegment(),
	)
	{
	}

	public function getPromotionType(): PromotionType
	{
		return PromotionType::DEADLINE_AHA;
	}

	/**
	 * Not a server-side feature gate. The module-wide OnboardingFeature::isAvailable()
	 * check lives higher up the stack in OnboardingBannerProvider; a caller that invokes
	 * shouldShow() directly from a template (as other promotions do) must verify feature
	 * availability itself beforehand.
	 */
	public function shouldShow(int $userId): bool
	{
		if ($this->isViewed($userId))
		{
			return false;
		}

		if (!$this->newUserSegment->isNewUser($userId))
		{
			return false;
		}

		return $this->isTriggerReached($userId);
	}

	/**
	 * The aha moment becomes relevant once the user has spent enough distinct
	 * days creating tasks without manually setting a deadline (SC-003).
	 */
	protected function isTriggerReached(int $userId): bool
	{
		return $this->counterService->getDaysWithoutManualDeadlineCount($userId) >= self::TRIGGER_THRESHOLD;
	}
}
