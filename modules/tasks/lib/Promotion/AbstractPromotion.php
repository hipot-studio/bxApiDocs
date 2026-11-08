<?php

namespace Bitrix\Tasks\Promotion;

abstract class AbstractPromotion
{
	abstract public function getPromotionType(): PromotionType;
	abstract public function shouldShow(int $userId): bool;

	public function setViewed(int $userId): bool
	{
		return
			\CUserOptions::SetOption(
				'tasks',
				$this->getOptionName(),
				true,
				false,
				$userId
			);
	}

	protected function getOptionName(): string
	{
		return 'promo_' . $this->getPromotionType()->value . '_is_viewed';
	}

	public function isViewed(int $userId): bool
	{
		$option = \CUserOptions::GetOption('tasks', $this->getOptionName(), false, $userId);

		return $option === true;
	}

	/**
	 * Close-count semantics for banner promotions.
	 *
	 * Unlike the boolean `is_viewed` flag used by other promotions, banners
	 * track how many times the user dismissed them. Subclasses that need this
	 * behavior override setViewed() to call incrementCloseCount() and base
	 * their shouldShow() on getCloseCount().
	 */
	protected function getCloseCountOptionName(): string
	{
		return 'promo_' . $this->getPromotionType()->value . '_close_count';
	}

	public function getCloseCount(int $userId): int
	{
		return (int)\CUserOptions::GetOption('tasks', $this->getCloseCountOptionName(), 0, $userId);
	}

	/**
	 * Close-count cap for banner promotions, or null when the promotion does not
	 * use close-count semantics. Subclasses that track dismissals override this so
	 * incrementCloseCount() becomes idempotent once the cap is reached: after the
	 * banner is permanently hidden, retries / double clicks / duplicate REST calls
	 * must not keep writing CUserOptions and growing the value without bound.
	 */
	protected function getCloseCountLimit(): ?int
	{
		return null;
	}

	/**
	 * Read-modify-write over CUserOptions. The RMW is only protected against
	 * unbounded growth AFTER the cap is reached (idempotency via
	 * getCloseCountLimit()); a race BEFORE the cap (two concurrent setViewed()
	 * calls for the same banner) is not serialized. The effect is cosmetic only:
	 * the banner may be shown at most one extra time.
	 */
	protected function incrementCloseCount(int $userId): bool
	{
		$current = $this->getCloseCount($userId);

		$limit = $this->getCloseCountLimit();
		if ($limit !== null && $current >= $limit)
		{
			// Idempotent past the cap: the banner is already permanently hidden, so
			// further dismissals must not issue another CUserOptions write.
			return true;
		}

		return \CUserOptions::SetOption(
			'tasks',
			$this->getCloseCountOptionName(),
			$current + 1,
			false,
			$userId
		);
	}
}
