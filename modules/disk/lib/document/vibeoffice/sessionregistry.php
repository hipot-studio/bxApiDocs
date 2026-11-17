<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;

class SessionRegistry
{
	/** A refusal of the registry the open path cannot repeat its way out of. */
	public const ERROR_CODE_FAILED = 'vibeoffice_registry_failed';

	/**
	 * A refusal the document is merely busy with: the metadata of the open could not be written
	 * while a save of the same document held the session context. Before the retry cycle such a
	 * write was best-effort and never failed an open, so it is answered with the screen of the
	 * branch of saving instead of the generic failure.
	 */
	public const ERROR_CODE_BUSY = 'vibeoffice_registry_busy';

	/** The local budget of an unresolved attempt ran out; the attempt is retained, not replaced. */
	public const ERROR_CODE_OPEN_EXPIRED = 'open_operation_expired';

	/**
	 * The latest attempt of the local session is not resolved: a send of it may still reach the platform,
	 * it was stopped, or its reopen belongs to another caller. No new identity and no new send is made.
	 */
	public const ERROR_CODE_OPEN_UNRESOLVED = 'open_operation_unresolved';

	private const MAX_EVENT_SESSIONS = 2000;
	/**
	 * The bound of attempt states one local session keeps in its CONTEXT: visitors of a public link share
	 * it, and the column must not outgrow its type (TEXT of MySQL) whatever their number.
	 */
	private const MAX_OPEN_ATTEMPTS = 64;
	private const OPEN_TOKEN_MAX_AGE_SECONDS = 7 * 24 * 3600;
	private const PARTICIPANT_PATTERN = '/^[a-f0-9]{64}$/D';

	private ?ClientOriginProviderInterface $clientOriginProvider;

	public function __construct(
		private readonly DocumentStateRepository $stateRepository = new DocumentStateRepository(),
		?ClientOriginProviderInterface $clientOriginProvider = null,
	)
	{
		$this->clientOriginProvider = $clientOriginProvider;
	}

	/**
	 * Resume an ambiguous live attempt of the same local session, or reserve a new one.
	 *
	 * The data of a success is ['openToken' => string, 'clientOrigin' => string|null]: the origin is
	 * the snapshot taken with the token, never the current setting.
	 *
	 * The participant ({@see self::PARTICIPANT_PATTERN}) names whose identity the open carries when several
	 * visitors share one local session, as the guests of a public link do: each of them resumes and is gated
	 * by its own latest attempt only. Without it the latest attempt of the whole session is the gate.
	 */
	public function beginOrResumeOpen(DocumentSession $session, string $hostDocKey, ?string $participant = null): Result
	{
		return $this->reserveOpen(
			$session,
			$hostDocKey,
			static fn(DocumentSession $fresh): bool => $fresh->isActive(),
			participant: $participant,
		);
	}

	/**
	 * Reserve once before the HTTP retry loop. No network calls may run inside repository locks.
	 * An unresolved attempt of the session is resumed instead of competing with a new identity.
	 */
	public function beginOpen(DocumentSession $session, string $hostDocKey, ?string $participant = null): Result
	{
		return $this->reserveOpen(
			$session,
			$hostDocKey,
			static fn(DocumentSession $fresh): bool => !$fresh->isNonActive(),
			participant: $participant,
		);
	}

	/**
	 * The one reopen a trusted 409 open_session_closed grants: the owner issued by the finalization of the
	 * closed attempt is presented and consumed together with the new token.
	 */
	public function beginReopen(
		DocumentSession $session,
		string $hostDocKey,
		string $owner,
		?string $participant = null,
	): Result
	{
		return $this->reserveOpen(
			$session,
			$hostDocKey,
			static fn(DocumentSession $fresh): bool => $fresh->isActive(),
			$owner,
			$participant,
		);
	}

	/**
	 * @param callable(DocumentSession): bool $isOpenable
	 */
	private function reserveOpen(
		DocumentSession $session,
		string $hostDocKey,
		callable $isOpenable,
		?string $owner = null,
		?string $participant = null,
	): Result
	{
		if (!$session->getId() || HostDocumentKey::resolve($session) !== $hostDocKey)
		{
			return $this->failure('Invalid Vibeoffice open binding.');
		}
		if ($participant !== null && preg_match(self::PARTICIPANT_PATTERN, $participant) !== 1)
		{
			return $this->failure('Invalid Vibeoffice open participant.');
		}

		$binding = $this->stateRepository->ensureSessionState($session);
		if (!$binding->isSuccess())
		{
			return $binding;
		}

		return $this->stateRepository->withLockedDocument(
			$hostDocKey,
			function () use ($session, $hostDocKey, $isOpenable, $owner, $participant): Result {
				$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $session->getId());
				$row = $this->fetchLocked($query);
				$fresh = $row ? DocumentSession::buildFromArray($row) : null;
				if (!$fresh || !$isOpenable($fresh) || HostDocumentKey::resolve($fresh) !== $hostDocKey)
				{
					return $this->failure('Vibeoffice session is no longer active.');
				}

				$vo = DocumentSessionManager::extractVoMetadata($fresh);
				if (OpenAttemptState::isFloorCorrupt($vo))
				{
					return $this->unresolved('Vibeoffice open attempt floor is unknown.');
				}
				$pending = $this->selectPending($hostDocKey, (int)$fresh->getId(), $vo, $participant);
				if ($pending)
				{
					return $owner === null
						? $this->resumePendingOpen($fresh, $pending, $vo)
						: $this->unresolved('Vibeoffice reopen found an unresolved attempt.')
					;
				}

				$latest = $this->resolveLatestAttempt($vo, $participant);
				if ($latest === false)
				{
					return $this->unresolved('Vibeoffice open participants are unknown.');
				}
				$gate = $this->checkLastAttempt($vo, $owner, $latest);
				if (!$gate->isSuccess())
				{
					$bound = $owner === null && $latest !== null
						? $this->findReplayableBoundAttempt($fresh, $hostDocKey, $vo, $latest)
						: null
					;

					return $bound === null ? $gate : $this->resumeBoundOpen($bound, $vo);
				}

				return $this->reserveNewOpen($fresh, $hostDocKey, $vo, $gate->getData()['consume'], $participant);
			},
		);
	}

	/**
	 * The pending attempt the open resumes: without a participant the latest one of the session; with it
	 * the own latest attempt of the participant, else an attempt no participant is known for (legacy).
	 * Pending attempts of other participants are theirs alone. Each of them is a single locked row. A pending
	 * row above the floor of the session whose state was given up by the bound is never resumed.
	 */
	private function selectPending(string $hostDocKey, int $sessionId, array $vo, ?string $participant): ?array
	{
		$floor = OpenAttemptState::readFloor($vo);
		$query = static fn(): Query => PlatformSessionTable::query()
			->setSelect(['ID', 'OPEN_TOKEN', 'CREATE_TIME'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('DOCUMENT_SESSION_ID', $sessionId)
			->where('STATUS', PlatformSessionTable::STATUS_PENDING)
			->whereNotNull('OPEN_TOKEN')
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
		;
		if ($participant === null)
		{
			$latest = $query();
			if ($floor !== null)
			{
				$resumable = Query::filter()->logic('or')->where('ID', '<', $floor);
				$stated = array_map('strval', array_keys(OpenAttemptState::readMap($vo) ?? []));
				if ($stated !== [])
				{
					$resumable->whereIn('OPEN_TOKEN', $stated);
				}
				$latest->where($resumable);
			}

			return $this->fetchLocked($latest);
		}
		$participants = OpenAttemptState::readParticipantMap($vo) ?? [];
		$own = $participants[$participant] ?? null;
		if (is_string($own) && $own !== '')
		{
			$row = $this->fetchLocked($query()->where('OPEN_TOKEN', $own));
			if ($row)
			{
				return $row;
			}
		}
		$legacy = $query();
		if ($floor !== null)
		{
			$legacy->where('ID', '<', $floor);
		}
		$known = array_map('strval', array_keys(OpenAttemptState::participantTokenSet($participants)));
		if ($known !== [])
		{
			$legacy->whereNotIn('OPEN_TOKEN', $known);
		}

		return $this->fetchLocked($legacy);
	}

	/**
	 * The latest attempt the gate of the open checks: the own latest attempt of a known participant; for a
	 * participant new to the session nothing (null) when the latest attempt of the session is another
	 * participant's, the latest attempt of the session otherwise. False when the participants are unknown.
	 *
	 * @return array{token: ?string, present: bool}|false|null
	 */
	private function resolveLatestAttempt(array $vo, ?string $participant): array|false|null
	{
		$session = [
			'token' => OpenAttemptState::readLastToken($vo),
			'present' => array_key_exists(DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY, $vo),
		];
		if ($participant === null)
		{
			return $session;
		}
		$participants = OpenAttemptState::readParticipantMap($vo);
		if ($participants === null)
		{
			return false;
		}
		if (array_key_exists($participant, $participants))
		{
			$token = $participants[$participant];

			return ['token' => is_string($token) && $token !== '' ? $token : null, 'present' => true];
		}

		$known = OpenAttemptState::participantTokenSet($participants);

		return $session['token'] !== null && isset($known[$session['token']])
			? null
			: $session
		;
	}

	/**
	 * Gate of the latest attempt, checked before any new identity even when no pending row is left: its
	 * debt, its stop and its reopen owner cannot be bypassed. The data names the token whose owner (or
	 * expired finalization) the new reservation consumes.
	 *
	 * @param array{token: ?string, present: bool}|null $latest null for a participant new to the session
	 */
	private function checkLastAttempt(array $vo, ?string $owner, ?array $latest): Result
	{
		$map = OpenAttemptState::readMap($vo);
		$lastToken = $latest['token'] ?? null;
		$hasLastKey = $latest['present'] ?? false;
		if (!$hasLastKey && $map !== null)
		{
			return $owner === null
				? (new Result())->setData(['consume' => null])
				: $this->unresolved('Vibeoffice reopen owner is unknown.')
			;
		}
		$state = $map !== null && $lastToken !== null ? ($map[$lastToken] ?? null) : null;
		if (
			!OpenAttemptState::isValid($state)
			|| $state['outstanding'] > 0
			|| ($state['stop'] && !$state['finalized'])
		)
		{
			return $this->unresolved('Vibeoffice open attempt is not resolved.');
		}
		if (!$state['finalized'])
		{
			return $owner === null
				? (new Result())->setData(['consume' => null])
				: $this->unresolved('Vibeoffice reopen owner is unknown.')
			;
		}
		if ($state['consumed'])
		{
			return $this->unresolved('Vibeoffice open attempt was already reopened.');
		}
		if ($state['candidate'] === OpenAttemptState::CANDIDATE_CLOSED)
		{
			return $owner !== null && $state['owner'] !== null && hash_equals($state['owner'], $owner)
				? (new Result())->setData(['consume' => $lastToken])
				: $this->unresolved('Vibeoffice reopen belongs to another caller.')
			;
		}

		// A remote expiry ends the call that saw it; only the next independent open takes the owner.
		return $owner === null
			? (new Result())->setData(['consume' => $lastToken])
			: $this->unresolved('Vibeoffice reopen owner is unknown.')
		;
	}

	/**
	 * The outcome of a sent token is unknown, so the token is kept for good: a replay before the
	 * local budget runs out repeats its snapshot, after it the open is refused without a new identity.
	 */
	private function resumePendingOpen(DocumentSession $fresh, array $pending, array $vo): Result
	{
		if (
			$pending['CREATE_TIME'] instanceof DateTime
			&& time() - $pending['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
		)
		{
			return (new Result())->addError(new Error(
				'Vibeoffice open operation expired.',
				self::ERROR_CODE_OPEN_EXPIRED,
			));
		}

		$token = (string)$pending['OPEN_TOKEN'];
		$state = $this->readAttemptState($vo, $token);
		if ($state === false || (is_array($state) && $state['stop']))
		{
			return $this->unresolved('Vibeoffice open attempt is stopped or its state is unknown.');
		}
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		$origin = is_array($origins) && is_string($origins[$token] ?? null) ? $origins[$token] : null;

		return (new Result())->setData(['openToken' => $token, 'clientOrigin' => $origin]);
	}

	/**
	 * The latest attempt of the local session that was answered with a config but still owes sends (an
	 * answer over an untrusted transport writes nothing off): it stays the identity of the session, so a
	 * reload replays its token instead of creating a new one. Null for anything else.
	 */
	private function findReplayableBoundAttempt(
		DocumentSession $fresh,
		string $hostDocKey,
		array $vo,
		array $latest,
	): ?array
	{
		$token = $latest['token'];
		$state = $token === null ? false : $this->readAttemptState($vo, $token);
		if (
			!is_array($state)
			|| $state['outstanding'] === 0
			|| $state['stop']
			|| $state['finalized']
			|| $state['fingerprint'] === null
		)
		{
			return null;
		}
		$row = $this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID', 'OPEN_TOKEN', 'CREATE_TIME', 'API_SESSION_ID'])
			->where('OPEN_TOKEN', $token)
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('DOCUMENT_SESSION_ID', $fresh->getId())
			->where('STATUS', PlatformSessionTable::STATUS_ACTIVE)
			->whereNotNull('API_SESSION_ID'));

		return $row ?: null;
	}

	/**
	 * Replays the bound token with its own snapshot: the same identity, the same local budget as a pending
	 * replay, and every send is admitted against the fingerprint of the first one.
	 */
	private function resumeBoundOpen(array $bound, array $vo): Result
	{
		if (
			!$bound['CREATE_TIME'] instanceof DateTime
			|| time() - $bound['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
		)
		{
			return (new Result())->addError(new Error(
				'Vibeoffice open operation expired.',
				self::ERROR_CODE_OPEN_EXPIRED,
			));
		}
		$token = (string)$bound['OPEN_TOKEN'];
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		$origin = is_array($origins) && is_string($origins[$token] ?? null) ? $origins[$token] : null;

		return (new Result())->setData(['openToken' => $token, 'clientOrigin' => $origin]);
	}

	private function reserveNewOpen(
		DocumentSession $fresh,
		string $hostDocKey,
		array $vo,
		?string $consume,
		?string $participant,
	): Result
	{
		$liveRows = $this->findLiveRows(array_keys(OpenAttemptState::readMap($vo) ?? []));
		$liveTokens = array_keys($liveRows);
		$refusal = null;
		$evicted = [];
		$origin = $this->getClientOriginProvider()->getOrigin();
		$token = UuidGenerator::generateV4();
		$add = PlatformSessionTable::add([
			'HOST_DOC_KEY' => $hostDocKey,
			'DOCUMENT_SESSION_ID' => $fresh->getId(),
			'OPEN_TOKEN' => $token,
			'STATUS' => PlatformSessionTable::STATUS_PENDING,
		]);
		if (!$add->isSuccess())
		{
			return (new Result())->addErrors($add->getErrors());
		}
		// A failed snapshot fails the whole reservation: the transaction drops the row with it.
		if (!(new DocumentSessionManager())->persistClientOriginSnapshot($fresh, $token, $origin))
		{
			return $this->failure('Could not persist Vibeoffice origin snapshot.', self::ERROR_CODE_BUSY);
		}
		$rowId = (int)$add->getId();
		$recorded = (new DocumentSessionManager())->persistOpenAttemptTransition(
			$fresh,
			function (array $vo) use (
				$token,
				$rowId,
				$consume,
				$participant,
				$liveTokens,
				&$refusal,
				&$evicted,
			): ?array {
				$evicted = [];
				$map = OpenAttemptState::readMap($vo);
				$participants = OpenAttemptState::readParticipantMap($vo);
				if ($map === null || $participants === null || array_key_exists($token, $map))
				{
					return null;
				}
				if ($consume !== null)
				{
					$previous = $map[$consume] ?? null;
					if (
						!OpenAttemptState::isValid($previous)
						|| !$previous['finalized']
						|| $previous['consumed']
						|| ($this->resolveLatestAttempt($vo, $participant)['token'] ?? null) !== $consume
					)
					{
						return null;
					}
					$previous['consumed'] = true;
					$map[$consume] = $previous;
				}
				$map[$token] = OpenAttemptState::create();
				if ($participant !== null)
				{
					$participants[$participant] = $token;
				}

				// The attempt consumed right now stays readable until the next reservation.
				$kept = $liveTokens;
				if ($consume !== null)
				{
					$kept[] = $consume;
				}
				if (!array_key_exists(DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY, $vo))
				{
					$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY] = $rowId;
				}
				elseif (OpenAttemptState::isFloorCorrupt($vo))
				{
					$refusal = 'Vibeoffice open attempt floor is unknown.';

					return null;
				}
				$vo = $this->boundAttempts(
					$this->pruneResolvedAttempts($vo, $map, $participants, $token, $kept),
					array_diff($liveTokens, [$token, $consume]),
					$evicted,
				);
				if ($vo === null)
				{
					$refusal = 'Vibeoffice local session holds too many unresolved open attempts.';
				}

				return $vo;
			},
		);
		if ($refusal !== null)
		{
			return $this->unresolved($refusal);
		}
		if (!$recorded)
		{
			return $this->failure('Could not persist Vibeoffice open attempt state.', self::ERROR_CODE_BUSY);
		}
		// The rows were locked by findLiveRows after the session row, so the deletion takes no new lock.
		foreach ($evicted as $evictedToken)
		{
			$evictedRow = $liveRows[$evictedToken] ?? null;
			if ($evictedRow === null || $evictedRow['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				continue;
			}
			$delete = PlatformSessionTable::delete($evictedRow['ID']);
			if (!$delete->isSuccess())
			{
				return (new Result())->addErrors($delete->getErrors());
			}
		}

		return (new Result())->setData(['openToken' => $token, 'clientOrigin' => $origin]);
	}

	/**
	 * Writes the new latest attempt and drops attempts nothing can reach any more, so the vo metadata stays
	 * bounded by what is still alive: an attempt without debt, stop or owner pending, that is neither the
	 * latest of the session or of a participant nor held by a pending or active registry row, goes together
	 * with its origin snapshot. A participant whose latest attempt is resolved that way is forgotten: its
	 * next open is a new identity, as it would be anyway. Anything unknown or in debt stays.
	 *
	 * @param string[] $liveTokens tokens of pending and active registry rows, and any other token to keep
	 */
	private function pruneResolvedAttempts(
		array $vo,
		array $map,
		array $participants,
		string $token,
		array $liveTokens,
	): array
	{
		$live = array_fill_keys($liveTokens, true);
		$live[$token] = true;
		$isResolved = static fn(mixed $attempt): bool => !isset($live[$attempt])
			&& OpenAttemptState::isValid($map[$attempt] ?? null)
			&& !OpenAttemptState::hasDebt($map[$attempt])
		;
		foreach ($participants as $participant => $attempt)
		{
			if (is_string($attempt) && $isResolved($attempt))
			{
				unset($participants[$participant]);
			}
		}
		$kept = $live + array_fill_keys(array_filter($participants, 'is_string'), true);
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		foreach (array_keys($map) as $attempt)
		{
			$attempt = (string)$attempt;
			if (!isset($kept[$attempt]) && $isResolved($attempt))
			{
				unset($map[$attempt]);
				if (is_array($origins))
				{
					unset($origins[$attempt]);
				}
			}
		}

		$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY] = $map;
		$vo[DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY] = $token;
		$participantsKey = DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY;
		if ($participants !== [] || array_key_exists($participantsKey, $vo))
		{
			$vo[$participantsKey] = $participants;
		}
		if (is_array($origins))
		{
			$vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] = $origins;
		}

		return $vo;
	}

	/**
	 * Keeps at most {@see self::MAX_OPEN_ATTEMPTS} attempt states. Over the bound the oldest states without
	 * debt go first, with the participants they are the latest attempt of: such a state owes no send, and its
	 * participant opens with a new identity. The caller drops the pending row of a state given up that way: no
	 * send of it is outstanding, so it protects nothing. A state with a debt, a stop or a finalization not
	 * consumed yet is never dropped: null is returned when they alone exceed it.
	 *
	 * @param string[] $evictable tokens of pending and active registry rows
	 * @param string[] $evicted receives the tokens whose states were given up
	 */
	private function boundAttempts(array $vo, array $evictable, array &$evicted = []): ?array
	{
		$map = $vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY];
		if (count($map) <= self::MAX_OPEN_ATTEMPTS)
		{
			return $vo;
		}
		$evictable = array_fill_keys($evictable, true);
		$participantsKey = DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY;
		$participants = is_array($vo[$participantsKey] ?? null) ? $vo[$participantsKey] : [];
		$byToken = [];
		foreach ($participants as $participant => $attempt)
		{
			if (is_string($attempt))
			{
				$byToken[$attempt][] = $participant;
			}
		}
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		foreach (array_keys($map) as $attempt)
		{
			if (count($map) <= self::MAX_OPEN_ATTEMPTS)
			{
				break;
			}
			$attempt = (string)$attempt;
			if (
				!isset($evictable[$attempt])
				|| !OpenAttemptState::isValid($map[$attempt])
				|| OpenAttemptState::hasDebt($map[$attempt])
				|| $map[$attempt]['stop']
				|| ($map[$attempt]['finalized'] && !$map[$attempt]['consumed'])
			)
			{
				continue;
			}
			unset($map[$attempt]);
			$evicted[] = $attempt;
			if (is_array($origins))
			{
				unset($origins[$attempt]);
			}
			foreach ($byToken[$attempt] ?? [] as $participant)
			{
				unset($participants[$participant]);
			}
		}
		if (count($map) > self::MAX_OPEN_ATTEMPTS)
		{
			return null;
		}
		$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY] = $map;
		if (array_key_exists($participantsKey, $vo))
		{
			$vo[$participantsKey] = $participants;
		}
		if (is_array($origins))
		{
			$vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] = $origins;
		}

		return $vo;
	}

	/**
	 * Locked pending or active registry rows (ID and STATUS) of the given tokens, by token.
	 *
	 * @param array<int|string> $tokens
	 * @return array<string, array>
	 */
	private function findLiveRows(array $tokens): array
	{
		$tokens = array_values(array_filter(array_map('strval', $tokens), static fn(string $t): bool => $t !== ''));
		$live = [];
		foreach (array_chunk($tokens, 500) as $chunk)
		{
			$rows = $this->queryLocked(PlatformSessionTable::query()
				->setSelect(['ID', 'OPEN_TOKEN', 'STATUS'])
				->whereIn('OPEN_TOKEN', $chunk)
				->whereIn('STATUS', [PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_ACTIVE]));
			foreach ($rows as $row)
			{
				$live[(string)$row['OPEN_TOKEN']] = $row;
			}
		}

		return $live;
	}

	/**
	 * Admits one send of the token: under the document lock the attempt must still be pending, inside its
	 * local budget and not stopped; the first send binds the fingerprint, every later one must repeat it
	 * while a send is outstanding or the token is bound. A pending attempt without debt rebinds it.
	 * The outstanding counter is committed before the caller may touch the network.
	 */
	public function admitOpenSend(string $openToken, string $fingerprint): Result
	{
		if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1)
		{
			return $this->unresolved('Invalid Vibeoffice open fingerprint.');
		}

		return $this->withOpenToken($openToken, function (array $row) use ($openToken, $fingerprint): Result {
			$bound = $row['STATUS'] === PlatformSessionTable::STATUS_ACTIVE && $row['API_SESSION_ID'] !== null;
			if ($row['STATUS'] !== PlatformSessionTable::STATUS_PENDING && !$bound)
			{
				return $this->unresolved('Vibeoffice open attempt is no longer pending.');
			}
			if (
				!$row['CREATE_TIME'] instanceof DateTime
				|| time() - $row['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
			)
			{
				return (new Result())->addError(new Error(
					'Vibeoffice open operation expired.',
					self::ERROR_CODE_OPEN_EXPIRED,
				));
			}
			$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
			if (!$session || !$session->isActive())
			{
				return $this->failure('Vibeoffice session is no longer active.');
			}
			$sessionVo = DocumentSessionManager::extractVoMetadata($session);
			$state = $this->readAttemptState($sessionVo, $openToken);
			if ($state === false || ($state === null && !OpenAttemptState::isLegacyRow($sessionVo, (int)$row['ID'])))
			{
				return $this->unresolved('Vibeoffice open attempt state is unknown.');
			}
			// A bound token is replayed only as the latest attempt of its session, and only once its first
			// send bound the fingerprint: anything else would be a send no reservation stands for.
			if ($bound && (
				!is_array($state)
				|| $state['fingerprint'] === null
				|| $state['finalized']
				|| !OpenAttemptState::isLatestToken($sessionVo, $openToken)
			))
			{
				return $this->unresolved('Vibeoffice open attempt is no longer pending.');
			}
			$right = new OpenSendRight(
				$openToken,
				(string)$row['HOST_DOC_KEY'],
				(int)$session->getId(),
				$state !== null,
			);
			if ($state === null)
			{
				return (new Result())->setData(['right' => $right]);
			}

			$refusal = null;
			$admitted = (new DocumentSessionManager())->persistOpenAttemptTransition(
				$session,
				function (array $vo) use ($openToken, $fingerprint, $bound, &$refusal): ?array {
					$state = $this->readAttemptState($vo, $openToken);
					if (!is_array($state))
					{
						$refusal = 'Vibeoffice open attempt state is unknown.';

						return null;
					}
					if ($state['stop'] || $state['outstanding'] >= OpenAttemptState::MAX_OUTSTANDING)
					{
						$refusal = 'Vibeoffice open attempt admits no more sends.';

						return null;
					}
					// A pending attempt without debt owes no send, so a changed request may follow it under the
					// same token and origin snapshot; while a send is outstanding the first fingerprint binds.
					if (
						$state['fingerprint'] !== null
						&& !hash_equals($state['fingerprint'], $fingerprint)
						&& ($bound || $state['outstanding'] > 0)
					)
					{
						$refusal = 'Vibeoffice open request differs from the first send of its token.';

						return null;
					}
					$state['fingerprint'] = $fingerprint;
					$state['outstanding']++;
					$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY][$openToken] = $state;

					return $vo;
				},
			);
			if (!$admitted)
			{
				return $refusal !== null
					? $this->unresolved($refusal)
					: $this->failure('Could not admit Vibeoffice open send.', self::ERROR_CODE_BUSY)
				;
			}

			return (new Result())->setData(['right' => $right]);
		});
	}

	/**
	 * The once-finalizer of an admitted send. The right is consumed before the first DB access, so a
	 * failed or unknown commit is never repeated: the debt then stays for good.
	 *
	 * A trusted completed transport of this very send, or a proof that it never left the host, writes off
	 * its own debt; a trusted allow pair also stops the attempt. Once stopped with no debt left, the attempt
	 * is finalized in the same transaction: its pending row is dropped, and a closed attempt issues the
	 * single owner of its reopen.
	 *
	 * The data carries configAllowed (the attempt was not stopped before this answer), finalized, candidate,
	 * owner and sessionIds of the finalization.
	 *
	 * A success over an untrusted transport keeps the opening compatible: it writes off the debt of its own
	 * send unless the attempt is stopped, so it never stops, finalizes or grants a reopen.
	 *
	 * @param string|null $candidate {@see OpenAttemptState::CANDIDATE_CLOSED} or CANDIDATE_EXPIRED of a
	 *        trusted allow pair, null otherwise
	 */
	public function settleOpenSend(
		OpenSendRight $right,
		bool $writeOff,
		?string $candidate = null,
		bool $untrustedSuccess = false,
	): Result
	{
		if (!$right->consume())
		{
			return $this->failure('Vibeoffice open send was already settled.');
		}
		$settled = [
			'configAllowed' => true,
			'finalized' => false,
			'candidate' => OpenAttemptState::CANDIDATE_NONE,
			'owner' => null,
			'sessionIds' => [],
		];
		if (!$right->isAccounted())
		{
			return (new Result())->setData($settled);
		}
		if ($candidate !== null && $candidate !== OpenAttemptState::CANDIDATE_CLOSED
			&& $candidate !== OpenAttemptState::CANDIDATE_EXPIRED)
		{
			return $this->failure('Invalid Vibeoffice open outcome.');
		}

		return $this->stateRepository->withLockedDocument(
			$right->getHostDocKey(),
			function () use ($right, $writeOff, $candidate, $untrustedSuccess, $settled): Result {
				$session = $this->fetchLockedSession($right->getDocumentSessionId());
				if (!$session)
				{
					return $this->unresolved('Vibeoffice open attempt carrier is missing.');
				}
				$token = $right->getOpenToken();
				$transition = null;
				$applied = (new DocumentSessionManager())->persistOpenAttemptTransition(
					$session,
					function (array $vo) use ($token, $writeOff, $candidate, $untrustedSuccess, &$transition): ?array {
						$state = $this->readAttemptState($vo, $token);
						if (!is_array($state))
						{
							return null;
						}
						$before = $state;
						$writeOff = $writeOff || ($untrustedSuccess && $candidate === null && !$state['stop']);
						if ($writeOff && $state['outstanding'] > 0)
						{
							$state['outstanding']--;
						}
						if ($candidate !== null && !$state['finalized'])
						{
							$state['stop'] = true;
							$state['candidate'] = OpenAttemptState::raiseCandidate($state['candidate'], $candidate);
						}
						if ($state['stop'] && $state['outstanding'] === 0 && !$state['finalized'])
						{
							$state['finalized'] = true;
							if ($state['candidate'] === OpenAttemptState::CANDIDATE_CLOSED)
							{
								$state['owner'] = bin2hex(random_bytes(16));
							}
						}
						$transition = [$before, $state];
						$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY][$token] = $state;

						return $vo;
					},
				);
				if (!$applied || $transition === null)
				{
					return $this->unresolved('Vibeoffice open attempt state could not be settled.');
				}
				[$before, $after] = $transition;
				$settled['configAllowed'] = !$before['stop'];
				$settled['candidate'] = $after['candidate'];
				if ($before['finalized'] || !$after['finalized'])
				{
					return (new Result())->setData($settled);
				}

				$settled['finalized'] = true;
				$settled['owner'] = $after['owner'];
				$pending = $this->fetchLocked(PlatformSessionTable::query()
					->setSelect(['ID', 'STATUS'])
					->where('OPEN_TOKEN', $token));
				if ($pending && $pending['STATUS'] === PlatformSessionTable::STATUS_PENDING)
				{
					$delete = PlatformSessionTable::delete($pending['ID']);
					if (!$delete->isSuccess())
					{
						return (new Result())->addErrors($delete->getErrors());
					}
				}
				$settled['sessionIds'] = $this->eligibleSessionIds([$right->getDocumentSessionId()]);

				return (new Result())->setData($settled);
			},
		);
	}

	/**
	 * The state of the token: an array for an attempt reserved with a state, null for a legacy attempt
	 * reserved before it, false when the state is missing or corrupt although it must exist.
	 */
	private function readAttemptState(array $vo, string $token): array|false|null
	{
		$map = OpenAttemptState::readMap($vo);
		if ($map === null)
		{
			return false;
		}
		if (!array_key_exists($token, $map))
		{
			return OpenAttemptState::readLastToken($vo) === $token ? false : null;
		}

		return OpenAttemptState::isValid($map[$token]) ? $map[$token] : false;
	}

	private function fetchLockedSession(int $sessionId): ?DocumentSession
	{
		$row = $this->fetchLocked(DocumentSessionTable::query()->setSelect(['*'])->where('ID', $sessionId));

		return $row ? DocumentSession::buildFromArray($row) : null;
	}

	private function getClientOriginProvider(): ClientOriginProviderInterface
	{
		return $this->clientOriginProvider ??= new ConfiguredClientOriginProvider(
			ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration'),
		);
	}

	/** Returns terminal=true and eligible sessionIds when a close won the race with open. */
	public function completeOpen(string $openToken, array $metadata): Result
	{
		$uuid = $this->normalizeUuid($metadata['session_id'] ?? null);
		$editorKey = $metadata['editor_key'] ?? null;
		$rev = $metadata['rev'] ?? null;
		if (!$uuid || !$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice open metadata.');
		}

		return $this->withOpenToken($openToken, function (array $pending) use ($uuid, $editorKey, $rev): Result {
			$hostDocKey = $pending['HOST_DOC_KEY'];
			$sessionId = (int)$pending['DOCUMENT_SESSION_ID'];
			if ($pending['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				if ($pending['API_SESSION_ID'] !== $uuid)
				{
					return $this->failure('Vibeoffice open was already completed with another UUID.');
				}

				return $this->completionResult($pending, $sessionId);
			}

			$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $sessionId);
			$sessionRow = $this->fetchLocked($query);
			$session = $sessionRow ? DocumentSession::buildFromArray($sessionRow) : null;
			if (!$session || !$session->isActive())
			{
				return $this->failure('Vibeoffice session is no longer active.');
			}

			$known = $this->findUuid($uuid);
			if ($known && (
				$known['HOST_DOC_KEY'] !== $hostDocKey
				|| ((int)$known['DOCUMENT_SESSION_ID'] > 0 && (int)$known['DOCUMENT_SESSION_ID'] !== $sessionId)
			))
			{
				return $this->failure('Vibeoffice UUID is already bound to another session.');
			}

			$terminal = ($known && $known['STATUS'] === PlatformSessionTable::STATUS_TERMINATED)
				|| $this->isEditorGenerationTerminated($hostDocKey, $editorKey, $rev);
			$fields = [
				'API_SESSION_ID' => $uuid,
				'DOCUMENT_SESSION_ID' => $sessionId,
				'OPEN_TOKEN' => $pending['OPEN_TOKEN'],
				'EDITOR_KEY' => $editorKey,
				'REV' => $rev,
				'STATUS' => $terminal ? PlatformSessionTable::STATUS_TERMINATED : PlatformSessionTable::STATUS_ACTIVE,
				'UPDATE_TIME' => new DateTime(),
			];
			if ($known)
			{
				$delete = PlatformSessionTable::delete($pending['ID']);
				if (!$delete->isSuccess())
				{
					return (new Result())->addErrors($delete->getErrors());
				}
			}
			$update = PlatformSessionTable::update($known['ID'] ?? $pending['ID'], $fields);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}

			if (!(new DocumentSessionManager())->persistSessionMetadata($session, [
				'session_id' => $uuid,
				'editor_key' => $editorKey,
				'rev' => $rev,
			]))
			{
				return $this->failure('Could not persist Vibeoffice open metadata.', self::ERROR_CODE_BUSY);
			}

			return $this->completionResult($fields + $pending, $sessionId);
		});
	}

	/**
	 * A local cancel proves nothing about the request already sent with the token: the pending
	 * attempt, its token and snapshot stay, and no local session becomes eligible for finalization.
	 */
	public function cancelOpen(string $openToken): Result
	{
		if (!$this->normalizeUuid($openToken))
		{
			return $this->failure('Invalid Vibeoffice open token.');
		}

		return (new Result())->setData(['sessionIds' => []]);
	}

	/**
	 * Drops the pending attempt. Only for a caller that holds a definitive answer of the platform
	 * on this very token and a known state without outstanding sends; any other outcome is retained.
	 */
	public function cancelDefinitiveOpen(string $openToken): Result
	{
		return $this->withOpenToken($openToken, function (array $row) use ($openToken): Result {
			if ($row['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				return (new Result())->setData(['sessionIds' => []]);
			}
			$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
			$state = $session
				? $this->readAttemptState(DocumentSessionManager::extractVoMetadata($session), $openToken)
				: null;
			if (!is_array($state) || OpenAttemptState::hasDebt($state))
			{
				return $this->unresolved('Vibeoffice open attempt still has outstanding sends.');
			}

			$result = PlatformSessionTable::delete($row['ID']);

			return $result->isSuccess()
				? (new Result())->setData(['sessionIds' => $this->eligibleSessionIds([(int)$row['DOCUMENT_SESSION_ID']])])
				: (new Result())->addErrors($result->getErrors());
		});
	}

	/** Explicit UUID termination is authoritative in this host key, regardless of editor_key. */
	public function applyClosed(string $hostDocKey, array $terminated, array $retained): Result
	{
		$terminated = $this->normalizeUuids($terminated);
		$retained = $this->normalizeUuids($retained);
		if ($terminated === null || $retained === null || array_intersect($terminated, $retained))
		{
			return $this->failure('Invalid Vibeoffice closed session lists.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function (array $state) use (
			$hostDocKey, $terminated, $retained,
		): Result {
			$sessionIds = [];
			$closed = false;
			foreach ([PlatformSessionTable::STATUS_TERMINATED => $terminated, PlatformSessionTable::STATUS_ACTIVE => $retained] as $status => $uuids)
			{
				foreach ($uuids as $uuid)
				{
					$row = $this->findUuid($uuid);
					if ($row && $row['HOST_DOC_KEY'] !== $hostDocKey)
					{
						return $this->failure('Vibeoffice UUID belongs to another document.');
					}
					if ($row)
					{
						if ($status === PlatformSessionTable::STATUS_TERMINATED)
						{
							$sessionIds[] = (int)$row['DOCUMENT_SESSION_ID'];
							if ($row['STATUS'] === PlatformSessionTable::STATUS_TERMINATED)
							{
								continue;
							}
							$result = PlatformSessionTable::update($row['ID'], [
								'STATUS' => $status,
								'UPDATE_TIME' => new DateTime(),
							]);
						}
						else
						{
							continue;
						}
					}
					else
					{
						$result = PlatformSessionTable::add([
							'HOST_DOC_KEY' => $hostDocKey,
							'API_SESSION_ID' => $uuid,
							'STATUS' => $status,
						]);
					}
					if (!$result->isSuccess())
					{
						return (new Result())->addErrors($result->getErrors());
					}
					$closed = $closed || $status === PlatformSessionTable::STATUS_TERMINATED;
				}
			}

			if ($closed)
			{
				$touch = DocumentStateTable::update((int)$state['ID'], ['UPDATED_TIME' => new DateTime()]);
				if (!$touch->isSuccess())
				{
					return (new Result())->addErrors($touch->getErrors());
				}
			}

			return (new Result())->setData(['sessionIds' => $this->eligibleSessionIds($sessionIds)]);
		});
	}

	/** A marker without a UUID also fences late open responses from this exact editor generation. */
	public function terminateEditorGeneration(string $hostDocKey, string $editorKey, ?int $rev = null): Result
	{
		if (!$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice editor generation.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function (array $state) use (
			$hostDocKey, $editorKey, $rev,
		): Result {
			$query = $this->editorQuery($hostDocKey, $editorKey, $rev);
			$rows = $this->queryLocked($query);
			$sessionIds = [];
			$hasMarker = false;
			$closed = false;
			foreach ($rows as $row)
			{
				$hasMarker = $hasMarker || (!$row['API_SESSION_ID'] && !$row['OPEN_TOKEN'] && !$row['DOCUMENT_SESSION_ID']);
				$sessionIds[] = (int)$row['DOCUMENT_SESSION_ID'];
				if ($row['STATUS'] === PlatformSessionTable::STATUS_TERMINATED)
				{
					continue;
				}
				$result = PlatformSessionTable::update($row['ID'], [
					'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
					'UPDATE_TIME' => new DateTime(),
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
				$closed = true;
			}
			if (!$hasMarker)
			{
				$result = PlatformSessionTable::add([
					'HOST_DOC_KEY' => $hostDocKey,
					'EDITOR_KEY' => $editorKey,
					'REV' => $rev,
					'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
				$closed = true;
			}

			if ($closed)
			{
				$touch = DocumentStateTable::update((int)$state['ID'], ['UPDATED_TIME' => new DateTime()]);
				if (!$touch->isSuccess())
				{
					return (new Result())->addErrors($touch->getErrors());
				}
			}

			return (new Result())->setData(['sessionIds' => $this->eligibleSessionIds($sessionIds)]);
		});
	}

	public function hasLiveSessions(int $documentSessionId): bool
	{
		return (bool)$this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT_SESSION_ID', $documentSessionId)
			->where($this->liveFilter())
			->setLimit(1));
	}

	public function hasLiveSessionsForHash(string $externalHash): bool
	{
		$sessions = DocumentSessionTable::query()
			->setSelect(['ID'])
			->where('EXTERNAL_HASH', $externalHash)
			->where('SERVICE', DocumentService::Vibeoffice->value);

		return (bool)$this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID'])
			->whereIn('DOCUMENT_SESSION_ID', $sessions)
			->where($this->liveFilter())
			->setLimit(1));
	}

	/** Mapped local IDs of every status; callers must check liveness before finalizing them. */
	public function getSessionIds(string $hostDocKey, ?string $editorKey = null): array
	{
		$query = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])->where('HOST_DOC_KEY', $hostDocKey);
		if ($editorKey !== null)
		{
			$query->where('EDITOR_KEY', $editorKey);
		}

		return array_values(array_unique(array_filter(array_map(
			static fn(array $row): int => (int)$row['DOCUMENT_SESSION_ID'],
			$query->fetchAll(),
		))));
	}

	/** Batches are a memory bound, not a limit on retained history. */
	public function getSessionIdBatches(string $hostDocKey): \Generator
	{
		$lastId = 0;
		do
		{
			$rows = PlatformSessionTable::query()->setSelect(['ID', 'DOCUMENT_SESSION_ID'])
				->where('HOST_DOC_KEY', $hostDocKey)->whereNotNull('DOCUMENT_SESSION_ID')
				->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(100)->fetchAll();
			if ($rows)
			{
				$lastId = (int)end($rows)['ID'];
				yield array_values(array_unique(array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'))));
			}
		}
		while (count($rows) === 100);
	}

	public function getRecipientSessionIds(string $hostDocKey, string $editorKey, int $rev): array
	{
		$queries = [
			PlatformSessionTable::query()->where('HOST_DOC_KEY', $hostDocKey)->where($this->liveFilter()),
			$this->editorQuery($hostDocKey, $editorKey, $rev)->whereIn('STATUS', [
				PlatformSessionTable::STATUS_ACTIVE, PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_TERMINATED,
			]),
		];
		$ids = [];
		foreach ($queries as $query)
		{
			$rows = $query->setSelect(['DOCUMENT_SESSION_ID'])->setDistinct()
				->whereNotNull('DOCUMENT_SESSION_ID')->fetchAll();
			foreach ($rows as $row)
			{
				$id = (int)$row['DOCUMENT_SESSION_ID'];
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/** A cancelled HTTP attempt alone does not prove that its local session is terminal. */
	public function getTerminatedSessionIds(array $sessionIds): array
	{
		if ($sessionIds === [])
		{
			return [];
		}
		$rows = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])
			->whereIn('DOCUMENT_SESSION_ID', $sessionIds)
			->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)
			->whereNotNull('API_SESSION_ID')->fetchAll();

		return array_values(array_unique(array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'))));
	}

	private function withOpenToken(string $openToken, callable $mutation): Result
	{
		if (!$this->normalizeUuid($openToken))
		{
			return $this->failure('Invalid Vibeoffice open token.');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$row = PlatformSessionTable::query()->setSelect(['HOST_DOC_KEY'])->where('OPEN_TOKEN', $openToken)->fetch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
		if (!$row)
		{
			return $this->failure('Vibeoffice open reservation was not found.');
		}

		return $this->stateRepository->withLockedDocument($row['HOST_DOC_KEY'], function () use ($openToken, $mutation): Result {
			$row = $this->fetchLocked(PlatformSessionTable::query()->setSelect(['*'])->where('OPEN_TOKEN', $openToken));

			return $row ? $mutation($row) : $this->failure('Vibeoffice open reservation was not found.');
		});
	}

	private function completionResult(array $row, int $sessionId): Result
	{
		return (new Result())->setData([
			'terminal' => $row['STATUS'] === PlatformSessionTable::STATUS_TERMINATED,
			'sessionIds' => $this->eligibleSessionIds([$sessionId]),
			'hostDocKey' => $row['HOST_DOC_KEY'],
		]);
	}

	private function liveFilter(): \Bitrix\Main\ORM\Query\Filter\ConditionTree
	{
		return Query::filter()->logic('or')
			->where('STATUS', PlatformSessionTable::STATUS_ACTIVE)
			->where('STATUS', PlatformSessionTable::STATUS_PENDING);
	}

	private function eligibleSessionIds(array $sessionIds): array
	{
		return array_values(array_filter(array_unique($sessionIds), fn(int $id): bool => $id > 0 && !$this->hasLiveSessions($id)));
	}

	private function findUuid(string $uuid): ?array
	{
		return $this->fetchLocked(PlatformSessionTable::query()->setSelect(['*'])->where('API_SESSION_ID', $uuid));
	}

	private function editorQuery(string $hostDocKey, string $editorKey, ?int $rev): Query
	{
		$query = PlatformSessionTable::query()->setSelect(['*'])->where('HOST_DOC_KEY', $hostDocKey)->where('EDITOR_KEY', $editorKey);
		if ($rev !== null)
		{
			$query->where('REV', $rev);
		}

		return $query;
	}

	private function isEditorGenerationTerminated(string $hostDocKey, string $editorKey, ?int $rev): bool
	{
		$revision = Query::filter()->whereNull('REV');
		if ($rev !== null)
		{
			$revision->logic('or')->where('REV', $rev);
		}
		$query = $this->editorQuery($hostDocKey, $editorKey, null)->setSelect(['ID'])
			->whereNull('API_SESSION_ID')->whereNull('OPEN_TOKEN')->whereNull('DOCUMENT_SESSION_ID')
			->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)->where($revision)->setLimit(1);

		return $this->fetchLocked($query) !== null;
	}

	private function fetchLocked(Query $query): ?array
	{
		return DocumentStateRepository::fetchForUpdate($query);
	}

	private function queryLocked(Query $query): array
	{
		return DocumentStateRepository::fetchAllForUpdate($query);
	}

	private function normalizeUuid(mixed $uuid): ?string
	{
		return is_string($uuid) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid)
			? strtolower($uuid) : null;
	}

	private function normalizeUuids(array $uuids): ?array
	{
		if (count($uuids) > self::MAX_EVENT_SESSIONS)
		{
			return null;
		}
		$result = [];
		foreach ($uuids as $uuid)
		{
			$uuid = $this->normalizeUuid($uuid);
			if (!$uuid)
			{
				return null;
			}
			$result[$uuid] = $uuid;
		}

		return array_values($result);
	}

	private function isEditorKey(mixed $editorKey): bool
	{
		return is_string($editorKey) && $editorKey !== '' && strlen($editorKey) <= 255;
	}

	private function isRevision(mixed $rev): bool
	{
		return $rev === null || (is_int($rev) && $rev >= 0 && $rev <= 2_147_483_647);
	}

	private function unresolved(string $message): Result
	{
		return $this->failure($message, self::ERROR_CODE_OPEN_UNRESOLVED);
	}

	private function failure(string $message, string $code = self::ERROR_CODE_FAILED): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
