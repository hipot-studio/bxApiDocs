<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internals\SourceGenerationTailMarkTable;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;

/**
 * The fallback route of the recognition: the letters the tail append stage puts onto a
 * source that answers no coordinates to an append.
 *
 * The main route needs nothing of this. A source that reports APPENDUID names the folder
 * epoch and the number it gave the letter, so the stage computes the placement row of it
 * and writes a terminal verdict straight into the matching journal - the reverse read then
 * stops at that verdict without comparing anything ({@see TailAppendService}). Some servers
 * report no such pair, and for a letter of theirs there is nothing a verdict could be
 * addressed by: the coordinates are what addresses it, and they are never learned.
 *
 * So such a letter carries a mark of ours instead - random, one time, and readable back out
 * of the MIME. What the mark is NOT is the decision: the identity comes from the row this
 * service writes BEFORE the letter reaches the server, and a mark with no row leads nowhere.
 * The order is the whole of it, and it is the same order the stage keeps everywhere else:
 * write down what is about to happen, then let it happen.
 *
 * The mark is not stored. What the row keeps is MARK_HASH, the SHA-256 of it in hex, the way
 * the fingerprints of the matching keep their values: a reader of the database learns which
 * letters have a mark and never which mark, so it cannot compose a letter that claims one.
 *
 * Where it differs from the two headers the module already has:
 *
 * - The header of the send path (X-Bitrix-Mail-Message-UID) carries an open and guessable
 *   placement row id, is read in any run of any synchronization and any folder, and its
 *   handling REWRITES the row it names. This mark is random, is read only inside the
 *   declared operation ({@see Context::acceptsTailMark()}) and leads to a new placement
 *   being linked, never to an old one being rewritten.
 * - The transferable identifier of a managed transfer service arrives from outside and is
 *   written by code that is not ours, which is why a match by it still has to be confirmed
 *   by the comparison of the letters. The ground of trust here is a different one: we wrote
 *   the record down before the letter existed on that server.
 *
 * The residual risk remains explicit rather than covered: the mark lies
 * physically inside a letter in the mailbox of the client, so whoever already has access to
 * that mailbox can read it and put a letter of their own carrying the same mark there before
 * we read ours back. The lifetime below is what bounds the window it is worth trying in.
 */
class TailMarkService
{
	/**
	 * The header the mark travels in. Not an X-Bitrix-Mail-* name on purpose: those are read
	 * by the send path of the module in any generation and any folder, and this one must be
	 * read by nothing but the import of a prepared generation.
	 */
	public const HEADER = 'X-Bitrix-Mail-Tail-Mark';

	/** Bytes of randomness behind one mark, spelled out in hex - so 64 characters of it */
	private const MARK_BYTES = 32;

	/**
	 * How long a written mark can still be recognized.
	 *
	 * The stage itself works in a window of four hours and the delta pass of the switch reads
	 * the appended letters back right after it, so days of slack cover an operation that
	 * stalled over a weekend and was resumed. Longer would widen the one window the residual
	 * risk above lives in, for nothing: past it the letter is read by the ordinary route of
	 * the matching, which is the route every letter the transfer service carried over goes
	 * anyway.
	 */
	private const LIFETIME = 3 * 86400;

	/**
	 * The mark the letter is to carry, written down before the letter goes anywhere.
	 *
	 * @param string $dirMd5 The folder of the prepared generation the letter is appended into,
	 *        named the way a placement row names one.
	 * @return string|null The mark to put into the MIME; null when this letter must not be
	 *         appended at all - either the reverse read has recognized it already, or another
	 *         pass over the same remainder holds it.
	 * @throws SqlQueryException
	 */
	public function issue(Context $context, int $messageId, string $dirMd5): ?string
	{
		if (!$context->acceptsTailMark() || $messageId <= 0)
		{
			return null;
		}

		$mark = bin2hex(random_bytes(self::MARK_BYTES));
		$now = new DateTime();
		$row = $this->loadRowOfLetter($context, $messageId);

		if ($row === null)
		{
			try
			{
				SourceGenerationTailMarkTable::add([
					'MAILBOX_ID' => $context->mailboxId,
					'GENERATION_ID' => $context->generationId,
					'MESSAGE_ID' => $messageId,
					'MARK_HASH' => $this->hash($mark),
					'STATE' => SourceGenerationTailMarkTable::STATE_PENDING,
					'DIR_MD5' => $dirMd5,
					'ATTEMPTS' => 1,
					'DATE_CREATE' => $now,
					'DATE_UPDATE' => $now,
				]);
			}
			catch (SqlQueryException)
			{
				/*
					The pair of the generation and the letter is unique, and this is the lock the
					stage has in no other form: the pass that lost the race leaves the letter to the
					one that holds it instead of appending a second copy of it.
				*/
				return null;
			}

			return $mark;
		}

		if ((string)$row['STATE'] === SourceGenerationTailMarkTable::STATE_USED)
		{
			// The letter is on the new source and has been read back off it: it goes up once
			return null;
		}

		/*
			A record left PENDING by a pass that died is rotated onto a new mark, and the old one
			dies with it. Safe exactly because the stage resolves such a letter before the walk
			gets to it: the recovery of the append in flight asks the folder whether the letter is
			there, and moves the cursor past it when it is. So a letter the walk still offers here
			is a letter the new source does not hold, and no letter carrying the old mark exists.
		*/
		SourceGenerationTailMarkTable::update((int)$row['ID'], [
			'MARK_HASH' => $this->hash($mark),
			'STATE' => SourceGenerationTailMarkTable::STATE_PENDING,
			'DIR_MD5' => $dirMd5,
			'ATTEMPTS' => (int)$row['ATTEMPTS'] + 1,
			'DATE_CREATE' => $now,
			'DATE_UPDATE' => $now,
		]);

		return $mark;
	}

	/**
	 * The letter a mark read out of an incoming MIME stands for.
	 *
	 * Every refusal here is one the decision asks for by name. A mark nobody issued has no
	 * row: the letter carrying it is not ours, whoever wrote it. A row past its lifetime is
	 * not answered either. And a row already USED is terminal, so a second letter repeating
	 * the mark of the first gets nothing - which is what makes the mark a one time one and
	 * not a password to a message of the mailbox.
	 *
	 * The folder is deliberately not compared, and the written one is not overwritten either.
	 * The reverse read runs the blacklist of the portal, so a letter whose sender was
	 * blacklisted after it arrived is moved to spam or to the bin on the server: a folder that
	 * disagrees with the written one is no ground to refuse a letter we appended ourselves, and
	 * the disagreement itself is worth keeping. Where the letter really lies is answered by its
	 * placement row anyway; what the record keeps is where we put it.
	 *
	 * @param array $fields The physical row the reverse read registered the letter under. The
	 *        coordinates of it are what the record learns here - the append never named them,
	 *        which is the whole reason this route exists.
	 * @return int The local letter, or 0 when the mark leads nowhere and the ordinary route
	 *         of the matching decides.
	 */
	public function recognize(Context $context, string $mark, array $fields = []): int
	{
		if (!$context->acceptsTailMark() || $mark === '')
		{
			return 0;
		}

		$row = SourceGenerationTailMarkTable::getRow([
			'select' => ['ID', 'MESSAGE_ID', 'STATE', 'DATE_CREATE'],
			'filter' => [
				'=MARK_HASH' => $this->hash($mark),
				'=MAILBOX_ID' => $context->mailboxId,
				'=GENERATION_ID' => $context->generationId,
			],
		]);

		if ($row === null || (string)$row['STATE'] !== SourceGenerationTailMarkTable::STATE_PENDING)
		{
			return 0;
		}

		$createdAt = $row['DATE_CREATE'] instanceof DateTime ? $row['DATE_CREATE']->getTimestamp() : 0;

		if ($createdAt <= 0 || $createdAt + self::LIFETIME < time())
		{
			return 0;
		}

		$messageId = (int)$row['MESSAGE_ID'];

		if ($messageId <= 0)
		{
			return 0;
		}

		SourceGenerationTailMarkTable::update((int)$row['ID'], [
			'STATE' => SourceGenerationTailMarkTable::STATE_USED,
			'DIR_UIDV' => (int)($fields['DIR_UIDV'] ?? 0),
			'MSG_UID' => (int)($fields['MSG_UID'] ?? 0),
			'DATE_UPDATE' => new DateTime(),
		]);

		return $messageId;
	}

	/**
	 * Closes the record of a letter the main route has answered for after all: the source
	 * named the coordinates of this one, so its verdict is in the matching journal and the
	 * mark inside it will never be asked about.
	 */
	public function settle(Context $context, int $messageId, array $coordinates = []): void
	{
		$row = $this->loadRowOfLetter($context, $messageId);

		if ($row === null || (string)$row['STATE'] !== SourceGenerationTailMarkTable::STATE_PENDING)
		{
			return;
		}

		SourceGenerationTailMarkTable::update((int)$row['ID'], [
			'STATE' => SourceGenerationTailMarkTable::STATE_USED,
			'DIR_UIDV' => (int)($coordinates['uidValidity'] ?? 0),
			'MSG_UID' => (int)($coordinates['uid'] ?? 0),
			'DATE_UPDATE' => new DateTime(),
		]);
	}

	/**
	 * Whether the record of the letter is terminal already: the letter is on the new source
	 * and either the append named its coordinates {@see settle()} or the reverse read has
	 * recognized it by its mark {@see recognize()}.
	 *
	 * This is one of the two answers a resumed pass accepts as a proof that the append of a
	 * letter really happened - the other one is a verdict of the matching journal. Anything
	 * short of a proof leaves the letter to be appended again: a duplicate is a letter the
	 * user can see, and a letter nobody appended is a letter nobody will ever see again.
	 */
	public function isSettled(Context $context, int $messageId): bool
	{
		$row = $this->loadRowOfLetter($context, $messageId);

		return $row !== null && (string)$row['STATE'] === SourceGenerationTailMarkTable::STATE_USED;
	}

	/**
	 * The letters of the list an earlier walk of this generation issued a mark for, whatever the
	 * state of the record: a pending one is a letter appended and not read back yet. The walk over
	 * the folders a hand over reopened passes over them {@see TailAppendService}: on a source naming
	 * no number the mark is all that tells a letter the first walk put there.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true>
	 */
	public function findMarkedOf(Context $context, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = SourceGenerationTailMarkTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=GENERATION_ID' => $context->generationId,
				'@MESSAGE_ID' => array_map('intval', $messageIds),
			],
		])->fetchAll();

		return array_fill_keys(array_map(static fn (array $row): int => (int)$row['MESSAGE_ID'], $rows), true);
	}

	/**
	 * The SHA-256 in hex, the spelling every stored hash of the matching uses.
	 */
	private function hash(string $mark): string
	{
		return hash('sha256', $mark);
	}

	private function loadRowOfLetter(Context $context, int $messageId): ?array
	{
		return SourceGenerationTailMarkTable::getRow([
			'select' => ['ID', 'STATE', 'ATTEMPTS'],
			'filter' => [
				'=GENERATION_ID' => $context->generationId,
				'=MESSAGE_ID' => $messageId,
			],
		]);
	}
}
