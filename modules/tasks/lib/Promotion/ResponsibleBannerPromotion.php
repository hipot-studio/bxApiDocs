<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Tasks\Onboarding\Counter\OnboardingCounterService;

/**
 * SC-002: responsible-change value banner for the "new user" segment.
 *
 * Shown while the user belongs to the new-user segment and has dismissed the
 * banner fewer than MAX_CLOSE_COUNT times. Dismissals are counted via
 * close-count semantics (not the boolean is_viewed flag).
 */
class ResponsibleBannerPromotion extends AbstractPromotion
{
	private const MAX_CLOSE_COUNT = 2;

	/**
	 * Trigger fires after 3 tasks created in a row with the creator as
	 * responsible (PRD AC-007); delegation resets the streak (AC-008).
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
		return PromotionType::RESPONSIBLE_BANNER;
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

		return $this->isTriggerReached($userId);
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
	 * The banner becomes relevant once the user created enough tasks in a row
	 * with themselves as responsible (SC-002). Delegating a task resets the
	 * streak to zero, which naturally hides the banner ("success").
	 */
	protected function isTriggerReached(int $userId): bool
	{
		return $this->counterService->getSelfAssignStreak($userId) >= self::TRIGGER_THRESHOLD;
	}
}
