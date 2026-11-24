<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/**
 * Fixed-size state of one open attempt (token) kept in CONTEXT.vo next to its origin snapshot.
 *
 * The state is the durable debt of the attempt: how many admitted sends may still reach the platform,
 * whether a trusted terminal answer stopped the attempt, and the single owner of a reopen it grants.
 * Anything that does not match the shape exactly is treated as unknown and retains the attempt.
 */
final class OpenAttemptState
{
	public const VERSION = 1;
	public const MAX_OUTSTANDING = 2_147_483_647;

	public const CANDIDATE_NONE = 'none';
	public const CANDIDATE_CLOSED = 'closed';
	public const CANDIDATE_EXPIRED = 'expired';

	private const CANDIDATE_RANK = [
		self::CANDIDATE_NONE => 0,
		self::CANDIDATE_CLOSED => 1,
		self::CANDIDATE_EXPIRED => 2,
	];

	public static function create(): array
	{
		return [
			'version' => self::VERSION,
			'fingerprint' => null,
			'outstanding' => 0,
			'stop' => false,
			'candidate' => self::CANDIDATE_NONE,
			'finalized' => false,
			'owner' => null,
			'consumed' => false,
		];
	}

	public static function isValid(mixed $state): bool
	{
		if (!is_array($state) || count($state) !== 8)
		{
			return false;
		}

		return ($state['version'] ?? null) === self::VERSION
			&& array_key_exists('fingerprint', $state)
			&& array_key_exists('owner', $state)
			&& (
				($state['fingerprint'] ?? null) === null
				|| (is_string($state['fingerprint']) && preg_match('/^[a-f0-9]{64}$/D', $state['fingerprint']) === 1)
			)
			&& is_int($state['outstanding'] ?? null)
			&& $state['outstanding'] >= 0
			&& $state['outstanding'] <= self::MAX_OUTSTANDING
			&& is_bool($state['stop'] ?? null)
			&& is_string($state['candidate'] ?? null)
			&& isset(self::CANDIDATE_RANK[$state['candidate']])
			&& is_bool($state['finalized'] ?? null)
			&& (($state['owner'] ?? null) === null || is_string($state['owner']))
			&& is_bool($state['consumed'] ?? null)
			&& ($state['stop'] || $state['candidate'] === self::CANDIDATE_NONE)
			&& (!$state['finalized'] || ($state['stop'] && $state['outstanding'] === 0))
		;
	}

	/**
	 * Whether the attempt still holds something a deletion or a new identity must not drop: a send that
	 * may still reach the platform, a stop not finalized yet, or a reopen owner not consumed yet.
	 */
	public static function hasDebt(array $state): bool
	{
		return $state['outstanding'] > 0
			|| ($state['stop'] && !$state['finalized'])
			|| ($state['owner'] !== null && !$state['consumed'])
		;
	}

	/** The candidate only rises: expired dominates closed, nothing lowers either of them. */
	public static function raiseCandidate(string $current, string $candidate): string
	{
		return self::CANDIDATE_RANK[$candidate] > self::CANDIDATE_RANK[$current] ? $candidate : $current;
	}

	/**
	 * Reads the map of attempt states from vo metadata; null means the map itself is corrupt.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function readMap(array $vo): ?array
	{
		if (!array_key_exists(DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY, $vo))
		{
			return [];
		}
		$map = $vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY];

		return is_array($map) ? $map : null;
	}

	public static function readLastToken(array $vo): ?string
	{
		$token = $vo[DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY] ?? null;

		return is_string($token) && $token !== '' ? $token : null;
	}

	/**
	 * Reads the map participant => token of the latest attempt each participant of the local session
	 * reserved; null means the map itself is corrupt.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function readParticipantMap(array $vo): ?array
	{
		if (!array_key_exists(DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY, $vo))
		{
			return [];
		}
		$map = $vo[DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY];

		return is_array($map) ? $map : null;
	}

	/** The registry ID below which a pending row without a state is legacy; null when not set or corrupt. */
	public static function readFloor(array $vo): ?int
	{
		$floor = $vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY] ?? null;

		return is_int($floor) && $floor > 0 ? $floor : null;
	}

	/**
	 * Whether a registry row without a state is a legacy attempt: reserved below the floor, or in a session
	 * that never reserved an attempt with a state. A row above the floor lost its state to the bound.
	 */
	public static function isLegacyRow(array $vo, int $rowId): bool
	{
		if (!array_key_exists(DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY, $vo))
		{
			return true;
		}
		$floor = self::readFloor($vo);

		return $floor !== null && $rowId < $floor;
	}

	/** Whether the floor is set but unreadable: nothing below or above it can then be told apart. */
	public static function isFloorCorrupt(array $vo): bool
	{
		return array_key_exists(DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY, $vo)
			&& self::readFloor($vo) === null
		;
	}

	/** Whether the token is the latest attempt of the local session or of one of its participants. */
	public static function isLatestToken(array $vo, string $token): bool
	{
		if (self::readLastToken($vo) === $token)
		{
			return true;
		}
		$participants = self::readParticipantMap($vo);

		return $participants !== null && isset(self::participantTokenSet($participants)[$token]);
	}

	/**
	 * The tokens of a participant map as a set, for membership checks by key.
	 *
	 * @return array<string, true>
	 */
	public static function participantTokenSet(array $participants): array
	{
		$set = [];
		foreach ($participants as $token)
		{
			if (is_string($token) && $token !== '')
			{
				$set[$token] = true;
			}
		}

		return $set;
	}

	/** Whether vo metadata of a local session carries an attempt whose debt keeps the carrier. */
	public static function protectsCarrier(array $vo): bool
	{
		$map = self::readMap($vo);
		$participants = self::readParticipantMap($vo);
		if ($map === null || $participants === null)
		{
			return true;
		}
		foreach ($participants as $token)
		{
			if (!is_string($token) || !array_key_exists($token, $map))
			{
				return true;
			}
		}
		$lastToken = self::readLastToken($vo);
		if (
			array_key_exists(DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY, $vo)
			&& ($lastToken === null || !array_key_exists($lastToken, $map))
		)
		{
			return true;
		}
		foreach ($map as $state)
		{
			if (!self::isValid($state) || self::hasDebt($state))
			{
				return true;
			}
		}

		return false;
	}
}
