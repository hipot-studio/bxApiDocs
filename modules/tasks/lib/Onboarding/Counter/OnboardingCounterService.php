<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Counter;

use Bitrix\Main\Type\Date;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepository;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;

/**
 * Per-user onboarding trigger counters (ALG-01).
 *
 * Tracks three counters in CUserOptions (category tasks.v2) used by the
 * onboarding banner/aha promotions:
 * - noDeadlineCount (SC-001): consecutive tasks created without a deadline;
 *   reset to zero as soon as a task with a deadline is created.
 * - selfAssignStreak (SC-002): consecutive tasks created with the creator as
 *   responsible; reset to zero on delegation (Q-3).
 * - daysWithoutManualDeadline (SC-003): number of distinct days the user
 *   created a task without manually setting a deadline (Q-2). A day is counted
 *   when the user did not set a deadline themselves — including when the server
 *   substituted the user's default auto deadline (so the task has a deadline but
 *   the user did not choose it).
 *
 * Counters are per-user; updates are sequential within a single OnTaskAdd, so
 * no transaction or locking is required.
 */
class OnboardingCounterService
{
	/**
	 * Counters are capped at the promotion trigger threshold. Every consuming
	 * promotion (\Bitrix\Tasks\Promotion\*) compares its counter with
	 * `>= TRIGGER_THRESHOLD`, so once a counter reaches the threshold its exact
	 * value no longer matters: freezing it there keeps the banner display logic
	 * intact while removing the per-creation read-modify-write to CUserOptions in
	 * the hot OnTaskAdd path. Must stay >= the largest promotion threshold.
	 */
	private const TRIGGER_THRESHOLD = 3;

	private UserOptionRepositoryInterface $userOptionRepository;

	public function __construct(?UserOptionRepositoryInterface $userOptionRepository = null)
	{
		$this->userOptionRepository = $userOptionRepository ?? new UserOptionRepository();
	}

	/**
	 * Applies ALG-01 for a single task creation.
	 *
	 * @param bool $hasDeadline whether the created task has any deadline (drives
	 *     SC-001 noDeadlineCount).
	 * @param bool $hasManualDeadline whether the user set the deadline manually
	 *     (drives SC-003). Distinct from $hasDeadline: when the server applied the
	 *     user's default auto deadline, $hasDeadline is true but
	 *     $hasManualDeadline is false, and the day still counts towards SC-003.
	 */
	public function registerTaskCreation(int $userId, bool $hasDeadline, bool $isSelf, bool $hasManualDeadline): void
	{
		if ($userId <= 0)
		{
			return;
		}

		// Read every counter before writing any of them. The three options share
		// the CUserOptions category 'tasks.v2', so the first get() loads the whole
		// category and the other reads are cache hits — one SELECT total. This
		// matters because each add() (CUserOptions::SetOption) drops the category
		// cache, so interleaving reads and writes would re-SELECT the category once
		// per counter in this hot OnTaskAdd path.
		$noDeadlineCount = $this->getNoDeadlineCount($userId);
		$selfAssignStreak = $this->getSelfAssignStreak($userId);
		$daysWithoutManualDeadline = $hasManualDeadline ? [] : $this->getDaysWithoutManualDeadline($userId);

		if ($hasDeadline)
		{
			$this->resetNoDeadlineCount($userId, $noDeadlineCount);
		}
		else
		{
			$this->incrementNoDeadlineCount($userId, $noDeadlineCount);
		}

		if ($isSelf)
		{
			$this->incrementSelfAssignStreak($userId, $selfAssignStreak);
		}
		else
		{
			$this->resetSelfAssignStreak($userId, $selfAssignStreak);
		}

		// SC-003 (Q-2): a day is counted when the user did NOT set a deadline
		// manually — including the case where the server substituted the user's
		// default auto deadline ($hasDeadline may be true while $hasManualDeadline
		// is false). SC-001 above still follows the actual deadline ($hasDeadline).
		if (!$hasManualDeadline)
		{
			$this->addDayWithoutManualDeadline($userId, $daysWithoutManualDeadline);
		}
	}

	public function getNoDeadlineCount(int $userId): int
	{
		return (int)$this->userOptionRepository->get(OptionDictionary::OnboardingNoDeadlineCount, $userId);
	}

	public function getSelfAssignStreak(int $userId): int
	{
		return (int)$this->userOptionRepository->get(OptionDictionary::OnboardingSelfAssignStreak, $userId);
	}

	public function getDaysWithoutManualDeadlineCount(int $userId): int
	{
		return count($this->getDaysWithoutManualDeadline($userId));
	}

	private function incrementNoDeadlineCount(int $userId, int $current): void
	{
		if ($current >= self::TRIGGER_THRESHOLD)
		{
			return;
		}

		$this->userOptionRepository->add(OptionDictionary::OnboardingNoDeadlineCount, $userId, $current + 1);
	}

	private function resetNoDeadlineCount(int $userId, int $current): void
	{
		if ($current === 0)
		{
			return;
		}

		$this->userOptionRepository->add(OptionDictionary::OnboardingNoDeadlineCount, $userId, 0);
	}

	private function incrementSelfAssignStreak(int $userId, int $current): void
	{
		if ($current >= self::TRIGGER_THRESHOLD)
		{
			return;
		}

		$this->userOptionRepository->add(OptionDictionary::OnboardingSelfAssignStreak, $userId, $current + 1);
	}

	private function resetSelfAssignStreak(int $userId, int $current): void
	{
		if ($current === 0)
		{
			return;
		}

		$this->userOptionRepository->add(OptionDictionary::OnboardingSelfAssignStreak, $userId, 0);
	}

	/**
	 * @param string[] $days distinct 'Y-m-d' dates already read by the caller
	 */
	private function addDayWithoutManualDeadline(int $userId, array $days): void
	{
		if (count($days) >= self::TRIGGER_THRESHOLD)
		{
			return;
		}

		// Q-2, accepted best-effort: the day boundary is the server timezone one.
		// For a user in another timezone two of their days may collapse into one
		// server day (or split); with a threshold of three days this skew is
		// considered negligible and user-timezone math is intentionally avoided
		// in the hot task creation path.
		$today = (new Date())->format('Y-m-d');

		if (in_array($today, $days, true))
		{
			return;
		}

		$days[] = $today;

		$this->userOptionRepository->add(OptionDictionary::OnboardingDaysWithoutManualDeadline, $userId, $days);
	}

	/**
	 * @return string[] distinct dates in 'Y-m-d' format
	 */
	private function getDaysWithoutManualDeadline(int $userId): array
	{
		$value = $this->userOptionRepository->get(OptionDictionary::OnboardingDaysWithoutManualDeadline, $userId);

		if (!is_array($value))
		{
			return [];
		}

		return array_values(array_filter($value, 'is_string'));
	}
}
