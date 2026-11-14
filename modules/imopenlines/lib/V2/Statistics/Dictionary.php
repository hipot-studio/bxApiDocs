<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenLines\Session;

/**
 * Single source of truth for enum mappings used by imopenlines.v2 statistics REST methods.
 *
 * All mappers are pure functions over session fields: they do not query the database
 * and do not know anything about REST transport, so they can be reused by any of the
 * six statistics methods (and by their tests) without duplicating enum logic.
 */
final class Dictionary
{
	public const SESSION_STATUS_NEW = 'new';
	public const SESSION_STATUS_ANSWERED = 'answered';
	public const SESSION_STATUS_CLOSED = 'closed';
	public const SESSION_STATUS_SPAM = 'spam';
	public const SESSION_STATUS_PAUSED = 'paused';

	public const CLOSE_REASON_SPAM = 'spam';
	public const CLOSE_REASON_REPLY_LIMIT = 'replyLimit';
	public const CLOSE_REASON_AUTO = 'auto';
	public const CLOSE_REASON_OPERATOR = 'operator';
	public const CLOSE_REASON_CLIENT = 'client';

	public const VOTE_LIKE = 'like';
	public const VOTE_DISLIKE = 'dislike';
	public const VOTE_NONE = 'none';

	public const TRANSFER_REASON_MANUAL = 'manual';
	public const TRANSFER_REASON_QUEUE = 'queue';
	public const TRANSFER_REASON_AUTO = 'auto';
	public const TRANSFER_REASON_LINE = 'line';

	private const TRANSFER_MODE_MANUAL = 'MANUAL';
	private const TRANSFER_MODE_AUTO = 'AUTO';
	private const TRANSFER_MODE_BOT = 'BOT';
	private const TRANSFER_TYPE_USER = 'USER';
	private const TRANSFER_TYPE_QUEUE = 'QUEUE';

	/**
	 * Maps a session row to the REST session status enum.
	 *
	 * Expected keys in $session: STATUS (int), CLOSED ('Y'/'N'), SPAM ('Y'/'N'), PAUSE ('Y'/'N').
	 * Priority (highest first): spam, closed, paused, answered, new — a session can be
	 * simultaneously CLOSED='Y' and SPAM='Y', so spam must win before closed is checked.
	 */
	public static function getSessionStatus(array $session): string
	{
		$status = (int)($session['STATUS'] ?? Session::STATUS_NEW);
		$isSpam = ($session['SPAM'] ?? 'N') === 'Y';
		$isClosed = ($session['CLOSED'] ?? 'N') === 'Y';
		$isPaused = ($session['PAUSE'] ?? 'N') === 'Y';

		if ($isSpam || $status === Session::STATUS_SPAM)
		{
			return self::SESSION_STATUS_SPAM;
		}

		if (
			$isClosed
			|| $status === Session::STATUS_CLOSE
			|| $status === Session::STATUS_SILENTLY_CLOSE
			|| $status === Session::STATUS_DUPLICATE
		)
		{
			return self::SESSION_STATUS_CLOSED;
		}

		if ($isPaused)
		{
			return self::SESSION_STATUS_PAUSED;
		}

		if (
			$status === Session::STATUS_NEW
			|| $status === Session::STATUS_SKIP
		)
		{
			return self::SESSION_STATUS_NEW;
		}

		if ($status >= Session::STATUS_ANSWER && $status <= Session::STATUS_WAIT_CLIENT)
		{
			return self::SESSION_STATUS_ANSWERED;
		}

		return self::SESSION_STATUS_NEW;
	}

	/**
	 * Maps a session row to the REST close reason enum.
	 *
	 * Expected keys in $session: STATUS (int), SPAM ('Y'/'N'), BLOCK_REASON (string|null),
	 * BLOCK_DATE (string|DateTime|null), DATE_CLOSE (string|DateTime|null).
	 *
	 * IS_CLOSED_BY_OPERATOR ('Y'/'N') is not a persisted session table column — the session
	 * model does not store who finished the dialog. It is a flag the caller must derive from
	 * its own context (e.g. an explicit operator action vs. `Session::finish($auto = true)` /
	 * an automatic timeout) and pass in here. Defaults to 'N' (i.e. "not operator") when absent,
	 * so an unspecified close falls through to `client`.
	 *
	 * Priority (highest first):
	 * 1. spam;
	 * 2. reply limit (BLOCK_REASON is set and BLOCK_DATE <= DATE_CLOSE, and the session
	 *    was not closed by an operator and is not spam);
	 * 3. auto (STATUS_SILENTLY_CLOSE);
	 * 4. operator;
	 * 5. client (closed because of client inactivity — the fallback).
	 */
	public static function getCloseReason(array $session): string
	{
		$status = (int)($session['STATUS'] ?? Session::STATUS_NEW);
		$isSpam = ($session['SPAM'] ?? 'N') === 'Y';
		$isClosedByOperator = ($session['IS_CLOSED_BY_OPERATOR'] ?? 'N') === 'Y';

		if ($isSpam || $status === Session::STATUS_SPAM)
		{
			return self::CLOSE_REASON_SPAM;
		}

		if (
			!empty($session['BLOCK_REASON'])
			&& self::isBlockDateBeforeClose($session)
			&& !$isClosedByOperator
		)
		{
			return self::CLOSE_REASON_REPLY_LIMIT;
		}

		if ($status === Session::STATUS_SILENTLY_CLOSE)
		{
			return self::CLOSE_REASON_AUTO;
		}

		if ($isClosedByOperator)
		{
			return self::CLOSE_REASON_OPERATOR;
		}

		return self::CLOSE_REASON_CLIENT;
	}

	/**
	 * Maps the numeric client vote mark (Session::VOTE field) to the REST vote enum.
	 *
	 * This mapper is ONLY for the client vote. The encoding is not a signed scale:
	 * `Session::VOTE_LIKE` (5) is a like, `Session::VOTE_DISLIKE` (1) is a dislike,
	 * `0` means no vote — any other value is treated as no vote.
	 *
	 * `VOTE_HEAD` (the supervisor/head rating) uses a DIFFERENT scale — a 1..5 star
	 * rating where 0/null means "no rating" — and is NOT run through this mapper.
	 * It is exposed as a raw number (1..5) or null directly by the phase 2-3 REST
	 * methods.
	 */
	public static function getVote(int $vote): string
	{
		return match ($vote)
		{
			Session::VOTE_LIKE => self::VOTE_LIKE,
			Session::VOTE_DISLIKE => self::VOTE_DISLIKE,
			default => self::VOTE_NONE,
		};
	}

	/**
	 * Maps transfer fields (as stored in b_imopenlines_operator_transfer) to the REST
	 * transfer reason enum.
	 *
	 * Expected keys in $session: TRANSFER_MODE ('MANUAL'/'AUTO'/'BOT'), TRANSFER_TYPE ('USER'/'QUEUE'),
	 * TRANSFER_LINE_ID (int).
	 *
	 * $currentConfigId is the CONFIG_ID (line) of the session at the time of the transfer.
	 * It is required to distinguish a transfer to another line from a same-line queue
	 * requeue: per Chat::transferToQueue() (chat.php), TRANSFER_LINE_ID is ALWAYS populated
	 * for TRANSFER_TYPE = QUEUE (it defaults to the current line id when transferring back
	 * into the same queue), so `TRANSFER_LINE_ID > 0` alone can never distinguish the two
	 * and would make the `queue` branch unreachable.
	 *
	 * Priority:
	 * 1. `line` — TRANSFER_LINE_ID is set and differs from the session's own CONFIG_ID,
	 *    i.e. an explicit transfer to a different line;
	 * 2. `manual` — TRANSFER_MODE = MANUAL and TRANSFER_TYPE = USER;
	 * 3. `queue` — TRANSFER_MODE = AUTO and TRANSFER_TYPE = QUEUE (requeue within the same line);
	 * 4. `auto` — everything else, including TRANSFER_MODE = AUTO with TRANSFER_TYPE = USER
	 *    and TRANSFER_MODE = BOT (see Chat::TRANSFER_MODE_BOT, chat.php:57-59).
	 */
	public static function getTransferReason(array $session, int $currentConfigId = 0): string
	{
		$transferLineId = (int)($session['TRANSFER_LINE_ID'] ?? 0);
		if ($transferLineId > 0 && $transferLineId !== $currentConfigId)
		{
			return self::TRANSFER_REASON_LINE;
		}

		$mode = mb_strtoupper((string)($session['TRANSFER_MODE'] ?? self::TRANSFER_MODE_MANUAL));
		$type = mb_strtoupper((string)($session['TRANSFER_TYPE'] ?? self::TRANSFER_TYPE_USER));

		if ($mode === self::TRANSFER_MODE_MANUAL && $type === self::TRANSFER_TYPE_USER)
		{
			return self::TRANSFER_REASON_MANUAL;
		}

		if ($mode === self::TRANSFER_MODE_AUTO && $type === self::TRANSFER_TYPE_QUEUE)
		{
			return self::TRANSFER_REASON_QUEUE;
		}

		return self::TRANSFER_REASON_AUTO;
	}

	private static function isBlockDateBeforeClose(array $session): bool
	{
		$blockDate = self::toTimestamp($session['BLOCK_DATE'] ?? null);
		$dateClose = self::toTimestamp($session['DATE_CLOSE'] ?? null);

		if ($blockDate === null || $dateClose === null)
		{
			return false;
		}

		return $blockDate <= $dateClose;
	}

	private static function toTimestamp(mixed $date): ?int
	{
		if ($date === null || $date === '')
		{
			return null;
		}

		if ($date instanceof \Bitrix\Main\Type\DateTime)
		{
			return $date->getTimestamp();
		}

		$timestamp = strtotime((string)$date);

		return $timestamp === false ? null : $timestamp;
	}
}
