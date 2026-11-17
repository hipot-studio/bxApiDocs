<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;

class EffectiveDeadlineCalculator
{
	private const OFFSET_PROBE_SHIFT = 172800;

	public function calculate(
		DeadlineUserOption $deadlineUserOption,
		bool $matchesWorkTime,
		DateTime $now,
	): ?DateTime
	{
		if ($deadlineUserOption->defaultDeadlineInSeconds === 0)
		{
			return null;
		}

		$deadline = $deadlineUserOption->getDefaultDeadlineDate(
			matchWorkTime: $matchesWorkTime,
			dateTime: clone $now,
		);

		if ($deadline === null)
		{
			return null;
		}

		$wallTimestamp = $deadline->getTimestamp() + $deadline->getOffset();
		$offsets = [];
		foreach ([-self::OFFSET_PROBE_SHIFT, 0, self::OFFSET_PROBE_SHIFT] as $probeShift)
		{
			$probe = clone $deadline;
			if ($probeShift !== 0)
			{
				$probe->add(($probeShift < 0 ? '-' : '') . 'PT' . abs($probeShift) . 'S');
			}
			$offsets[] = \CTimeZone::GetOffset(null, false, $probe) + $probe->getOffset();
		}

		$validCandidates = [];
		$candidates = [];
		foreach (array_unique($offsets) as $userTimeOffset)
		{
			$candidate = clone $deadline;
			$candidateShift = $wallTimestamp - $userTimeOffset - $deadline->getTimestamp();
			if ($candidateShift !== 0)
			{
				$candidate->add(($candidateShift < 0 ? '-' : '') . 'PT' . abs($candidateShift) . 'S');
			}
			$candidates[$candidate->getTimestamp()] = $candidate;

			$actualUserTimeOffset = \CTimeZone::GetOffset(null, false, $candidate) + $candidate->getOffset();
			if ($actualUserTimeOffset === $userTimeOffset)
			{
				$validCandidates[$candidate->getTimestamp()] = $candidate;
			}
		}

		$resolvedCandidates = $validCandidates ?: $candidates;
		ksort($resolvedCandidates);

		$resolvedDeadline = end($resolvedCandidates);

		return $resolvedDeadline instanceof DateTime ? $resolvedDeadline : null;
	}
}
