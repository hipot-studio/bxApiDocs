<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

/**
 * The ceilings of the answer of the communication history, all of them in one place: a number that
 * lives in two places is a number that stops agreeing with itself.
 *
 * @internal
 */
final class CommunicationHistoryLimits
{
	/**
	 * Conversations the history carries when the caller asks for no number of its own.
	 */
	public const DEFAULT_ITEMS_LIMIT = 10;

	/**
	 * The most conversations a caller is allowed to ask for: a bigger number is cut down to it and not
	 * refused.
	 */
	public const MAX_ITEMS_LIMIT = 30;

	/**
	 * Conversations a single source is read of, spent before the merge. The ceiling of a source is its
	 * own and is never below the ceiling of the answer: read to less than the answer carries, a source
	 * would lose conversations the answer had room for.
	 *
	 * More than the answer carries is read of nobody: a conversation below the {@see self::MAX_ITEMS_LIMIT}-th
	 * of its own source has that many fresher ones of the same source ahead of it and cannot reach the
	 * answer, so the rows above the asked number are read and thrown away. The service asks a source for
	 * the smaller of the two numbers.
	 */
	public const ITEMS_PER_SOURCE = 30;

	/**
	 * Characters of the short content of one conversation. The text comes out of a `longtext` column
	 * that nobody else bounds, and an answer carries up to {@see self::MAX_ITEMS_LIMIT} of them - the
	 * same number the summary of a client bounds a single one of by
	 * {@see \Bitrix\Crm\V2\Internal\Service\Summary\SummaryLimits::LAST_CALL_SUMMARY_LENGTH}.
	 *
	 * The cut is not told about in the record: at this length it is a guard against a text that should
	 * never have been this long, and not a ceiling the answer is expected to meet - the speech analysis
	 * writes a short content of a conversation, and a mark of a cut on every record would say something
	 * happened where nothing did.
	 */
	public const SUMMARY_CHARS_PER_ITEM = 4000;

	/**
	 * Characters of the theme of a conversation and of the intent of the client. Both come out of the
	 * same `longtext` column the short content does and are written by the same speech analysis, which
	 * words them as labels of a line or two - so the guard is set where a label stops being one, far
	 * above anything the analysis writes and far below what an unbounded column may hold.
	 *
	 * The cut is not told about in the record, for the same reason
	 * {@see self::SUMMARY_CHARS_PER_ITEM} is not: at this length it is a guard and not a ceiling the
	 * answer is expected to meet.
	 */
	public const LABEL_CHARS_PER_ITEM = 500;

	private function __construct()
	{
	}
}
