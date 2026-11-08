<?php

namespace Bitrix\HumanResources\Item\HcmLink\Monitoring;

use Bitrix\HumanResources\Contract\Item;
use Bitrix\HumanResources\Type\HcmLink\Monitoring\ExchangeHealth;
use Bitrix\Main\Type\DateTime;

class CompanySummary implements Item
{
	/**
	 * @param StateCounters $counters over the window of the summary, the set the page shows
	 * @param StateCounters $recentCounters over the window of the verdict
	 * @param ?DateTime $lastTroubleAt the moment the exchange last went wrong over the window of the summary
	 * @param ?DateTime $lastSuccessStartedAt when the last successfully finished job was created
	 */
	public function __construct(
		public readonly int $companyId,
		public readonly string $companyTitle,
		public readonly ?DateTime $lastAnswerAt,
		public readonly StateCounters $counters,
		public readonly StateCounters $recentCounters,
		public readonly ?DateTime $lastTroubleAt = null,
		public readonly ?DateTime $lastSuccessStartedAt = null,
	)
	{
	}

	/**
	 * A working exchange is impossible without a single answer of 1C, so the verdict never says it
	 * while nothing came back over the window of the summary. Trouble inside the window of the
	 * verdict decides first, so that a fixed exchange cools down within a day.
	 *
	 * Older trouble is forgiven only against a successful job CREATED after it - a job born after the
	 * trouble and carried through is the only thing that proves the channel works now. The moment an
	 * answer arrived proves nothing by itself: a job created before the trouble can finish after it, and
	 * such an answer used to pass for a recovery. Trouble whose moment is unknown is not forgiven
	 * either: nothing can be proven newer than a moment nobody knows. A job the system picked up and
	 * never answered is older than a day by definition and cannot be in the window of the verdict at
	 * all, so it comes from the window of the summary.
	 */
	public function health(): ExchangeHealth
	{
		if ($this->counters->total() === 0)
		{
			return ExchangeHealth::NO_DATA;
		}

		if ($this->hasTrouble($this->recentCounters))
		{
			return $this->recentCounters->done > 0 ? ExchangeHealth::PARTIAL : ExchangeHealth::BROKEN;
		}

		if ($this->hasTrouble($this->counters) && !$this->hasSuccessAfterTrouble())
		{
			return ExchangeHealth::BROKEN;
		}

		if ($this->counters->unfinished > 0)
		{
			return ExchangeHealth::UNFINISHED;
		}

		return $this->hasAnswer() ? ExchangeHealth::OK : ExchangeHealth::NO_DATA;
	}

	private function hasTrouble(StateCounters $counters): bool
	{
		return $counters->notPickedUp > 0 || $counters->canceledByAgent > 0;
	}

	/**
	 * A job counted as successful but carrying no moment it finished at is not proof of an answer.
	 */
	private function hasAnswer(): bool
	{
		return $this->lastAnswerAt !== null;
	}

	/**
	 * The trouble is over only when a job created after it went through. Taking the moment of an answer
	 * instead would count a job that started before the trouble and finished after it, and a cancel
	 * whose own moment is not stored would have to be guessed at.
	 */
	private function hasSuccessAfterTrouble(): bool
	{
		if ($this->lastSuccessStartedAt === null || $this->lastTroubleAt === null)
		{
			return false;
		}

		return $this->lastSuccessStartedAt->getTimestamp() >= $this->lastTroubleAt->getTimestamp();
	}
}
