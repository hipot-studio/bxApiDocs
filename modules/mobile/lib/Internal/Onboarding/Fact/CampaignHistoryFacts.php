<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Onboarding\Fact;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Integration\Im\MobileVisitReader;
use Bitrix\Mobile\Internal\Integration\Intranet\EmployeeReader;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Service\OnboardingPushService;
use Bitrix\Mobile\Internal\Service\Marketing\FactSource;

/**
 * Facts of the history of the push campaign, declared for the rules of its channel only - the registry
 * turns away a rule of another channel that names one:
 * - "onboarding.seriesStarted" - a push of the series has gone out to the user;
 * - "onboarding.colleagueJoinedAfterInvitePush" - a colleague joined after the first invite push, or after
 *   the "since" parameter while none was sent: he registered after it, or accepted an invitation of the
 *   user sent after it;
 * - "onboarding.visitedAfterMeetup" - the application was opened after the meetup push, false while no
 *   meetup was sent.
 *
 * The journal is read, never written, and a pass hands in the one it has preloaded, so the history costs
 * no query of its own. A fact with a threshold from the history asks the shared reader with that
 * threshold. The colleague counts strictly after it, to the second, as the gate of the message step always
 * counted him, while the reader takes a threshold inclusively: it is asked a second later.
 */
final class CampaignHistoryFacts implements FactSource
{
	public const CHANNEL = 'push';

	public const SERIES_STARTED = 'onboarding.seriesStarted';
	public const COLLEAGUE_JOINED_AFTER_INVITE_PUSH = 'onboarding.colleagueJoinedAfterInvitePush';
	public const VISITED_AFTER_MEETUP = 'onboarding.visitedAfterMeetup';

	public const PARAM_SINCE = 'since';

	private readonly OnboardingPushService $journal;
	private readonly EmployeeReader $employees;
	private readonly MobileVisitReader $visits;

	public function __construct(
		?OnboardingPushService $journal = null,
		?EmployeeReader $employees = null,
		?MobileVisitReader $visits = null,
	)
	{
		$this->journal = $journal ?? new OnboardingPushService();
		$this->employees = $employees ?? new EmployeeReader();
		$this->visits = $visits ?? new MobileVisitReader();
	}

	public function getDefinitions(): array
	{
		return array_map(
			static fn(string $code): FactDefinition => new FactDefinition(
				$code,
				FactType::Boolean,
				true,
				channel: self::CHANNEL,
			),
			[self::SERIES_STARTED, self::COLLEAGUE_JOINED_AFTER_INVITE_PUSH, self::VISITED_AFTER_MEETUP],
		);
	}

	public function read(string $code, array $params, EvaluationContext $context, array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($context->channel !== self::CHANNEL)
		{
			return array_fill_keys($userIds, null);
		}

		return match ($code)
		{
			self::SERIES_STARTED => $this->readSeriesStarted($userIds),
			self::COLLEAGUE_JOINED_AFTER_INVITE_PUSH => $this->readColleagueJoined($userIds, $params),
			self::VISITED_AFTER_MEETUP => $this->readVisitedAfterMeetup($userIds),
			default => array_fill_keys($userIds, null),
		};
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>
	 */
	private function readSeriesStarted(array $userIds): array
	{
		$values = [];
		foreach ($userIds as $userId)
		{
			$values[$userId] = $this->journal->getLastSentPush($userId)?->lastSeriesPushDate !== null;
		}

		return $values;
	}

	/**
	 * A colleague who joined after the threshold decides for his user in one read of the pack; the
	 * invitations are asked only of the users still undecided.
	 *
	 * @param int[] $userIds
	 * @return array<int, bool|null>
	 */
	private function readColleagueJoined(array $userIds, array $params): array
	{
		$since = $params[self::PARAM_SINCE] ?? null;
		if ($userIds === [] || !$since instanceof DateTime)
		{
			return array_fill_keys($userIds, null);
		}

		$thresholds = [];
		foreach ($userIds as $userId)
		{
			$threshold = $this->journal->getStepFirstSentAt($userId, PushType::INVITE) ?? $since;
			$thresholds[$userId] = DateTime::createFromTimestamp($threshold->getTimestamp() + 1);
		}

		$owners = $this->employees->getJoinedColleagueOwners($userIds, $thresholds);
		if ($owners === null)
		{
			return array_fill_keys($userIds, null);
		}

		$values = array_fill_keys($userIds, false);
		foreach ($owners as $ownerId)
		{
			$values[$ownerId] = true;
		}

		$pending = array_keys(array_filter($values, static fn(bool $value): bool => !$value));
		if ($pending === [])
		{
			return $values;
		}

		$pendingThresholds = array_intersect_key($thresholds, array_flip($pending));
		$earliest = DateTime::createFromTimestamp(min(array_map(
			static fn(DateTime $threshold): int => $threshold->getTimestamp(),
			$pendingThresholds,
		)));
		$inviters = $this->employees->getAcceptedInviters($pending, $earliest, $pendingThresholds);
		$isInviter = $inviters === null ? null : array_flip($inviters);
		foreach ($pending as $userId)
		{
			$values[$userId] = $isInviter === null ? null : isset($isInviter[$userId]);
		}

		return $values;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool|null>
	 */
	private function readVisitedAfterMeetup(array $userIds): array
	{
		$meetups = [];
		foreach ($userIds as $userId)
		{
			$meetupPushDate = $this->journal->getLastSentPush($userId)?->meetupPushDate;
			if ($meetupPushDate !== null)
			{
				$meetups[$userId] = $meetupPushDate;
			}
		}

		$values = array_fill_keys($userIds, false);
		if ($meetups === [])
		{
			return $values;
		}

		$visits = $this->visits->getLastVisits(array_keys($meetups));
		foreach ($meetups as $userId => $meetupPushDate)
		{
			$lastVisit = $visits[$userId] ?? null;
			$values[$userId] = $visits === null
				? null
				: $lastVisit !== null && $lastVisit->getTimestamp() > $meetupPushDate
			;
		}

		return $values;
	}
}
