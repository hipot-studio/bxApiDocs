<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\UI\DashboardOnboarding;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardViewRepository;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Spotlight;

/**
 * Decides what the first-time visitor of a BI report is shown: the step tour, the single
 * "add a chart" call to action of an empty self-created report, or nothing at all.
 */
final class DashboardOnboardingService
{
	private const ACTIVATION_OPTION = '~report_onboarding_activation_ts';

	public function __construct(private readonly SupersetDashboardViewRepository $viewRepository)
	{
	}

	public function getConfig(DashboardOnboardingContext $context): DashboardOnboardingConfig
	{
		$steps = $this->getAvailableSteps($context);
		$canRestart = $steps !== [];

		// The flag comes first on purpose: it is a cheap per-user check, while isNewToBi goes
		// to the view log. A user who has passed the tour is the common case.
		if ($this->isFlagSet(DashboardOnboardingFlag::TourPassed, $context->userId) || !$this->isNewToBi($context->userId))
		{
			// The steps travel even in the none mode: the menu item restarts the tour from the
			// client, and without them it would have nothing to play. The mode alone decides
			// whether anything starts by itself.
			return new DashboardOnboardingConfig(
				DashboardOnboardingMode::None,
				$steps,
				canRestart: $canRestart,
			);
		}

		if ($this->isEmptyDashboardCtaTarget($context))
		{
			return new DashboardOnboardingConfig(
				DashboardOnboardingMode::EmptyDashboardCta,
				[DashboardOnboardingStep::Edit],
				canRestart: $canRestart,
			);
		}

		// Whether a step really has something to point at is only known on the client: a tour
		// left without a single reachable target marks itself passed from there.
		return new DashboardOnboardingConfig(
			DashboardOnboardingMode::Tour,
			$steps,
			canRestart: $canRestart,
		);
	}

	/**
	 * A user is new to BI when the report view log holds nothing of theirs from before the
	 * onboarding was activated on this portal: BI veterans must not get the tour on release.
	 */
	public function isNewToBi(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$activatedAt = DateTime::createFromTimestamp($this->getActivationTimestamp());

		return !$this->viewRepository->hasViewsBefore($userId, $activatedAt);
	}

	public function markFlag(DashboardOnboardingFlag $flag, int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		(new Spotlight($flag->value))->setViewDate($userId);
	}

	public function isFlagSet(DashboardOnboardingFlag $flag, int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return (new Spotlight($flag->value))->isViewed($userId);
	}

	/**
	 * @return DashboardOnboardingStep[]
	 */
	private function getAvailableSteps(DashboardOnboardingContext $context): array
	{
		$steps = [];
		foreach (DashboardOnboardingStep::cases() as $step)
		{
			if ($this->isStepAvailable($step, $context))
			{
				$steps[] = $step;
			}
		}

		return $steps;
	}

	private function isStepAvailable(DashboardOnboardingStep $step, DashboardOnboardingContext $context): bool
	{
		return match ($step)
		{
			// Whether the filter bar and the preset chip are really on the screen is only known
			// on the client, so both steps are offered here and the client queue drops what it
			// cannot point at.
			DashboardOnboardingStep::Filters, DashboardOnboardingStep::Presets => true,
			// The header button is always there: sending the report as a picture to a work chat
			// needs no rights at all, and only the "share a link" item behind it is gated.
			DashboardOnboardingStep::Info, DashboardOnboardingStep::Share => true,
			DashboardOnboardingStep::BitrixGpt => $context->isBitrixGptAvailable,
			DashboardOnboardingStep::Edit => $context->canEdit,
		};
	}

	private function isEmptyDashboardCtaTarget(DashboardOnboardingContext $context): bool
	{
		if ($this->isFlagSet(DashboardOnboardingFlag::EmptyDashboardCtaShown, $context->userId))
		{
			return false;
		}

		return
			$context->canEdit
			&& $context->dashboardType === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM
			&& $context->dashboardStatus === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
			// The owner column is deprecated - the domain keeps it for compatibility only and
			// takes the author from CREATED_BY_ID.
			&& $context->dashboardCreatedById === $context->userId
		;
	}

	/**
	 * The moment the onboarding became available on this portal. Written on first read, the
	 * same way core spotlights activate themselves, so no update step has to backfill it.
	 */
	private function getActivationTimestamp(): int
	{
		$activatedAt = (int)Option::get('biconnector', self::ACTIVATION_OPTION, 0);
		if ($activatedAt <= 0)
		{
			$activatedAt = time();
			Option::set('biconnector', self::ACTIVATION_OPTION, $activatedAt);
		}

		return $activatedAt;
	}
}
