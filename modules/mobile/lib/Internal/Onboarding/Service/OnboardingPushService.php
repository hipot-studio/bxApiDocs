<?php

namespace Bitrix\Mobile\Internal\Onboarding\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Internal\Integration\Main\UserOptionReader;
use Bitrix\Mobile\Internal\Onboarding\Dto\LastPushInfo;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * Reads and writes the campaign state of a user (DTO-01) in the user option
 * mobile/OnboardingLastPush. Every write merges into the stored payload, so the
 * legacy regional series cannot drop the counters and flags of the activation
 * series; reading a legacy {type, timestamp} record stays valid.
 *
 * Push types are PushType on the API and strings in the payload: records written
 * before the activation series carry type values of their own, and the stored `type`
 * must stay readable without matching an enum case.
 *
 * Besides the attempt counters the state keeps stepFirstSentAt - when each step push
 * went out for the first time. The gates of the steps are judged against these dates,
 * so they are append-only: an existing entry is never rewritten.
 *
 * A write is a read-modify-write of one user option without locking. The engine relies
 * on the agent not running twice for the same portal at the same moment; two truly
 * concurrent passes could lose an attempt counter or send a second push on the same
 * day. The window is one agent pass wide, and no state here is monetary.
 */
class OnboardingPushService
{
	private const OPTION_CATEGORY = 'mobile';
	private const LAST_PUSH_OPTION = 'OnboardingLastPush';

	/**
	 * State of a preloaded selection by user id, or null when nothing is preloaded and
	 * every read goes to the storage. An entry exists for every user of the selection, an
	 * empty payload included, so a user who has no record costs no query either.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $preloadedPayloads = null;

	/**
	 * Reads the state of a whole selection in one query, so a pass over N users spends one
	 * read of the option instead of N - and a user the series has nothing for today spends
	 * none at all. Writes stay per user: they happen only when a push actually goes out,
	 * and they keep the preloaded entry current.
	 *
	 * Every following read of a preloaded user is answered from the preloaded state, so a
	 * caller must not preload a selection it is not about to process.
	 *
	 * @param int[] $userIds
	 */
	public function preloadState(array $userIds): void
	{
		$payloads = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$payloads[$userId] = [];
			}
		}

		$this->preloadedPayloads = $payloads;
		if ($payloads === [])
		{
			return;
		}

		$stored = UserOptionReader::read(array_keys($payloads), self::OPTION_CATEGORY, self::LAST_PUSH_OPTION);
		foreach ($stored as $userId => $value)
		{
			$this->preloadedPayloads[$userId] = $this->decodePayload(
				unserialize($value, ['allowed_classes' => false]),
			);
		}
	}

	public function getLastSentPush(int $userId): ?LastPushInfo
	{
		$data = $this->readPayload($userId);

		if (empty($data['type']) || empty($data['timestamp']))
		{
			return null;
		}

		return new LastPushInfo(
			type: (string)$data['type'],
			timestamp: (int)$data['timestamp'],
			stepAttempts: $this->readAttempts($data),
			lastSeriesPushDate: $this->readNullableInt($data, 'lastSeriesPushDate'),
			meetupSent: (bool)($data['meetupSent'] ?? false),
			meetupPushDate: $this->readNullableInt($data, 'meetupPushDate'),
			helpSent: (bool)($data['helpSent'] ?? false),
			stepFirstSentAt: $this->readIntMap($data, 'stepFirstSentAt'),
		);
	}

	public function getStepFirstSentAt(int $userId, PushType $pushType): ?DateTime
	{
		if ($userId <= 0)
		{
			return null;
		}

		$timestamp = $this->readIntMap($this->readPayload($userId), 'stepFirstSentAt')[$pushType->value] ?? null;

		return $timestamp === null ? null : DateTime::createFromTimestamp($timestamp);
	}

	public function setLastSentPush(int $userId, PushType $type, ?int $timestamp = null): void
	{
		$this->write($userId, [
			'type' => $type->value,
			'timestamp' => $timestamp ?? (new DateTime())->getTimestamp(),
		]);
	}

	/**
	 * Registers a sent push of the activation series: bumps the attempt counter of
	 * its step, records the first send of that step and moves the final-push anchor.
	 * Called only after the sender reported success.
	 *
	 * The first-send date is written once and never moved by a retry: the gates compare
	 * facts against it, and a date that walks forward would revoke a colleague already
	 * counted.
	 */
	public function registerSeriesPush(int $userId, PushType $type, ?int $timestamp = null): void
	{
		$timestamp ??= (new DateTime())->getTimestamp();
		$payload = $this->readPayload($userId);

		$attempts = $this->readAttempts($payload);
		$attempts[$type->value] = ($attempts[$type->value] ?? 0) + 1;

		$firstSentAt = $this->readIntMap($payload, 'stepFirstSentAt');
		$firstSentAt[$type->value] ??= $timestamp;

		$this->write($userId, [
			'type' => $type->value,
			'timestamp' => $timestamp,
			'stepAttempts' => $attempts,
			'stepFirstSentAt' => $firstSentAt,
			'lastSeriesPushDate' => $timestamp,
		]);
	}

	/**
	 * Registers a sent meetup push: moves the last-push marker, so the "at most one
	 * push per day" invariant covers the finals too, and raises the once-only flag
	 * together with the anchor of the implementation help. The series anchor
	 * lastSeriesPushDate stays untouched - the meetup itself is counted from it.
	 */
	public function registerMeetupPush(int $userId, ?int $timestamp = null): void
	{
		$timestamp ??= (new DateTime())->getTimestamp();

		$this->write($userId, [
			'type' => PushType::MEETUP->value,
			'timestamp' => $timestamp,
			'meetupSent' => true,
			'meetupPushDate' => $timestamp,
		]);
	}

	public function registerHelpPush(int $userId, ?int $timestamp = null): void
	{
		$this->write($userId, [
			'type' => PushType::IMPLEMENTATION_HELP->value,
			'timestamp' => $timestamp ?? (new DateTime())->getTimestamp(),
			'helpSent' => true,
		]);
	}

	/**
	 * Marks the series of the user as stopped for good: the step it stood on was left
	 * uncompleted with its attempts spent.
	 *
	 * The mark is what makes the interruption irreversible. Without it the state of a user
	 * who did the action too late is indistinguishable from one who did it in time - the step
	 * simply reads as completed and the resolver walks on to the next one. Only the moment
	 * separates the two, so the moment is written down.
	 */
	public function registerSeriesStopped(int $userId, ?int $timestamp = null): void
	{
		$this->write($userId, ['seriesStoppedAt' => $timestamp ?? (new DateTime())->getTimestamp()]);
	}

	/**
	 * Whether the series of the user was stopped by a spent step.
	 */
	public function isSeriesStopped(int $userId): bool
	{
		return $this->hasMark($userId, 'seriesStoppedAt');
	}

	/**
	 * Marks the series of the user as ended: every step of their path is closed and the
	 * resolver has nothing left to send.
	 *
	 * Step progress itself is never stored - the checkers recompute it on every pass - but the
	 * end of the walk is worth a mark of its own, because it is the one answer that cannot
	 * change back: a closed step does not reopen. Without it a user who finished the series
	 * costs the checks of their whole path on every pass of the portal, for good, and on a
	 * portal whose people already had tasks and deals of their own that is most of the
	 * selection.
	 */
	public function registerSeriesCompleted(int $userId, ?int $timestamp = null): void
	{
		$this->write($userId, ['seriesCompletedAt' => $timestamp ?? (new DateTime())->getTimestamp()]);
	}

	/**
	 * Whether the series of the user ended with every step of their path closed.
	 */
	public function isSeriesCompleted(int $userId): bool
	{
		return $this->hasMark($userId, 'seriesCompletedAt');
	}

	private function hasMark(int $userId, string $key): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->readNullableInt($this->readPayload($userId), $key) !== null;
	}

	/**
	 * The implementation help gets a single attempt: a visit after the meetup cancels
	 * the send, and the flag is raised anyway.
	 */
	public function skipHelpPush(int $userId): void
	{
		$this->write($userId, ['helpSent' => true]);
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private function write(int $userId, array $changes): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$payload = array_merge($this->readPayload($userId), $changes);

		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::LAST_PUSH_OPTION,
			Json::encode($payload),
			false,
			$userId,
		);

		if ($this->preloadedPayloads !== null)
		{
			// the state is read again after a send - the dedup of the day, the attempt
			// counter and the gate dates all ask for it
			$this->preloadedPayloads[$userId] = $payload;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readPayload(int $userId): array
	{
		if (isset($this->preloadedPayloads[$userId]))
		{
			return $this->preloadedPayloads[$userId];
		}

		return $this->decodePayload(
			\CUserOptions::GetOption(self::OPTION_CATEGORY, self::LAST_PUSH_OPTION, null, $userId),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodePayload(mixed $value): array
	{
		if (!is_string($value) || $value === '')
		{
			return [];
		}

		try
		{
			$data = Json::decode($value);
		}
		catch (\Exception)
		{
			return [];
		}

		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, int>
	 */
	private function readAttempts(array $data): array
	{
		return $this->readIntMap($data, 'stepAttempts');
	}

	/**
	 * A `{pushType: int}` section of the payload, keeping only the entries that survive
	 * both keys and values being authored by an older version of this code.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, int>
	 */
	private function readIntMap(array $data, string $key): array
	{
		$section = $data[$key] ?? null;
		if (!is_array($section))
		{
			return [];
		}

		$result = [];
		foreach ($section as $pushType => $value)
		{
			if (is_string($pushType) && is_numeric($value))
			{
				$result[$pushType] = (int)$value;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readNullableInt(array $data, string $key): ?int
	{
		$value = $data[$key] ?? null;

		return is_numeric($value) ? (int)$value : null;
	}
}
