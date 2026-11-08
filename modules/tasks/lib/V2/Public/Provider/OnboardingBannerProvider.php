<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\Onboarding\Counter\OnboardingCounterService;
use Bitrix\Tasks\Onboarding\OnboardingFeature;
use Bitrix\Tasks\Promotion\DeadlineAhaPromotion;
use Bitrix\Tasks\Promotion\DeadlineBannerPromotion;
use Bitrix\Tasks\Promotion\NewUserSegment;
use Bitrix\Tasks\Promotion\ResponsibleBannerPromotion;

/**
 * Read-only provider for the onboarding banners client state (DTO-01).
 *
 * Aggregates, per user, the per-promotion dismissal/view state (phase 1
 * promotions) and the onboarding trigger counters (phase 2 service). The result
 * is delivered to the V2 task card via Core.getParams().onboardingBanners.
 *
 * The provider only reads state; it never mutates options.
 */
class OnboardingBannerProvider
{
	public function __construct(
		private readonly NewUserSegment $newUserSegment,
		private readonly DeadlineBannerPromotion $deadlineBannerPromotion,
		private readonly ResponsibleBannerPromotion $responsibleBannerPromotion,
		private readonly DeadlineAhaPromotion $deadlineAhaPromotion,
		private readonly OnboardingCounterService $onboardingCounterService,
	)
	{
	}

	public function get(int $userId): array
	{
		// The banners obey the module-wide onboarding gate; a disabled feature or
		// a user outside the segment must not leak stored counters to the client.
		// The early exit also skips the per-promotion option reads for them.
		if (!OnboardingFeature::isAvailable() || !$this->newUserSegment->isNewUser($userId))
		{
			return $this->getDefaultState();
		}

		return [
			'isNewUser' => true,
			'deadlineBanner' => [
				'closeCount' => $this->deadlineBannerPromotion->getCloseCount($userId),
			],
			'responsibleBanner' => [
				'closeCount' => $this->responsibleBannerPromotion->getCloseCount($userId),
			],
			'deadlineAha' => [
				'isViewed' => $this->deadlineAhaPromotion->isViewed($userId),
			],
			'counters' => [
				'noDeadlineCount' => $this->onboardingCounterService->getNoDeadlineCount($userId),
				'selfAssignStreak' => $this->onboardingCounterService->getSelfAssignStreak($userId),
				'daysWithoutManualDeadline' => $this->onboardingCounterService->getDaysWithoutManualDeadlineCount($userId),
			],
		];
	}

	private function getDefaultState(): array
	{
		return [
			'isNewUser' => false,
			'deadlineBanner' => [
				'closeCount' => 0,
			],
			'responsibleBanner' => [
				'closeCount' => 0,
			],
			'deadlineAha' => [
				'isViewed' => false,
			],
			'counters' => [
				'noDeadlineCount' => 0,
				'selfAssignStreak' => 0,
				'daysWithoutManualDeadline' => 0,
			],
		];
	}
}
