<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Tasks\Onboarding\Counter\OnboardingCounterService;

/**
 * SC-001: deadline value banner for the "new user" segment.
 *
 * Shown while the user belongs to the new-user segment and has dismissed the
 * banner fewer than MAX_CLOSE_COUNT times. Dismissals are counted via
 * close-count semantics (not the boolean is_viewed flag).
 */
class DeadlineBannerPromotion extends AbstractPromotion
{
	private const MAX_CLOSE_COUNT = 2;

	/**
	 * Reaching this count of created tasks without a deadline is the SC-001
	 * auto-hide condition, not the show condition: the banner stops showing once
	 * the user has created >= 3 such tasks (PRD AC-004). shouldShow() therefore
	 * displays the banner only while the counter is still below this threshold.
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
		return PromotionType::DEADLINE_BANNER;
	}

	/**
	 * Not a server-side feature gate. The module-wide OnboardingFeature::isAvailable()
	 * check lives higher up the stack in OnboardingBannerProvider; a caller that invokes
	 * shouldShow() directly from a template (as other promotions do) must verify feature
	 * availability itself beforehand.
	 */
	public function shouldShow(int $userId): bool
	{
		if (!$this->newUserSegment->isNewUser($userId))
		{
			return false;
		}

		if ($this->getCloseCount($userId) >= self::MAX_CLOSE_COUNT)
		{
			return false;
		}

		// SC-001 shows while the user is still below the no-deadline threshold;
		// reaching TRIGGER_THRESHOLD is the auto-hide condition (PRD AC-004), so
		// the show predicate is the negation of isTriggerReached().
		return !$this->isTriggerReached($userId);
	}

	public function setViewed(int $userId): bool
	{
		return $this->incrementCloseCount($userId);
	}

	protected function getCloseCountLimit(): int
	{
		return self::MAX_CLOSE_COUNT;
	}

	/**
	 * True once the user has created TRIGGER_THRESHOLD tasks without a deadline.
	 * For SC-001 this is the auto-hide condition, not the show condition: the
	 * banner keeps showing while the user has NOT yet created 3 such tasks and
	 * disappears once the counter reaches the threshold (PRD AC-004). Hence
	 * shouldShow() returns the negation of this predicate, which matches the
	 * client predicate (closeCount < 2 && noDeadlineCount < 3). The counter is
	 * reset to zero as soon as a task with a deadline is created.
	 *
	 * Note: this is a coarse server-side gate only. Per PRD (AC-001/AC-004)
	 * the exact SC-001 display logic (show on deadline removal, auto-hide
	 * rules) lives on the frontend, driven by the raw counters delivered via
	 * Core.getParams().onboardingBanners (DTO-01).
	 */
	protected function isTriggerReached(int $userId): bool
	{
		return $this->counterService->getNoDeadlineCount($userId) >= self::TRIGGER_THRESHOLD;
	}
}
