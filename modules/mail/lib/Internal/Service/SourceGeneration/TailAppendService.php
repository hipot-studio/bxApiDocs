<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MessageMatcher;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

/**
 * The second half of a transfer: the letters the external service failed to carry over,
 * put onto the new source by the module itself.
 *
 * The service copies the mail of the mailbox from one server to the other on its own and
 * does not carry all of it over. A letter it left behind has a placement of the retained
 * generation and none of the prepared one: the moment the prepared generation starts
 * serving the mailbox, that letter is gone from the list, from the search and from the
 * thread, and its attachments are gone for good - a download through a placement of a
 * generation the mailbox has left is refused by the boundary. The deal in CRM survives the
 * move and keeps the text of a letter whose files nobody can open again.
 *
 * So the stage stands between the import and the switch, and both neighbours matter. The
 * old source is still the active one, which is the only reason the attachments can still be
 * downloaded; and the delta pass of the switch walks every folder of the new source
 * afterwards, which is what reads the appended letters back - this stage writes no
 * placement of its own.
 *
 * The order of one letter is the whole of its safety: the attachments are downloaded and so
 * is the body of a letter our own row kept as an envelope alone, the message is assembled,
 * the intent to append it is written down, only then does it go up, and the verdict of the
 * matching follows immediately. What the write ahead buys is the resumption: a pass that
 * dies between the append and the verdict left a letter on the server that our journal
 * knows nothing about, and the next pass has to find that out before it appends the same
 * letter a second time.
 *
 * What it cannot buy is a verdict written before the append. The verdict is addressed by
 * the coordinates the server assigns to the letter, and the server assigns them in the
 * answer to the append itself - there is nothing to address a verdict with beforehand.
 * The gap is named, not hidden: a letter that fell into it is read back by the ordinary
 * route of the matching, and the route survives it because the assembled copy carries the
 * text part out of our own column instead of a retelling of the markup
 * ({@see TailMessageBuilder}).
 *
 * The cursor of the walk is the one thing that must never run ahead of the letters: it
 * moves past a letter only once that letter is on the new source, and what counts as being
 * there is a proof - a terminal record of its mark, a verdict of the journal, the folder
 * answering that it holds the mark of this very append, or the window of numbers the letter
 * would have landed in. When none of the four can answer, the fallback is defined in one place
 * ({@see UNKNOWN_APPEND_LANDED}).
 *
 * A source that refuses the letter, a source that will not hand its files over and a storage
 * that will not keep them are a different thing: there the letter is known NOT to be up
 * there, so it stays current and the pass ends with it. What the next passes are for is
 * telling apart the two things that look alike from here. One letter the source will not
 * take - one above the size limit of the server, one whose file is gone - is set aside once
 * the attempts of the module are out on it ({@see HistorySyncAttemptService}) and the walk
 * goes on over the rest of the remainder: a letter nobody can append must not cost the
 * mailbox the hundreds of letters behind it. A source that takes letter after letter from nobody is the other thing
 * ({@see SOURCE_REFUSALS}), and there walking on would leave the remainder in the archived
 * generation for nothing, so the operation stops by name (ERROR_TAIL_SOURCE_REFUSES) instead.
 *
 * A set aside letter is never dropped in silence: it is counted apart
 * ({@see MigrationMetrics::SET_ASIDE}) and named in the log of the portal one by one, exactly
 * as the letters the switch leaves behind past the window of the stage.
 *
 * A source that hands no BODY over is not among them: that letter has no files to lose, its
 * body may be gone for good, and a remainder waiting on it would cost the mailbox its whole
 * tail - so the envelope goes up and the loss is counted
 * ({@see MigrationMetrics::ENVELOPE_ONLY}).
 *
 * A source that answers no coordinates at all leaves every letter of the remainder in that
 * gap, and there the ordinary route is not good enough - a mailbox whose whole tail comes
 * back as new letters is the harm this stage exists against. Such a letter therefore carries
 * a one time mark of ours, written down before the append and readable back out of the MIME
 * ({@see TailMarkService}). Only such a letter does: a letter whose coordinates we learn has
 * the stronger route already, and a mark put into it would stay inside the mailbox of the
 * client for nothing. Which of the two a source is worth is learned from its first answer,
 * so the first letter of the very first pass against such a source is the one letter left in
 * the gap - and the answer is remembered with the generation, so no later pass repeats it.
 */
class TailAppendService
{
	public const ERROR_TAIL_FAILED = 'MAIL_SOURCE_GENERATION_TAIL_FAILED';
	public const ERROR_TAIL_FOLDER_MISSING = 'MAIL_SOURCE_GENERATION_TAIL_FOLDER_MISSING';

	/**
	 * The new source took none of the last letters of the remainder it was offered, so the
	 * operation stops at a name instead of walking the rest of the remainder past a source that
	 * takes nothing.
	 *
	 * What this is NOT about is one letter: a letter the source will not take is set aside once its
	 * own attempts are out and the walk goes on {@see refusalOf()}. Letter after letter
	 * refused is about the source itself - the quota of the mailbox, the rights of the folder, a
	 * connection that is answering and taking nothing - and there every letter walked past would
	 * be left in the archived generation for a reason that has nothing to do with it. Switching
	 * without the whole remainder stays a legitimate way out of a stage that runs out of its
	 * window. Until that is explicitly allowed, this error stops the operation.
	 */
	public const ERROR_TAIL_SOURCE_REFUSES = 'MAIL_SOURCE_GENERATION_TAIL_SOURCE_REFUSES';

	/** Letters one pass of the stage appends at most */
	private const PASS_BUDGET = 200;

	/**
	 * Pages of the remainder one pass asks for at most. A page can be empty and the walk
	 * still not over - everything of that stretch reached the new source already - so a pass
	 * over a mailbox the service carried across completely is bounded by this and not by the
	 * letters it found.
	 */
	private const WALK_BUDGET = 20;

	/**
	 * Appends of one letter inside one pass, and only of the answered kind: the source named its
	 * refusal, so the letter is where it was and offering it again costs nothing. If the source
	 * keeps refusing, the pass ends with that letter still current.
	 *
	 * A command the source did not answer spends every attempt that is left at once - the letter
	 * may already be up there, and the pass has no way of telling. That letter is answered by the
	 * pass that follows, out of the record of the append in flight.
	 */
	private const APPEND_ATTEMPTS = 3;

	/**
	 * Letters in a row the new source may refuse before the operation stops at it
	 * {@see ERROR_TAIL_SOURCE_REFUSES}, counted over the passes and started over by every
	 * letter the source does take.
	 *
	 * Two of them is a source that has taken nothing for six passes of the stage, the letters
	 * differing in size, in files and in age - what they share is the source, so the quota of
	 * the mailbox, the rights of the folder or a connection answering and taking nothing is the
	 * likelier reading, and there walking on would leave the whole remainder behind for a reason
	 * that has nothing to do with any single letter. Two is also what bounds the price of that
	 * reading being wrong the other way: a source refusing everything costs exactly one letter
	 * set aside before the operation stops, and every letter of that number would cost one more.
	 */
	private const SOURCE_REFUSALS = 2;

	/**
	 * The window of the stage: four hours of hurried work, counted from its first pass, and by
	 * its end the mailbox is handed over whether the remainder is finished or not.
	 *
	 * What it bounds is the wait, and nothing else. While it lasts, a source taking no letter at
	 * all keeps the stage where it is and stops the operation by name, so that somebody can free
	 * the quota or fix the rights; past it the stage says so itself and walks on
	 * {@see allowSwitchWithUnfinishedRemainder()}. One letter of the remainder never waits that
	 * long: it is set aside once the attempts of the module are out on it {@see refusalOf()},
	 * because the window belongs to the remainder as a whole and not to the letter the walk
	 * happens to stand on.
	 *
	 * The price is real and is accepted, not hidden: a letter left behind is gone from the mail,
	 * from the search and from the thread of the switched mailbox, and its attachments cannot be
	 * fetched afterwards - the archived placement serves no download once the mailbox has moved.
	 */
	private const WINDOW = 4 * 3600;

	/**
	 * Whether an append with no conclusive recovery evidence is treated as landed.
	 *
	 * A second copy of a letter stays in the mailbox of the client forever and the portal has no
	 * way to remove it; a letter read back as a new one loses its links and keeps its content. So
	 * the doubt reads as "the letter is up there", and the letter is not appended again.
	 *
	 * Every doubt that CAN be resolved is resolved before this is asked: the terminal record of
	 * the mark, the verdict of the journal and the window of the append are three proofs, and one
	 * of them answers unless the source named no number for the next letter of the folder or the
	 * epoch of the folder changed under the operation. What is left is this constant, and turning
	 * the decision around is changing it here.
	 */
	private const UNKNOWN_APPEND_LANDED = true;

	/** The flag of a letter the user had already read */
	private const READ_FLAG = '\Seen';

	/** The states of a placement that mean the user has read the letter */
	private const READ_STATES = ['Y', 'S'];

	/* The progress of the stage, kept in the OPTIONS of the prepared generation */

	/** The walk over the letters a CRM activity or a task points at */
	private const STATE_REFERENCED_CURSOR = 'tail_referenced_cursor';
	private const STATE_REFERENCED_DONE = 'tail_referenced_done';

	/** The walk over the rest of the remainder */
	private const STATE_REST_CURSOR = 'tail_rest_cursor';
	private const STATE_REST_DONE = 'tail_rest_done';

	/**
	 * The walk over the remainder of the folders a hand over brought into the new server late
	 * {@see reopenForFolders()}: [{generation, hash}] of the folders of the old server, its cursor and mark.
	 */
	private const STATE_REOPENED_DIRS = 'tail_reopened_dirs';
	private const STATE_REOPENED_CURSOR = 'tail_reopened_cursor';
	private const STATE_REOPENED_DONE = 'tail_reopened_done';

	/** The letter this pass is appending right now: written before the append, cleared after the verdict */
	private const STATE_IN_FLIGHT = 'tail_in_flight';

	/**
	 * The letter the walk is stuck on, with what it came to and how many attempts it has spent:
	 * the letter is current until it is on the new source, and this is where somebody looking at
	 * a stage that stopped moving sees which letter to look at.
	 *
	 * The count here is a copy for the reader. The attempts themselves are counted by the service
	 * of the module ({@see HistorySyncAttemptService}), because they belong to the letter and not
	 * to the walk: the walk forgets them the moment the letter is closed.
	 */
	private const STATE_STALLED = 'tail_stalled';

	/**
	 * Letters in a row the new source did not take, started over by every letter it did
	 * {@see SOURCE_REFUSALS}. Kept with the generation because the letters of such a run belong
	 * to different passes: a pass ends with the letter it could not append.
	 */
	private const STATE_REFUSED_RUN = 'tail_refused_run';

	/** When the stage started working, which is where its window is counted from */
	private const STATE_STARTED_AT = 'tail_started_at';

	/**
	 * The decision of the operation: hand the mailbox over even though the remainder is not
	 * through {@see allowSwitchWithUnfinishedRemainder()}.
	 *
	 * The window of the stage is four hours of hurried work, and by its end the mailbox is
	 * switched whether the remainder is finished or not - that is a decision of the operation
	 * and it stays possible. What it must never be is the answer to the first refusal of a
	 * letter: while nobody has decided, a letter that is not on the new source keeps the stage
	 * where it is for the passes of its own, and a source that then takes no letter at all stops
	 * the operation with a name {@see ERROR_TAIL_SOURCE_REFUSES}.
	 */
	private const STATE_UNFINISHED_ALLOWED = 'tail_switch_unfinished';

	/**
	 * The new source answers no coordinates for an appended letter, so the letters of it are
	 * recognized by the one time mark of the fallback route instead of by a verdict of the
	 * journal. Learned from the first answer of the source and kept with the generation, so a
	 * later pass marks its letters from the first one on.
	 */
	private const STATE_UNNUMBERED_SOURCE = 'tail_unnumbered_source';

	/**
	 * The one letter that went up before the stage knew the source names no numbers: it carries
	 * neither a mark nor a verdict until it is read back, and the walk over the reopened folders
	 * must not append it again.
	 */
	private const STATE_GAP_LETTER = 'tail_gap_letter';

	/* What one letter of the remainder came to. The first three mean it is on the new source */

	private const OUTCOME_APPENDED = 'appended';
	private const OUTCOME_MARKED = 'marked';
	private const OUTCOME_UNRECOGNIZED = 'unrecognized';

	/** The new source would not take the letter */
	private const OUTCOME_REFUSED = 'refused';

	/** The letter cannot be assembled whole: a file of it is neither on the old source nor ours */
	private const OUTCOME_INCOMPLETE = 'incomplete';

	/* What a letter that is not on the new source comes to, while nobody has decided to switch without it */

	/** The letter keeps the cursor behind it and the pass ends: the next one offers it again */
	private const REFUSAL_RETRY = 'retry';

	/** The attempts of the letter are out: it is left behind by name and the walk goes on without it */
	private const REFUSAL_SET_ASIDE = 'aside';

	/** Letter after letter refused is about the source, and there the operation stops */
	private const REFUSAL_SOURCE = 'source';

	private ?Helper\Mailbox $activeGenerationEngine = null;

	/** Whether the engine of the active generation has been asked for at all */
	private bool $activeGenerationEngineAsked = false;

	/** The system roles of the folders of the mailbox, read once for the whole pass */
	private ?SystemRoleMap $systemRoles = null;

	/** The folders the transfer brought into the new server, read once for the whole pass */
	private ?FolderPairMap $folderPairs = null;

	/** @var array<string, true> The folders of the prepared generation its import reads */
	private array $preparedSyncPaths = [];

	private ?Helper\Mailbox\HistorySyncAttemptService $attempts = null;

	public function __construct(
		private readonly TailRemainderQuery $remainder = new TailRemainderQuery(),
		private readonly MessageMatcher $matcher = new MessageMatcher(),
		private readonly TailAttachmentFetcher $attachments = new TailAttachmentFetcher(),
		private readonly TailMarkService $marks = new TailMarkService(),
		private readonly TailBodyFetcher $body = new TailBodyFetcher(),
		private readonly RolloutBrakeReader $brakes = new RolloutBrakeReader(),
	)
	{
	}

	/**
	 * Carries the remainder of the transfer as far as one pass of the stage gets.
	 *
	 * @param array $connection The stored credentials of the prepared generation.
	 * @return Result Data carries {@see MigrationService::PASS_CONTINUES} for a pass that
	 *         spent its budget: the stage stays where it is and the next run of the same
	 *         operation continues from the cursors this one stored.
	 */
	public function run(Context $context, array $connection, MigrationMetrics $metrics): Result
	{
		$result = new Result();

		$this->activeGenerationEngine = null;
		$this->activeGenerationEngineAsked = false;
		$this->systemRoles = null;
		$this->folderPairs = null;
		$this->preparedSyncPaths = [];

		$progress = $this->readProgress($context->generationId);

		if ($progress === null)
		{
			// The generation is gone: the mailbox is being deleted and nothing here has an owner
			return $this->continued($result);
		}

		if ((int)($progress[self::STATE_STARTED_AT] ?? 0) <= 0)
		{
			// The window of the stage starts with its first pass and is kept with the generation
			$this->store($context->generationId, [self::STATE_STARTED_AT => time()]);
		}

		$prepared = $this->createPreparedGenerationEngine($context->mailboxId, $connection, $context);

		if ($prepared === null)
		{
			return $result->addError(new Error(
				sprintf('The new source of the mailbox %u is unreachable', $context->mailboxId),
				self::ERROR_TAIL_FAILED,
			));
		}

		$destinations = $this->findDestinations($prepared, $context);
		$dirPath = $destinations[MailboxDirectoryTable::TYPE_INCOME] ?? null;

		if ($dirPath === null)
		{
			return $result->addError(new Error(
				sprintf(
					'The prepared generation %u has no incoming folder to append the remainder into',
					$context->generationId,
				),
				self::ERROR_TAIL_FOLDER_MISSING,
			));
		}

		$this->recoverInFlightLetter($context, $prepared, $dirPath, $metrics);

		return $this->walk($context, $prepared, $destinations, $metrics, $result);
	}

	/**
	 * The two walks of the remainder, in the order the stage owes them: the letters a CRM
	 * activity or a task points at first, the rest of the remainder afterwards.
	 *
	 * A pass is bounded three ways - by the letters it appends, by the pages of the remainder
	 * it asks for and by its time. The last of them is what a slow source needs: every letter
	 * costs an append of its own and, for a letter whose files are still on the old source, a
	 * download from the other server as well, so a pass counted in letters alone turns into a
	 * request of hundreds of round trips. The time is asked between two letters, where the
	 * progress of the stage is written down anyway.
	 */
	private function walk(
		Context $context,
		Helper\Mailbox\Imap $prepared,
		array $destinations,
		MigrationMetrics $metrics,
		Result $result,
	): Result
	{
		$letters = 0;
		$walks = 0;
		$deadline = time() + $this->getTimeQuota();

		while ($letters < $this->getPassBudget() && $walks < self::WALK_BUDGET)
		{
			/*
				This stage is the one that writes to the new source: every letter of the remainder
				it takes is a letter put on the new server. So the switches of the rollout are
				asked here as well, and asked of the storage itself - the value the process holds
				in memory is the one taken before the operator pressed anything
				{@see RolloutBrakeReader}. A pass told to stop leaves its cursors where they are
				and reports itself unfinished, exactly as it does when its budget runs out.
			*/
			if ($this->brakes->findRefusal() !== null)
			{
				return $this->continued($result);
			}

			$progress = $this->readProgress($context->generationId);

			if ($progress === null)
			{
				return $this->continued($result);
			}

			$pass = $this->currentPass($progress);

			if ($pass === null)
			{
				/*
					Both walks reached the end of the mailbox, and every letter they offered is
					either on the new source or named in the log as left behind: a letter that is
					neither ends its pass right here, so this answer cannot be given over a letter
					that was quietly passed over.
				*/
				return $result;
			}

			++$walks;
			$page = $this->readPage($context, $pass, $progress);
			$unnumbered = (bool)($progress[self::STATE_UNNUMBERED_SOURCE] ?? false);
			$refusedRun = (int)($progress[self::STATE_REFUSED_RUN] ?? 0);

			/*
				The walk over the reopened folders passes over the letters the first walk appended into
				the inbox of the new server before their folder was there: the replay of the hand over
				moves them into the pair. Their verdicts come in one statement for the page, and nothing
				else of theirs is read. A source naming no number leaves such a letter with a mark and no
				verdict until it is read back, so the marks count as well, and so does the one letter that
				went up before the stage knew it - a letter whose marked append the server refused is not
				retried here either: the first walk named it as left behind.
			*/
			$settled = $pass['folders'] !== null
				? $this->findVerdictsOf($context, $page->messageIds)
					+ ($unnumbered ? $this->marks->findMarkedOf($context, $page->messageIds) : [])
					+ (isset($progress[self::STATE_GAP_LETTER]) ? [(int)$progress[self::STATE_GAP_LETTER] => true] : [])
				: []
			;
			$pending = $settled === []
				? $page->messageIds
				: array_values(array_filter($page->messageIds, static fn ($id): bool => !isset($settled[(int)$id])))
			;

			/*
				The letters of the page, their archived placements and the rows of their files
				come in three statements for the whole page: one letter used to cost three of its
				own, and a page of fifty of them a hundred and fifty round trips to the database
				before the first append.
			*/
			$letterRows = $this->loadLetters($context, $pending);
			$attachmentRows = $this->loadAttachmentsOfLetters($pending);
			$roles = $this->systemRoles($context->mailboxId);
			$pairs = $this->folderPairs($context);

			/*
				And the attempts the letters of the page have spent, in one statement of their own:
				almost none of them has any, and reading them here is what keeps the closing write of
				a letter that never failed from asking the database about a counter that is not there.
			*/
			$spent = $this->attempts($context->mailboxId)->getAttemptCounts(
				array_map(strval(...), $pending),
			);

			/*
				A letter passed over is not closed by a write of its own: the next letter closed moves the
				cursor past it, the end of the page does the same, and a pass ending in between offers it
				again only to pass over it again.
			*/
			foreach ($pending as $messageId)
			{
				$messageId = (int)$messageId;
				$letter = $letterRows[$messageId] ?? null;
				$dirPath = $this->destinationOf($letter, $roles, $pairs, $destinations);

				if ($dirPath === null)
				{
					return $result->addError(new Error(
						sprintf(
							'The prepared generation %u has no sent folder to append the message %u into',
							$context->generationId,
							$messageId,
						),
						self::ERROR_TAIL_FOLDER_MISSING,
					));
				}

				$counted = isset($spent[(string)$messageId]);
				$outcome = $this->appendLetter(
					$context,
					$prepared,
					$dirPath,
					$messageId,
					$metrics,
					$unnumbered,
					$letter,
					$attachmentRows[$messageId] ?? [],
				);
				++$letters;
				$behind = false;

				if ($outcome === self::OUTCOME_REFUSED || $outcome === self::OUTCOME_INCOMPLETE)
				{
					$allowed = (bool)($progress[self::STATE_UNFINISHED_ALLOWED] ?? false);

					if (!$allowed && $this->windowHasRunOut($progress))
					{
						/*
							The window of the stage is over, and by the decision of the stage that is
							when the mailbox is handed over with whatever the remainder came to. The
							stage says so itself and writes it down, so every pass from here on reads
							the same answer instead of counting the hours again.
						*/
						$this->allowSwitchWithUnfinishedRemainder($context->generationId);
						$allowed = true;
					}

					$refusal = $allowed
						? self::REFUSAL_SET_ASIDE
						: $this->refusalOf($context, $messageId, $outcome, $refusedRun, $spent)
					;
					$counted = $counted || !$allowed;

					if ($refusal === self::REFUSAL_RETRY)
					{
						/*
							The letter is not on the new source, so the cursor stays behind it and the
							pass ends here. The next run offers this very letter again: a refusal is
							usually a source that stopped answering for a minute, and a letter walked
							past on the first one would be lost to a connection.
						*/
						return $this->continued($result);
					}

					if ($refusal === self::REFUSAL_SOURCE)
					{
						return $result->addError($this->sourceRefusesTheRemainder($context, $messageId, $outcome));
					}

					// The letter stays behind, and it is named one by one, not counted in silence
					$behind = true;
					$metrics->add($allowed ? MigrationMetrics::LEFT_BEHIND : MigrationMetrics::SET_ASIDE);
					$this->reportStaysBehind(
						$context,
						$messageId,
						$outcome,
						$allowed,
						$spent[(string)$messageId] ?? 0,
					);
				}

				/*
					One write closes the letter: the cursor moves past it, the record of the append
					in flight goes away and the count of the stalled passes with it. Two writes
					would leave a window in which the letter is neither in flight nor behind the
					cursor, and a pass that died in that window would append it a second time.
				*/
				$closed = [
					$pass['cursor'] => $messageId,
					self::STATE_IN_FLIGHT => null,
					self::STATE_STALLED => null,
				];

				if ($behind && $outcome === self::OUTCOME_REFUSED)
				{
					$closed[self::STATE_REFUSED_RUN] = ++$refusedRun;
				}
				elseif ($outcome !== null)
				{
					// The source took a letter, so whatever run of refused ones there was is over
					$refusedRun = 0;
					$closed[self::STATE_REFUSED_RUN] = null;
				}

				if ($outcome === self::OUTCOME_UNRECOGNIZED && !$unnumbered)
				{
					/*
						The source named no coordinates for this letter, and it will name none for
						the ones after it either. This is the one letter of the gap: it went up
						before anything knew what kind of source this is, so it is read back by the
						ordinary route of the matching. Every letter from here on carries the one
						time mark of the fallback route, and the answer is remembered with the
						generation so that no later pass pays for it again.
					*/
					$unnumbered = true;
					$closed[self::STATE_UNNUMBERED_SOURCE] = true;
					$closed[self::STATE_GAP_LETTER] = $messageId;
				}

				$this->store($context->generationId, $closed);

				if ($counted)
				{
					/*
						The letter is closed either way it went, so the attempts it had spent are of no
						use to anybody: a counter left behind would meet the letter in the tail of the
						next generation of this mailbox and give it away before it was ever offered.
					*/
					$this->attempts($context->mailboxId)->clearAttempts([(string)$messageId]);
				}

				if ($letters >= $this->getPassBudget() || time() >= $deadline)
				{
					return $this->continued($result);
				}
			}

			$this->store($context->generationId, [$pass['cursor'] => $page->cursor]);

			if ($page->isExhausted)
			{
				$this->store($context->generationId, [$pass['done'] => true]);
			}
		}

		return $this->continued($result);
	}

	/**
	 * Opens the walk over the remainder of these folders of the old server once more: the hand over has
	 * just brought them into the new server, or failed to, and goes back to this stage for their letters
	 * {@see LateFolderReconciler}.
	 *
	 * The two ordinary walks are not repeated: a letter they appended has no placement of the prepared
	 * generation until the delta of the hand over reads it back, so a second ordinary walk would append
	 * it again. This walk passes over such a letter by its verdict instead.
	 *
	 * The window is counted anew from here: the one of the first pass has usually run out while the
	 * switch was awaited, and the first refusal of the new server would set a letter of such a folder
	 * aside at once. A decision to switch with the remainder unfinished, once taken, stays.
	 *
	 * @param array<int, array{generation: int, hash: string}> $dirs
	 */
	public function reopenForFolders(int $generationId, array $dirs): void
	{
		$dirs = array_values(array_map(
			static fn (array $dir): array => ['generation' => (int)$dir['generation'], 'hash' => (string)$dir['hash']],
			$dirs,
		));

		$this->store($generationId, [
			self::STATE_REOPENED_DIRS => $dirs === [] ? null : $dirs,
			self::STATE_REOPENED_CURSOR => null,
			self::STATE_REOPENED_DONE => null,
			self::STATE_REFUSED_RUN => null,
			self::STATE_STALLED => null,
			self::STATE_STARTED_AT => time(),
		]);
	}

	/**
	 * Whether these folders of the old server hold a letter the walks of the stage have not reached:
	 * one the final synchronization of a hand over brought into a folder with no pair after the walks
	 * were through. Message ids only grow, so such a letter lies beyond the cursor the walks reached,
	 * and the lookup is one page of the remainder of those folders above it.
	 *
	 * @param array<int, array{generation: int, hash: string}> $dirs
	 */
	public function hasLettersBeyondItsWalks(Context $context, array $dirs): bool
	{
		$hashes = array_values(array_unique(array_map(static fn (array $dir): string => (string)$dir['hash'], $dirs)));
		if ($hashes === [])
		{
			return false;
		}

		$progress = $this->readProgress($context->generationId) ?? [];
		// The two ordinary walks split the letters between them: the one behind covers both
		$reached = min((int)($progress[self::STATE_REFERENCED_CURSOR] ?? 0), (int)($progress[self::STATE_REST_CURSOR] ?? 0));

		$reopened = array_map(
			static fn (array $dir): string => (string)($dir['hash'] ?? ''),
			array_filter((array)($progress[self::STATE_REOPENED_DIRS] ?? []), 'is_array'),
		);
		if (($progress[self::STATE_REOPENED_DONE] ?? false) && array_diff($hashes, $reopened) === [])
		{
			$reached = max($reached, (int)($progress[self::STATE_REOPENED_CURSOR] ?? 0));
		}

		$page = $this->remainder->listFolderPage($context, $hashes, $reached, 1);

		return $page->messageIds !== [] || !$page->isExhausted;
	}

	/**
	 * Whether the window of the stage is over. Counted from the first pass of the stage and not
	 * from the start of the operation: what the window bounds is the hurried work of this stage.
	 *
	 * @param array<string, mixed> $progress
	 */
	private function windowHasRunOut(array $progress): bool
	{
		$startedAt = (int)($progress[self::STATE_STARTED_AT] ?? 0);

		return $startedAt > 0 && time() >= $startedAt + $this->getWindow();
	}

	/**
	 * How long the stage may keep the mailbox waiting for its remainder, in seconds.
	 */
	protected function getWindow(): int
	{
		return self::WINDOW;
	}

	/**
	 * The decision to hand the mailbox over with the remainder unfinished, written down with
	 * the generation the operation is preparing.
	 *
	 * Written by the stage itself once its window is over {@see WINDOW}, and callable from
	 * outside for an operation that decides earlier - the answer is the same either way: from
	 * here on a letter the stage cannot put onto the new source is left behind, named in the log
	 * of the portal and counted apart from the letters that made it
	 * ({@see MigrationMetrics::LEFT_BEHIND}). Which letters were lost stays answerable, and that
	 * is the whole difference from a silent skip.
	 */
	public function allowSwitchWithUnfinishedRemainder(int $generationId): void
	{
		$this->store($generationId, [self::STATE_UNFINISHED_ALLOWED => true]);
	}

	/**
	 * A letter that stays in the archived generation alone, named one by one. The counters of
	 * the pass already carry it; what the entry adds is which letter it was and why, so that a
	 * mailbox switched without a part of its remainder stays answerable afterwards.
	 *
	 * @param string $outcome Which of the two ways the letter did not make it: the source
	 *        refused it, or it could not be assembled whole out of what we have.
	 * @param bool $decided Whether the operation is switching without the remainder anyway
	 *        {@see allowSwitchWithUnfinishedRemainder()}. Otherwise this is one letter set
	 *        aside so that the rest of the remainder can go up {@see refusalOf()}.
	 * @param int $attempts How many attempts the letter had spent by then, zero for a letter the
	 *        decision of the operation leaves behind without counting anything.
	 */
	private function reportStaysBehind(
		Context $context,
		int $messageId,
		string $outcome,
		bool $decided,
		int $attempts,
	): void
	{
		$entry = $decided
			? sprintf(
				'The message %u of the transfer remainder of the mailbox %u stays behind on the switch'
				. ' by decision of the operation (%s)',
				$messageId,
				$context->mailboxId,
				$outcome,
			)
			: sprintf(
				'The message %u of the transfer remainder of the mailbox %u is set aside after %u attempts'
				. ' and stays behind on the switch: the new source did not take it (%s)',
				$messageId,
				$context->mailboxId,
				$attempts,
				$outcome,
			)
		;

		AddMessage2Log($entry, 'mail', 2, false);
	}

	/**
	 * What a letter of the remainder that is not on the new source comes to: the source refused
	 * it, or the files it is assembled out of are neither on the old source nor in our storage.
	 *
	 * Such a letter is never dropped in silence and never walked past on the first refusal - the
	 * pass ends with the cursor behind it, and the passes it has cost are counted with the
	 * generation. What the count buys is telling apart the two things that look the same from
	 * here, and the answer differs for them:
	 *
	 * - a source that stopped answering for a minute is back by the next run, and the letter
	 *   goes up then;
	 * - a letter that can never go up - one above the size limit of the server, one whose file
	 *   is gone - is set aside once its attempts are out, and the walk goes on over the rest of
	 *   the remainder. Waiting on it any longer would cost the mailbox every letter behind it,
	 *   and that is a far larger loss than the one letter;
	 * - a source that takes no letter at all is what letter after letter refused reads as, and
	 *   there the walk has nothing to go on to: the operation stops with an error naming the
	 *   letter it stands on, and what to do about it is a decision somebody makes - free the
	 *   quota, fix the rights, or say that the mailbox is switched without the rest of the
	 *   remainder {@see allowSwitchWithUnfinishedRemainder()}.
	 *
	 * How many attempts one letter is owed is not decided here. It is the count of the module
	 * ({@see HistorySyncAttemptService}), written for the very same illness in the history sync:
	 * one message the server would not hand over used to end every run before it, and the rest of
	 * the dir was never reached. The count is kept under a property of this stage, so the attempts
	 * of the two operations over one letter never touch.
	 *
	 * @param int $refusedRun Letters in a row the source has not taken before this one.
	 * @param array<string, int> $spent In and out: attempts of the letters of this page, the one
	 *        counted here included, so that the walk names it in the log without a read of its own.
	 * @return string One of the REFUSAL_* constants.
	 */
	private function refusalOf(
		Context $context,
		int $messageId,
		string $outcome,
		int $refusedRun,
		array &$spent,
	): string
	{
		$attempts = $this->attempts($context->mailboxId)->registerFailedAttempt(
			(string)$messageId,
			$spent[(string)$messageId] ?? 0,
		);
		$spent[(string)$messageId] = $attempts;

		if (
			Helper\Mailbox\HistorySyncAttemptService::isQuarantined($attempts)
			&& ($outcome === self::OUTCOME_INCOMPLETE || $refusedRun + 1 < self::SOURCE_REFUSALS)
		)
		{
			// The closing write of the walk carries the record away with the letter it named
			return self::REFUSAL_SET_ASIDE;
		}

		$this->store($context->generationId, [
			self::STATE_STALLED => ['message' => $messageId, 'attempts' => $attempts, 'reason' => $outcome],
		]);

		return Helper\Mailbox\HistorySyncAttemptService::isQuarantined($attempts)
			? self::REFUSAL_SOURCE
			: self::REFUSAL_RETRY
		;
	}

	/**
	 * The attempts of one letter of the remainder, counted by the service of the module and under
	 * a property of this stage {@see MailEntityOptionsTable::SOURCE_GENERATION_TAIL_ATTEMPT_COUNT_PROPERTY_NAME}.
	 */
	private function attempts(int $mailboxId): Helper\Mailbox\HistorySyncAttemptService
	{
		return $this->attempts ??= new Helper\Mailbox\HistorySyncAttemptService(
			$mailboxId,
			MailEntityOptionsTable::SOURCE_GENERATION_TAIL_ATTEMPT_COUNT_PROPERTY_NAME,
		);
	}

	/**
	 * The error of a source that took none of the letters it was offered. It names the letter
	 * the walk stands on, because that is the one somebody can look at - the run itself is in
	 * the counters of the operation.
	 */
	private function sourceRefusesTheRemainder(Context $context, int $messageId, string $outcome): Error
	{
		return new Error(
			sprintf(
				'The new source of the mailbox %u took none of the last %u letters of the transfer'
				. ' remainder, the message %u among them (%s)',
				$context->mailboxId,
				self::SOURCE_REFUSALS,
				$messageId,
				$outcome,
			),
			self::ERROR_TAIL_SOURCE_REFUSES,
		);
	}

	/**
	 * One letter of the remainder: everything it needs, in the one order that keeps the
	 * history intact.
	 *
	 * @param bool $unnumbered The source names no coordinates for a letter it takes, so this one
	 *        goes up carrying the one time mark of the fallback route.
	 * @param array|null $letter The letter as the page of the walk loaded it, null when neither
	 *        it nor its archived placement is there anymore.
	 * @param array $attachments Rows of the files of the letter, as the page loaded them.
	 * @return string|null One of the OUTCOME_* constants; null when the letter was passed
	 *         over without reaching the server at all.
	 */
	private function appendLetter(
		Context $context,
		Helper\Mailbox\Imap $prepared,
		string $dirPath,
		int $messageId,
		MigrationMetrics $metrics,
		bool $unnumbered,
		?array $letter,
		array $attachments,
	): ?string
	{
		$metrics->add(MigrationMetrics::PROCESSED);

		if ($letter === null)
		{
			/*
				Neither the letter nor a live placement of it on the old source: it left the
				mailbox on its own, and there is nothing left to carry over. The walk closes it -
				a letter that does not exist cannot be lost.
			*/
			return null;
		}

		$placement = $letter['__placement'];

		/*
			The files are downloaded first and the letter waits for them: the old source is the
			only place they exist, and after the switch a download through a placement of the
			retained generation is refused by the boundary. A letter that went up without a file
			is a file gone for good, so a letter whose files are not there is not appended at all
			- it stays for a next pass, and a letter that keeps coming back is set aside by name
			{@see refusalOf()}. The source is asked for at all only when a letter really needs it.
		*/
		if ($this->attachments->isPending($letter))
		{
			if (!$this->attachments->fetch($this->engineOfActiveGeneration($context->mailboxId), $letter))
			{
				$metrics->add(MigrationMetrics::MISSING_FILES);

				return self::OUTCOME_INCOMPLETE;
			}

			// The rows the download has just written: the page was loaded before they existed
			$attachments = $this->loadAttachments($messageId);
		}

		/*
			And the body, for a letter our own row kept as an envelope alone. The same source
			and the same window as the files: after the switch the text of that letter is out
			of reach as well, and an empty shell is what the mailbox would be left with.

			A refusal here does NOT hold the letter back, and that is the difference from the
			files. A body may be lost for good - nobody can fix it and no later pass would
			change the answer - so a remainder stuck on it would cost the mailbox its whole
			tail, while the envelope on the new source at least keeps the letter in the list,
			in the search and in the thread. The loss is counted instead {@see TailBodyFetcher}.
		*/
		if ($this->body->isPending($letter))
		{
			$metrics->add(
				$this->body->fetch($this->engineOfActiveGeneration($context->mailboxId), $letter)
					? MigrationMetrics::BODY_RESTORED
					: MigrationMetrics::ENVELOPE_ONLY
				,
			);
		}

		$mark = '';

		if ($unnumbered)
		{
			/*
				Written down before the letter is even assembled, because the letter is assembled
				around it: this record and not the header is what hands the letter its identity when
				it is read back {@see TailMarkService}.
			*/
			$issued = $this->marks->issue($context, $messageId, md5(Emoji::encode($dirPath)));

			if ($issued === null)
			{
				/*
					Either the reverse read has recognized this letter already or another pass over
					the same remainder holds it - the pair of the generation and the letter is
					unique, and that is the only lock the stage has against a second pass. Either
					way the letter is not this pass's to append: a second copy of a letter is what
					costs the user forever.
				*/
				return null;
			}

			$mark = $issued;
		}

		$builder = TailMessageBuilder::fromStoredMessage(
			$letter,
			$attachments,
			$placement['INTERNALDATE'],
			$mark,
		);

		$missing = $builder->missingAttachments();

		if ($missing !== [])
		{
			/*
				The row of the file is there and the file itself is gone from our storage, so this
				letter cannot be assembled whole by anybody: it goes up neither without the file
				nor with a stub of the kernel in its place. A mark issued above stays PENDING and
				is rotated when the letter comes back, exactly as after a pass that died - no
				letter carrying it exists.
			*/
			$metrics->add(MigrationMetrics::MISSING_FILES, count($missing));

			return self::OUTCOME_INCOMPLETE;
		}

		$mime = $builder->getRawMessage();
		$flags = in_array((string)$placement['IS_SEEN'], self::READ_STATES, true) ? [self::READ_FLAG] : [];
		$internalDate = $this->serverMarkOf($placement);

		/*
			Written down before the letter reaches the server, and this is the only record that
			can be: what addresses the verdict itself are the coordinates the server has not
			assigned yet. A pass that dies from here on leaves a letter that may or may not be
			up there, and the record is what makes the next pass ask instead of guessing.

			The mark travels in the record as well, and only while the letter is in flight. It
			is the one question a resumed pass can ask the folder and get an answer about THIS
			append: the durable record of the mark keeps its hash alone and cannot be searched
			by, and a search by the Message-ID of the letter answers about any letter carrying
			it. The record dies with the letter it covered, so the mark is stored no longer than
			it is on the wire.
		*/
		$inFlight = ['message' => $messageId, 'folder' => $dirPath];
		$position = $prepared->nextAppendPosition($dirPath);

		if ($position !== null)
		{
			// The window this letter will be looked for in if the answer of its append is lost
			$inFlight['epoch'] = $position['uidValidity'];
			$inFlight['from'] = $position['uid'];
		}

		if ($mark !== '')
		{
			$inFlight['mark'] = $mark;
		}

		$this->store($context->generationId, [self::STATE_IN_FLIGHT => $inFlight]);

		$answered = true;

		for ($attempt = 1; $attempt <= self::APPEND_ATTEMPTS; ++$attempt)
		{
			$appended = $prepared->appendMessage($dirPath, $mime, $internalDate, $flags, $answered);

			if ($appended === false)
			{
				$metrics->add(MigrationMetrics::RETRIES);

				if (!$answered)
				{
					/*
						The command went out and the link failed under it, so the source may well
						hold the letter already. Offering it again right here is the one move that
						can leave a second copy in the mailbox of the client forever, and no proof
						is to be had inside this pass: the folder is asked about the record of the
						append in flight by the pass that follows {@see recoverInFlightLetter()},
						and only an answer of the source decides.
					*/
					break;
				}

				continue;
			}

			$outcome = $this->outcomeOfAppend($context, $dirPath, $appended, $messageId, $mark);

			$metrics->add(match ($outcome)
			{
				self::OUTCOME_APPENDED => MigrationMetrics::APPENDED,
				self::OUTCOME_MARKED => MigrationMetrics::MARKED,
				default => MigrationMetrics::UNRECOGNIZED,
			});

			return $outcome;
		}

		$metrics->add(MigrationMetrics::REFUSED);

		AddMessage2Log(
			sprintf(
				$answered
					? 'The new source of the mailbox %u refused the message %u of the transfer remainder'
					: 'The new source of the mailbox %u left the append of the message %u of the transfer'
						. ' remainder unanswered'
				,
				$context->mailboxId,
				$messageId,
			),
			'mail',
			2,
			false,
		);

		/*
			The record of the append in flight is left where it is on purpose. An unanswered command
			says nothing about the letter, so it may well be up there: the next pass asks about it
			the way it asks after a pass that died, and only an answer of the source counts as an
			answer. A refusal the source named is the letter staying where it was, and the record
			costs that case one question of the folder and nothing else.
		*/
		return self::OUTCOME_REFUSED;
	}

	/**
	 * Which of the two routes of the recognition answers for the letter that has just gone up.
	 *
	 * The coordinates the source named are the stronger one and are taken whenever they are
	 * there - a mark issued for the letter is then settled and never read, because the reverse
	 * read stops at the verdict before it ever looks at a header. Without them the mark of the
	 * letter is the route, and a letter carrying neither is the one letter of the gap.
	 *
	 * @param array{uidValidity: int, uid: int}|null $appended What the append answered with.
	 * @param string $mark The one time mark this letter went up with, empty when it carries none.
	 */
	private function outcomeOfAppend(
		Context $context,
		string $dirPath,
		?array $appended,
		int $messageId,
		string $mark,
	): string
	{
		if ($appended === null)
		{
			return $mark === '' ? self::OUTCOME_UNRECOGNIZED : self::OUTCOME_MARKED;
		}

		if ($mark !== '')
		{
			$this->marks->settle($context, $messageId, $appended);
		}

		return $this->recordVerdict($context, $dirPath, $appended, $messageId);
	}

	/**
	 * The verdict about the appended letter, addressed by the coordinates the server gave
	 * it and computed the way the engine computes them when it reads a letter back - the
	 * same call, so the two cannot drift apart.
	 *
	 * @param array{uidValidity: int, uid: int} $coordinates
	 */
	private function recordVerdict(Context $context, string $dirPath, array $coordinates, int $messageId): string
	{
		$uidId = UidIdentity::build(
			$context->getUidFormulaGenerationId(),
			$dirPath,
			$coordinates['uidValidity'],
			$coordinates['uid'],
		);

		return $this->matcher->recordAppendedMessage($context, $uidId, $messageId)
			? self::OUTCOME_APPENDED
			: self::OUTCOME_UNRECOGNIZED
		;
	}

	/**
	 * The letter a previous pass was in the middle of appending.
	 *
	 * It is either on the new source without a verdict of ours, or it never got there, and
	 * only a proof decides which. Three answers are a proof, and the two of them that cost
	 * nothing are asked first: the record of the mark of the letter is terminal, or the journal
	 * of the matching already holds a verdict about it. Then the folder itself - for the mark
	 * this very append carried, and for the window above the number written down before it went
	 * up. The folder is asked once per pass and only when such a record is there.
	 *
	 * The window is what makes the ordinary letters answerable at all: they carry no mark, and
	 * their coordinates are exactly what the dead pass never learned. A letter found there is
	 * proved present and gets the verdict it was owed; an empty window proves the letter absent,
	 * and it goes up now. What is left - no number of the next letter from the source, or an
	 * epoch of the folder that changed since - is the one narrow case nothing can answer, and
	 * there the configured fallback applies {@see UNKNOWN_APPEND_LANDED}.
	 */
	private function recoverInFlightLetter(
		Context $context,
		Helper\Mailbox\Imap $prepared,
		string $dirPath,
		MigrationMetrics $metrics,
	): void
	{
		$progress = $this->readProgress($context->generationId);
		$inFlight = $progress[self::STATE_IN_FLIGHT] ?? null;

		if (!is_array($inFlight))
		{
			return;
		}

		$messageId = (int)($inFlight['message'] ?? 0);
		$folder = (string)($inFlight['folder'] ?? '') ?: $dirPath;
		$found = null;

		$landed = $messageId <= 0
			? null
			: $this->hasReachedTheNewSource($context, $prepared, $folder, $messageId, $inFlight, $found)
		;

		/*
			Where no proof either way is available, use the fallback kept in one place
			{@see UNKNOWN_APPEND_LANDED}.
		*/
		$landed ??= self::UNKNOWN_APPEND_LANDED;

		if (!$landed)
		{
			/*
				Proved absent: the folder answered that nothing of ours is above the number written
				down before the append. The cursor never passed the letter, so the pass that follows
				offers it again.
			*/
			$metrics->add(MigrationMetrics::RETRIES);
			$this->clearInFlight($context->generationId);

			return;
		}

		if ($found !== null)
		{
			/*
				The window of the append answered, so the coordinates of the letter are known after
				all - and with them the verdict the dead pass never wrote. Written now, the letter
				keeps its own history at the reverse read instead of going the ordinary route.
			*/
			$metrics->add(
				$this->recordVerdict($context, $folder, $found, $messageId) === self::OUTCOME_APPENDED
					? MigrationMetrics::APPENDED
					: MigrationMetrics::UNRECOGNIZED
				,
			);
		}

		$pass = $this->currentPass($progress);
		$cursor = $pass === null ? self::STATE_REST_CURSOR : $pass['cursor'];

		/*
			The cursor only ever moves forward. A record of a letter the walk has passed already
			would otherwise send the walk back over letters that are on the new source, and every
			one of them would be appended a second time.
		*/
		$this->store($context->generationId, [
			$cursor => max($messageId, (int)($progress[$cursor] ?? 0)),
			self::STATE_IN_FLIGHT => null,
		]);
		$this->attempts($context->mailboxId)->clearAttempts([(string)$messageId]);
	}

	/**
	 * Whether the append the record covers really happened - the only question the cursor of
	 * the walk may be moved on.
	 *
	 * Four answers are asked for in the order of what they cost, and every one of them is a
	 * proof and not a guess. What is deliberately NOT asked is a search of the folder by the
	 * Message-ID of the letter: it answers about ANY letter carrying that identifier - a copy
	 * the transfer service brought, a letter of a sender who reuses identifiers - and a letter
	 * of ours that never had one would be answered "yes" for nothing.
	 *
	 * @param array $inFlight The record written before the append.
	 * @param array|null $found Out: the coordinates of the letter, when the window of the append
	 *        is what answered - the verdict of the letter can be written from them.
	 * @return bool|null Null when nothing could answer either way.
	 */
	private function hasReachedTheNewSource(
		Context $context,
		Helper\Mailbox\Imap $prepared,
		string $folder,
		int $messageId,
		array $inFlight,
		?array &$found,
	): ?bool
	{
		// The record of the fallback route is terminal: the coordinates of the letter are known
		if ($this->marks->isSettled($context, $messageId))
		{
			return true;
		}

		/*
			Or the verdict of the letter is in the journal already, which the stage writes only
			out of the coordinates the source named for this very append: a pass that died right
			after it left the letter recognized and only the record behind.
		*/
		if ($this->hasVerdictOf($context, $messageId))
		{
			return true;
		}

		$mark = (string)($inFlight['mark'] ?? '');

		/*
			Or the folder answers that it holds a letter carrying the mark of this append. Nobody
			else can put that mark into a letter of that folder: it is random, it was written down
			before the letter existed there and it is asked about once.
		*/
		if ($mark !== '' && $prepared->holdsMessageWithHeader($folder, [TailMarkService::HEADER => $mark]) === true)
		{
			return true;
		}

		return $this->foundInTheWindowOfTheAppend($context, $prepared, $folder, $messageId, $inFlight, $found);
	}

	/**
	 * The window of the append: the letters the folder holds at or above the number written down
	 * before the letter went up.
	 *
	 * The window is ours by construction, and that is what makes it a proof. Into a folder of a
	 * generation being prepared nobody else writes: the transfer service finished its work before
	 * this stage starts, and the mail of the mailbox keeps arriving on the source it is still
	 * served from until the switch. So either the letter is in the window - the append happened,
	 * and its coordinates are known after all - or the window is empty and the append never did.
	 *
	 * A letter of the window is recognized by the mark of the server it was received under, which
	 * the append carried as a parameter, and by its own identifier when it has one. The size of
	 * the letter is not compared: it would mean assembling the whole message again, and the window
	 * is one letter wide anyway.
	 *
	 * @param array|null $found Out: the coordinates of the letter.
	 * @return bool|null False when the window answered that the letter is not there; null when
	 *         there is no window to ask - the source named no number for the next letter, the
	 *         epoch of the folder changed since, or it could not be asked at all.
	 */
	private function foundInTheWindowOfTheAppend(
		Context $context,
		Helper\Mailbox\Imap $prepared,
		string $folder,
		int $messageId,
		array $inFlight,
		?array &$found,
	): ?bool
	{
		$epoch = (int)($inFlight['epoch'] ?? 0);
		$from = (int)($inFlight['from'] ?? 0);

		if ($epoch <= 0 || $from <= 0)
		{
			return null;
		}

		$window = $prepared->listAppendedSince($folder, $epoch, $from);

		if ($window === null)
		{
			return null;
		}

		if ($window === [])
		{
			return false;
		}

		$letter = $this->letterOfTheWindow($context, $messageId, $window);

		if ($letter === null)
		{
			/*
				The window holds letters and none of them is this one. The assumption behind the
				window does not hold here, so the window is no answer either - and a wrong "not
				there" would put a second copy into the mailbox of the user.
			*/
			return null;
		}

		$found = ['uidValidity' => $epoch, 'uid' => $letter];

		return true;
	}

	/**
	 * Which letter of the window is ours, by the mark of the server and by the identifier of the
	 * letter when it has one.
	 *
	 * @param array<int, array{uid: int, header: string, internalDate: int}> $window
	 * @return int|null The number of the letter, null when none of them is ours.
	 */
	private function letterOfTheWindow(Context $context, int $messageId, array $window): ?int
	{
		$row = MailMessageTable::getRow([
			'select' => ['MSG_ID'],
			'filter' => ['=ID' => $messageId, '=MAILBOX_ID' => $context->mailboxId],
		]);
		$identifier = trim((string)($row['MSG_ID'] ?? ''), ' <>');

		$placement = $this->loadArchivedPlacements($context->mailboxId, [$messageId])[$messageId] ?? null;
		$stamp = ($placement['INTERNALDATE'] ?? null) instanceof DateTime
			? $placement['INTERNALDATE']->getTimestamp()
			: 0
		;

		foreach ($window as $letter)
		{
			if ($stamp > 0 && (int)$letter['internalDate'] !== $stamp)
			{
				continue;
			}

			if ($identifier !== '' && !$this->headerCarriesIdentifier((string)$letter['header'], $identifier))
			{
				continue;
			}

			return (int)$letter['uid'];
		}

		return null;
	}

	private function headerCarriesIdentifier(string $block, string $identifier): bool
	{
		if ($block === '')
		{
			return false;
		}

		$header = \CMailMessage::parseHeader($block, defined('LANG_CHARSET') ? LANG_CHARSET : 'utf-8');

		return trim((string)$header->getHeader('MESSAGE-ID'), ' <>') === $identifier;
	}

	/**
	 * Whether the matching journal of the generation already answers for the letter.
	 *
	 * Addressed by the mailbox and the letter, which is the index of the journal
	 * (IX_MAIL_SOURCE_GENERATION_MATCH_MESSAGE), and not by the coordinates - those are exactly what a pass
	 * that died between the append and the verdict never wrote down.
	 */
	private function hasVerdictOf(Context $context, int $messageId): bool
	{
		return SourceGenerationMatchTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=MAILBOX_ID' => $context->mailboxId,
				'=MESSAGE_ID' => $messageId,
				'=GENERATION_ID' => $context->generationId,
				'=STATE' => SourceGenerationMatchTable::STATE_MATCHED,
			],
		]) !== null;
	}

	/**
	 * The letters of the page the matching journal of the generation already answers for, in one
	 * statement by the index of the journal {@see hasVerdictOf()}.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true>
	 */
	private function findVerdictsOf(Context $context, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = SourceGenerationMatchTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $context->mailboxId,
				'@MESSAGE_ID' => array_map('intval', $messageIds),
				'=GENERATION_ID' => $context->generationId,
				'=STATE' => SourceGenerationMatchTable::STATE_MATCHED,
			],
		])->fetchAll();

		return array_fill_keys(array_map(static fn (array $row): int => (int)$row['MESSAGE_ID'], $rows), true);
	}

	/**
	 * Which of the two walks the stage owes next.
	 *
	 * The walk over the folders a hand over reopened comes first: it is opened only after both
	 * ordinary walks are through.
	 *
	 * @param array<string, mixed> $progress
	 * @return array{cursor: string, done: string, referenced: bool, folders: string[]|null}|null Null
	 *         when every walk is through.
	 */
	private function currentPass(array $progress): ?array
	{
		$reopened = array_filter((array)($progress[self::STATE_REOPENED_DIRS] ?? []), 'is_array');
		if ($reopened !== [] && !($progress[self::STATE_REOPENED_DONE] ?? false))
		{
			return [
				'cursor' => self::STATE_REOPENED_CURSOR,
				'done' => self::STATE_REOPENED_DONE,
				'referenced' => false,
				'folders' => array_values(array_unique(array_map(
					static fn (array $dir): string => (string)($dir['hash'] ?? ''),
					$reopened,
				))),
			];
		}

		if (!($progress[self::STATE_REFERENCED_DONE] ?? false))
		{
			return [
				'cursor' => self::STATE_REFERENCED_CURSOR,
				'done' => self::STATE_REFERENCED_DONE,
				'referenced' => true,
				'folders' => null,
			];
		}

		if (!($progress[self::STATE_REST_DONE] ?? false))
		{
			return [
				'cursor' => self::STATE_REST_CURSOR,
				'done' => self::STATE_REST_DONE,
				'referenced' => false,
				'folders' => null,
			];
		}

		return null;
	}

	/**
	 * @param array{cursor: string, done: string, referenced: bool, folders: string[]|null} $pass
	 * @param array<string, mixed> $progress
	 */
	private function readPage(Context $context, array $pass, array $progress): TailRemainderPage
	{
		$cursor = (int)($progress[$pass['cursor']] ?? 0);

		if ($pass['folders'] !== null)
		{
			return $this->remainder->listFolderPage($context, $pass['folders'], $cursor);
		}

		return $pass['referenced']
			? $this->remainder->listReferencedPage($context, $cursor)
			: $this->remainder->listUnreferencedPage($context, $cursor)
		;
	}

	/**
	 * The letters of one page as our own database keeps them, each with the coordinates of
	 * its archived placement joined in.
	 *
	 * A page at a time and not a letter at a time: the walk of a pass asks for fifty letters,
	 * and three statements for the page is what a source slow enough to need the time quota
	 * leaves room for.
	 *
	 * @param int[] $messageIds
	 * @return array<int, array> Letter => its row; a letter whose row or whose archived
	 *         placement is gone is not there at all.
	 */
	private function loadLetters(Context $context, array $messageIds): array
	{
		$messageIds = array_map('intval', $messageIds);

		if ($messageIds === [])
		{
			return [];
		}

		$rows = MailMessageTable::getList([
			'select' => [
				'ID',
				'MAILBOX_ID',
				'SUBJECT',
				'BODY',
				'BODY_HTML',
				'HEADER',
				'MSG_ID',
				'IN_REPLY_TO',
				'FIELD_FROM',
				'FIELD_TO',
				'FIELD_CC',
				'FIELD_BCC',
				'FIELD_REPLY_TO',
				'ATTACHMENTS',
				'OPTIONS',
				// Whether the markup of the row is stored cleaned up or is cleaned up on the read
				MailMessageTable::FIELD_SANITIZE_ON_VIEW,
			],
			'filter' => [
				'@ID' => $messageIds,
				'=MAILBOX_ID' => $context->mailboxId,
			],
		])->fetchAll();

		$placements = $this->loadArchivedPlacements($context->mailboxId, $messageIds);
		$letters = [];

		foreach ($rows as $row)
		{
			$messageId = (int)$row['ID'];
			$placement = $placements[$messageId] ?? null;

			if ($placement === null)
			{
				continue;
			}

			/*
				The lazy download of the attachments reads the coordinates off the very row it is
				given, and the boundary of the engine checks the generation and the folder version
				of them: without them the download would be refused.
			*/
			$letters[$messageId] = $row + [
				'DIR_MD5' => $placement['DIR_MD5'],
				'MSG_UID' => $placement['MSG_UID'],
				'DIR_UIDV' => $placement['DIR_UIDV'],
				'GENERATION_ID' => $placement['GENERATION_ID'],
				'__placement' => $placement,
			];
		}

		return $letters;
	}

	/**
	 * The folder of the prepared generation this letter of the remainder goes into: the folder
	 * the transfer brought into the new server for the folder the letter lies in, then the sent
	 * one when its archived placement lies in a sent folder, the incoming one otherwise.
	 *
	 * The placement is addressed by both its generation and its path hash: retained generations
	 * stay in the same physical table and may contain equal paths.
	 *
	 * @param array|null $letter
	 * @param array<string, string> $destinations
	 */
	private function destinationOf(
		?array $letter,
		SystemRoleMap $roles,
		FolderPairMap $pairs,
		array $destinations,
	): ?string
	{
		$inbox = $destinations[MailboxDirectoryTable::TYPE_INCOME] ?? null;

		if ($letter === null)
		{
			return $inbox;
		}

		$placement = $letter['__placement'];
		$paired = $pairs->targetOf((int)$placement['GENERATION_ID'], (string)$placement['DIR_MD5']);

		if ($paired !== null && isset($this->preparedSyncPaths[$paired['path']]))
		{
			return $paired['path'];
		}
		$sent = $roles->holds(
			(int)$placement['GENERATION_ID'],
			(string)$placement['DIR_MD5'],
			MailboxDirectoryTable::TYPE_OUTCOME,
		);

		return $sent ? ($destinations[MailboxDirectoryTable::TYPE_OUTCOME] ?? null) : $inbox;
	}

	/**
	 * The system roles of the folders of the mailbox, read once for the whole pass: a page of
	 * the remainder is hundreds of letters and the roles of both generations are in one answer.
	 */
	private function systemRoles(int $mailboxId): SystemRoleMap
	{
		return $this->systemRoles ??= SystemRoleMap::ofMailbox($mailboxId);
	}

	private function folderPairs(Context $context): FolderPairMap
	{
		return $this->folderPairs ??= FolderPairMap::ofGeneration($context->mailboxId, $context->generationId);
	}

	/**
	 * The placements of the letters on the source still serving the mailbox: where their
	 * attachments are downloaded from, when the server received them and whether the user has
	 * read them.
	 *
	 * The scope is the one of the pointer of the mailbox and it comes from
	 * {@see GenerationScope} whole - a placement of the generation being prepared is not one
	 * of these, and a letter of the remainder has none anyway.
	 *
	 * @param int[] $messageIds
	 * @return array<int, array> Letter => the oldest placement of it, which is the one it
	 *         arrived as: one letter can lie in several folders.
	 */
	private function loadArchivedPlacements(int $mailboxId, array $messageIds): array
	{
		$rows = MailMessageUidTable::getList([
			'select' => ['ID', 'MESSAGE_ID', 'DIR_MD5', 'MSG_UID', 'DIR_UIDV', 'GENERATION_ID', 'INTERNALDATE', 'IS_SEEN'],
			'filter' => GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIds,
				'==DELETE_TIME' => 0,
			]),
			'order' => ['INTERNALDATE' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$oldest = [];

		foreach ($rows as $row)
		{
			// The order is the oldest first, so the first placement of a letter is the one
			$oldest[(int)$row['MESSAGE_ID']] ??= $row;
		}

		return $oldest;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, array<int, array>> Letter => rows of its attachments, oldest first.
	 */
	private function loadAttachmentsOfLetters(array $messageIds): array
	{
		$messageIds = array_map('intval', $messageIds);

		if ($messageIds === [])
		{
			return [];
		}

		$rows = MailMessageAttachmentTable::getList([
			'select' => ['ID', 'MESSAGE_ID', 'FILE_ID', 'FILE_NAME', 'CONTENT_TYPE'],
			'filter' => ['@MESSAGE_ID' => $messageIds],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		$attachments = [];

		foreach ($rows as $row)
		{
			$attachments[(int)$row['MESSAGE_ID']][] = $row;
		}

		return $attachments;
	}

	/**
	 * @return array<int, array> Rows of the attachments of the letter, oldest first. Asked for
	 *         one letter only when the download of the pass has just created them.
	 */
	private function loadAttachments(int $messageId): array
	{
		return MailMessageAttachmentTable::getList([
			'select' => ['ID', 'FILE_ID', 'FILE_NAME', 'CONTENT_TYPE'],
			'filter' => ['=MESSAGE_ID' => $messageId],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	/**
	 * The mark of the server the letter really arrived under, which is what the append
	 * command asks for as a parameter of its own.
	 *
	 * The column of the date of the message is never used for it: that one is written with
	 * the timezone offset of the portal added, and the whole history would move by hours.
	 */
	private function serverMarkOf(array $placement): \DateTime
	{
		$stamp = $placement['INTERNALDATE'] ?? null;
		$timestamp = $stamp instanceof DateTime ? $stamp->getTimestamp() : time();

		return (new \DateTime())->setTimestamp($timestamp);
	}

	/**
	 * The folder of the prepared generation the remainder goes into: the one holding the
	 * role of the incoming mail.
	 *
	 * A letter appended anywhere else would never be read back - the import walks the
	 * synchronized folders of the generation and no others - so the folder is confirmed to
	 * be one of them here as well, and not only inside the append.
	 */
	private function findDestinations(Helper\Mailbox\Imap $prepared, Context $context): array
	{
		$syncPaths = [];

		foreach ($prepared->getDirsHelper()->getSyncDirs() as $dir)
		{
			$syncPaths[(string)$dir->getPath()] = true;
		}

		$this->preparedSyncPaths = $syncPaths;

		$roles = $this->systemRoles($context->mailboxId);
		$destinations = [];

		foreach ([MailboxDirectoryTable::TYPE_INCOME, MailboxDirectoryTable::TYPE_OUTCOME] as $role)
		{
			$path = $roles->pathOf($context->generationId, $role);

			if ($path !== null && isset($syncPaths[$path]))
			{
				$destinations[$role] = $path;
			}
		}

		return $destinations;
	}

	/**
	 * The engine of the generation serving the mailbox right now, built once for the whole
	 * pass. The lazy path of the reading screens builds one per letter, which on a tail of
	 * thousands is a login, a folder listing and a connection per letter.
	 */
	private function engineOfActiveGeneration(int $mailboxId): ?Helper\Mailbox
	{
		if (!$this->activeGenerationEngineAsked)
		{
			$this->activeGenerationEngineAsked = true;
			$this->activeGenerationEngine = $this->createActiveGenerationEngine($mailboxId);
		}

		return $this->activeGenerationEngine;
	}

	/**
	 * The engine talking to the new source, writing into the prepared generation only.
	 */
	protected function createPreparedGenerationEngine(
		int $mailboxId,
		array $connection,
		Context $context,
	): ?Helper\Mailbox\Imap
	{
		$engine = Helper\Mailbox::createGenerationInstance($mailboxId, $context, $connection);

		return $engine instanceof Helper\Mailbox\Imap ? $engine : null;
	}

	/**
	 * The engine talking to the source the mailbox is served from, built from its stored
	 * connection the way every ordinary synchronization builds it.
	 */
	protected function createActiveGenerationEngine(int $mailboxId): ?Helper\Mailbox
	{
		$engine = Helper\Mailbox::createInstance($mailboxId, false);

		return $engine instanceof Helper\Mailbox ? $engine : null;
	}

	/**
	 * How many letters of the remainder one pass appends. A remainder larger than that is
	 * carried over by several runs of the same operation.
	 */
	protected function getPassBudget(): int
	{
		return self::PASS_BUDGET;
	}

	/**
	 * How long one pass of the stage may keep working, in seconds.
	 *
	 * The same bound the import of the migration keeps, and for the same reason: what one
	 * letter costs is an append of the new source plus, for a letter whose files are still on
	 * the old one, a download from the other server - so the budget in letters says nothing
	 * about the time a slow source turns them into. The tenth left over is the room the pass
	 * needs to write down where it stopped.
	 */
	protected function getTimeQuota(): int
	{
		return max(1, (int)ceil(Helper\Mailbox::getTimeout() * 0.9));
	}

	private function clearInFlight(int $generationId): void
	{
		$this->store($generationId, [self::STATE_IN_FLIGHT => null]);
	}

	/**
	 * The progress of the stage, as the prepared generation keeps it.
	 *
	 * @return array<string, mixed>|null Null when the generation is gone, which is how the
	 *         deletion of a mailbox asks a pass to stop.
	 */
	private function readProgress(int $generationId): ?array
	{
		$row = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'OPTIONS'],
			'filter' => ['=ID' => $generationId],
		])->fetch();

		if (!$row)
		{
			return null;
		}

		return is_array($row['OPTIONS'] ?? null) ? $row['OPTIONS'] : [];
	}

	/**
	 * Keeps the progress of the stage with its generation. Only the keys of this stage are
	 * written, and the stage of the operation itself belongs to {@see MigrationService}.
	 *
	 * @param array<string, mixed> $progress A null value removes the key.
	 */
	private function store(int $generationId, array $progress): void
	{
		$options = $this->readProgress($generationId);

		if ($options === null)
		{
			return;
		}

		foreach ($progress as $key => $value)
		{
			if ($value === null)
			{
				unset($options[$key]);

				continue;
			}

			$options[$key] = $value;
		}

		MailboxSourceGenerationTable::update($generationId, ['OPTIONS' => $options]);
	}

	private function continued(Result $result): Result
	{
		return $result->setData([MigrationService::PASS_CONTINUES => true]);
	}
}
