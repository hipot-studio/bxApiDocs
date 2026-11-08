<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CandidateProvider;
use Bitrix\Mail\MailMessageUidTable;

/**
 * What the user did with their letters while the transfer was running, applied to the new
 * source right before the mailbox changes hands.
 *
 * Days pass between the moment a letter is carried over and the moment an administrator
 * allows the mailbox to change hands, and everything done in them lives on the portal alone.
 * The copy on the new source is a snapshot of the old one, and nobody works in it. Deleted
 * letters would rise again in the inbox, spam would come back, a letter pulled out of the bin
 * would return to it. This pass walks the letters that have a live placement on both sides,
 * reads the state off the portal and tells the new source to match it.
 *
 * The portal is always right. There is no direction in which the new source corrects the
 * portal: it holds a copy taken at the transfer, and a difference between the two can only
 * have been made here.
 *
 * What it does NOT do, all of it deliberate:
 *
 *  - it decides no identity. The pairs come from the matching, which is final by the time
 *    this runs, and a letter without a live placement on either side is somebody else's
 *    history.
 *  - it moves, never copies. The command of the module chooses MOVE or COPY plus a deletion
 *    by what the server can do, and neither of the two leaves a copy behind.
 *  - it removes nothing. A letter already lying in the target role on the new source keeps
 *    the copy it has and gets no command, so the pass never creates a second one.
 *  - it touches folders of the user on neither side, and no letter of them is moved - except a
 *    letter lying on the portal in a folder the transfer created on the new source, which is
 *    moved into that folder {@see FolderPairMap}, unless its only copies there lie in a role
 *    the portal keeps it in as well {@see copyFreeToMove()}.
 *  - it sends no flag of its own. The one flag it does set on the new source is the reading a
 *    letter it is about to move is already waiting to push, which the move would otherwise
 *    strand {@see move()}; every other reading is left to the ordinary synchronization.
 *  - it writes nothing but the placement row of the prepared generation. No letter row, no
 *    binding, no thread: the identity of a letter is what its bindings hang on, and the row
 *    is repainted rather than recreated for the same reason.
 *
 * A refusal of the server is counted and left behind: nothing is rolled back, no repeat is
 * scheduled and the walk goes on. A new source that cannot be reached at all is another matter
 * and refuses the whole pass {@see ActionReplayPage::REFUSAL_SOURCE}, because a walk that
 * counted every letter of the mailbox as refused would end successfully and let the switch
 * behind it through. The two are told apart by what the client of the engine reports
 * {@see Helper\Mailbox\Imap::hasLostTheConnection()}, and a connection lost halfway through a
 * page refuses that page as surely as one that was never made.
 *
 * The pass is bounded and resumable, page by page and inside a page as well: the letters are
 * walked by a growing identifier, the deadline of the caller is met between letters and between
 * commands, and the cursor names the last letter the page is really done with. The cursor is
 * kept by the operation {@see MigrationService}, so no letter is ever carried twice - a page cut
 * short in the middle is walked again from the letter its commands stopped at, and a letter
 * already lying where the portal wants it gets no command whichever pass looks at it.
 */
class ActionReplayService
{
	/** Letters of the mailbox one page of the walk asks about */
	public const PAGE_SIZE = 500;

	/** The engine talking to the new source, and the pair of the mailbox and generation it serves */
	private ?Helper\Mailbox\Imap $prepared = null;

	private string $preparedFor = '';

	/** The system roles of the folders of the mailbox, and the mailbox they were read for */
	private ?SystemRoleMap $roles = null;

	private int $rolesOf = 0;

	/** The folders the transfer created on the new source, and the generation they were read for */
	private ?FolderPairMap $pairs = null;

	private int $pairsOf = 0;

	public function __construct(
		private readonly CandidateProvider $candidates = new CandidateProvider(),
	)
	{
	}

	/**
	 * One bounded page of the letters of the mailbox.
	 *
	 * @param array $connection The stored credentials of the prepared generation.
	 * @param int $afterMessageId The letter the previous page stopped at, 0 starts the walk.
	 * @param int|null $deadline The moment the pass has to be out of the lease of the mailbox by,
	 *        null for a page bounded by its letters alone.
	 */
	public function replay(
		Context $context,
		array $connection,
		MigrationMetrics $metrics,
		int $afterMessageId = 0,
		int $limit = self::PAGE_SIZE,
		?int $deadline = null,
	): ActionReplayPage
	{
		$cursor = max($afterMessageId, 0);

		if (!$this->mayRun($context))
		{
			return new ActionReplayPage($cursor, refusal: ActionReplayPage::REFUSAL_CONTEXT);
		}

		$page = array_map('intval', $this->candidates->listMessageIds($context->mailboxId, $cursor, max($limit, 1)));
		if ($page === [])
		{
			return new ActionReplayPage($cursor, isExhausted: true);
		}

		/*
			The engine is built before the folder roles are read and not when the first command
			is about to go out: building it is the one moment of the pass that reaches the
			folders of the new source, and every decision of the page is taken by that map -
			which role the letter aims at, whether the folder it lies in is one of the user,
			whether the role exists on the new source at all. A map read before the listing
			would let a letter whose folder the portal does not know yet be counted UNROUTED
			and walked over for good, and the safety net that drops the map would come too
			late to have protected anything. The engine is built once for the whole operation,
			so this costs one connection per operation and not one per page, and a new source
			that cannot be reached refuses the pass here rather than let a walk taken on an
			unverified map end successfully in front of the switch.
		*/
		$engine = $this->engineOfThePreparedGeneration($context, $connection);

		if ($engine === null)
		{
			return new ActionReplayPage($cursor, refusal: ActionReplayPage::REFUSAL_SOURCE);
		}

		/*
			The placements of the whole page and the folder roles of the whole mailbox come in
			two statements: a letter of the page costs neither a read of its own nor a lookup of
			the folder it lies in, and a page is hundreds of letters.
		*/
		$placements = $this->loadPlacements($context->mailboxId, $page);
		$roles = $this->systemRoles($context->mailboxId);
		$pairs = $this->folderPairs($context);
		$retainedScope = GenerationScope::withoutPreparedGenerations($context->mailboxId);
		$activeScope = GenerationScope::forMailbox($context->mailboxId);

		/** @var array<string, array{folder: array, letter: int, placements: array<int, array>, pending: array<string, string>}> */
		$moves = [];

		/** @var int[] The letters of the page this call really looked at, in the order it did */
		$walked = [];

		/**
		 * The intentions of the whole page, gathered here and written at the end of it
		 * {@see carryReadState()}.
		 *
		 * @var array<string, string[]> The intention to write => the rows it goes into
		 */
		$intents = [];

		foreach ($page as $messageId)
		{
			$move = $this->replayLetter(
				$context,
				$metrics,
				$roles,
				$pairs,
				$retainedScope,
				$activeScope,
				$placements[$messageId] ?? [],
				$intents,
			);

			$walked[] = $messageId;

			if ($move !== null)
			{
				/*
					The letters are gathered by the pair of folders they travel between and not
					sent one by one: the module groups the placements it is handed by the folder
					they lie in and gives the server one command per pair. A page therefore costs
					as many commands as the letters of it have pairs - at most the folders of the
					roles on one side by the folders of the roles on the other - instead of one
					command and one round trip for every letter.
				*/
				$key = (string)$move['placement']['DIR_MD5'] . ':' . $move['folder']['hash'];
				$moves[$key] ??= [
					'folder' => $move['folder'],
					'letter' => $messageId,
					'placements' => [],
					'pending' => [],
				];
				$moves[$key]['placements'][] = $move['placement'];

				if ($move['pending'] !== null)
				{
					$moves[$key]['pending'][(string)$move['placement']['ID']] = $move['pending'];
				}
			}

			/*
				After the letter and never before it: a pass that came in with its time already
				spent still has to carry one letter, or a mailbox whose share of the lease runs
				out would be walked from the same page by every run of the operation.
			*/
			if ($deadline !== null && time() >= $deadline)
			{
				break;
			}
		}

		return $this->closeThePage(
			$context,
			$engine,
			$metrics,
			array_values($moves),
			$walked,
			$intents,
			$cursor,
			$deadline,
		);
	}

	/**
	 * The commands the page gathered, one for each pair of folders, and the page they add up to.
	 *
	 * @param array<int, array{folder: array, letter: int, placements: array<int, array>, pending: array<string, string>}> $moves
	 * @param int[] $walked
	 * @param array<string, string[]> $intents The intentions the letters of the page gathered.
	 */
	private function closeThePage(
		Context $context,
		Helper\Mailbox\Imap $engine,
		MigrationMetrics $metrics,
		array $moves,
		array $walked,
		array $intents,
		int $cursor,
		?int $deadline,
	): ActionReplayPage
	{
		/*
			Before the commands and not after them, for two reasons. The letters that are about to
			move carry their intention to the new source as a flag and end up repainted with the
			value it confirmed {@see move()}, so the write of the intention has to come first or it
			would put the wish back over a settled value. And a page that gives up halfway - a
			source gone, a lease run out - still owes the rows of the letters it did look at: the
			intention of a letter is decided by the portal alone and no command of the new source
			takes it back, while the cursor stays in front of the letters nobody has looked at yet.
		*/
		$this->writeTheIntents($context, $metrics, $intents);

		$lost = false;

		if ($moves !== [])
		{
			$sent = 0;

			foreach ($moves as $move)
			{
				if (!$this->move($context, $metrics, $engine, $move))
				{
					/*
						The connection to the new source is gone, so every command behind this one
						would fail for a reason that has nothing to do with the letters it names.
						The page refuses as a whole instead of counting the rest of the mailbox as
						letters the source turned down {@see ActionReplayPage::REFUSAL_SOURCE}.
					*/
					$lost = true;

					break;
				}

				++$sent;

				if ($deadline !== null && time() >= $deadline)
				{
					break;
				}
			}

			$moves = array_slice($moves, $sent);
		}

		return new ActionReplayPage(
			$this->cursorBefore($walked, $moves[0]['letter'] ?? 0, $cursor),
			refusal: $lost ? ActionReplayPage::REFUSAL_SOURCE : '',
		);
	}

	/**
	 * The letter the next page continues after: the last one of the walk that is really done with.
	 *
	 * A page whose commands were not all sent is done only up to the first letter still waiting
	 * for one. Everything behind that letter is walked a second time by the next page, and that
	 * costs nothing: a letter already lying in the role the portal asks for gets no command.
	 *
	 * @param int[] $walked
	 * @param int $stoppedAt The first letter still waiting for a command, 0 when none is.
	 */
	private function cursorBefore(array $walked, int $stoppedAt, int $cursor): int
	{
		foreach ($walked as $messageId)
		{
			if ($stoppedAt > 0 && $messageId >= $stoppedAt)
			{
				break;
			}

			$cursor = $messageId;
		}

		return $cursor;
	}

	/**
	 * Whether the pass may work under the context it was given at all.
	 *
	 * It moves letters on a mail server and repaints rows of the portal, and the one context that
	 * ever reaches it is an import into a generation being prepared - a source hidden from the
	 * user until the switch. Anything else is the live source of a working mailbox, so the pass
	 * refuses rather than trust the caller. The pointer of the mailbox is asked as well: a
	 * generation that has meanwhile become the active one is no longer one to move letters about
	 * on, whatever the context still says about it.
	 */
	private function mayRun(Context $context): bool
	{
		return $context->isMigrationImport()
			&& $context->getAccessLevel() === Context::ACCESS_APPEND_ONLY
			&& !GenerationScope::forMailbox($context->mailboxId)->includes($context->generationId)
		;
	}

	/**
	 * One letter, by ALG-02. Every branch that gives up leaves the letter exactly as it was,
	 * on both sides.
	 *
	 * @param array<int, array> $placements Every live placement of the letter, oldest first.
	 * @return array{placement: array, folder: array{path: string, hash: string}, pending: string|null}|null
	 *         The move the letter waits for and the reading it has to take with it, null when the
	 *         letter needs no move at all.
	 */
	private function replayLetter(
		Context $context,
		MigrationMetrics $metrics,
		SystemRoleMap $roles,
		FolderPairMap $pairs,
		GenerationScope $retainedScope,
		GenerationScope $activeScope,
		array $placements,
		array &$intents,
	): ?array
	{
		$retained = [];
		$active = [];
		$prepared = [];

		foreach ($placements as $placement)
		{
			$generationId = (int)$placement['GENERATION_ID'];

			if ($generationId === $context->generationId)
			{
				$prepared[] = $placement;

				continue;
			}

			if (!$retainedScope->includes($generationId))
			{
				continue;
			}

			$retained[] = $placement;

			if ($activeScope->includes($generationId))
			{
				$active[] = $placement;
			}
		}

		// Not a pair: a letter of one side alone is the history of that side and nothing to replay
		if ($retained === [] || $prepared === [])
		{
			return null;
		}

		$metrics->add(MigrationMetrics::PAIRS);

		/*
			The side of the portal is the source the user is working in, which is the active
			generation of the mailbox and not the oldest one it retains. A mailbox moved twice
			keeps the letters of the first move in a generation of its own, and that generation
			stopped answering for the letter the moment the first switch happened: what the user
			has done since - sent it to spam, pulled it out of the bin - they did in the active
			one. Reading the role off the older generation would replay the state of the previous
			move over the current one and undo everything between the two.

			The generations of earlier moves answer only for a letter the active one holds no live
			placement of: there the old word is the only word of the portal there is.
		*/
		$portalSide = $active === [] ? $retained : $active;

		/*
			And among the placements of that side, the one the letter arrived as - the oldest of
			them, as everything else reading the retained side of a transfer takes it. The
			placements of one letter usually carry one and the same date of the server, so what
			really tells them apart is the moment the portal wrote the row down; the identifier of
			the row closes the order behind it, so that two rows written in the same second still
			name one and the same placement on every run instead of leaving the choice to the
			database.
		*/
		$paired = null;
		foreach ($portalSide as $placement)
		{
			/*
				Any placement in a folder the transfer brought into the new source, not only the oldest:
				a folder renamed on the old server leaves the placement under the old name alive until
				the scheduled sweep of vanished folders, and that one is older than the placement under
				the new name.
			*/
			$paired = $pairs->targetOf((int)$placement['GENERATION_ID'], (string)$placement['DIR_MD5']);
			if ($paired !== null)
			{
				break;
			}
		}
		$move = null;

		if ($paired !== null)
		{
			/*
				The transfer brought that folder into the new source itself, so no copy lying anywhere
				else there was put by the user: the letter goes where the portal has it.
			*/
			$placement = $this->liesInTheFolder($paired['hash'], $prepared)
				? null
				: $this->copyFreeToMove($roles, $context->generationId, $portalSide, $prepared)
			;

			if ($placement !== null)
			{
				$move = ['placement' => $placement, 'folder' => $paired, 'pending' => null];
			}
		}
		else
		{
			$targetRole = $roles->roleOf((int)$portalSide[0]['GENERATION_ID'], (string)$portalSide[0]['DIR_MD5']);

			// A folder of the user on the side of the portal: the pass has no role to aim at
			if ($targetRole === null)
			{
				return null;
			}

			foreach ($prepared as $placement)
			{
				// A folder of the user on the new source is left alone, whatever the portal says
				if ($roles->roleOf($context->generationId, (string)$placement['DIR_MD5']) === null)
				{
					return null;
				}
			}

			if (!$this->liesInTheRole($roles, $context->generationId, $targetRole, $prepared))
			{
				$folder = $roles->folderOf($context->generationId, $targetRole);

				if ($folder === null)
				{
					/*
						The new source keeps no folder of that role: there is nowhere to put the letter,
						and the letter is no business of this pass afterwards.
					*/
					$metrics->add(MigrationMetrics::UNROUTED);

					return null;
				}

				$move = ['placement' => $prepared[0], 'folder' => $folder, 'pending' => null];
			}
		}

		$states = $this->carryReadState($portalSide, $prepared, $intents);

		if ($move !== null)
		{
			/*
				The reading the moving placement has to take with it {@see move()}. It is the state
				the row ends this decision in, and not only the intention just written: a row the
				import left waiting to push carries a state of ours that never reached the new
				source either, and the move takes away the coordinates it would have been pushed by
				all the same.
			*/
			$move['pending'] = $states[(string)$move['placement']['ID']] ?? null;
		}

		return $move;
	}

	/**
	 * Whether the new source holds the letter in the target role already. The copy lying there
	 * was put there by the service that carried the mailbox over, and ours would be the second
	 * one: the pass creates no copy, and the one standing there is not removed - a deletion on a
	 * mail server cannot be taken back.
	 *
	 * @param array<int, array> $prepared
	 */
	private function liesInTheRole(
		SystemRoleMap $roles,
		int $generationId,
		string $targetRole,
		array $prepared,
	): bool
	{
		foreach ($prepared as $placement)
		{
			if ($roles->roleOf($generationId, (string)$placement['DIR_MD5']) === $targetRole)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The copy on the new source a move into a created folder may take: one lying in a folder of
	 * the user or in a role the portal does not keep the letter in. A copy in a role the portal
	 * keeps it in as well - an inbox letter also filed into a folder of the user - stays where it
	 * is: moving it would take the letter out of that role on the new source, which is a removal.
	 *
	 * @param array<int, array> $portalSide
	 * @param array<int, array> $prepared
	 * @return array|null The placement to move, null when every copy is held by a role of the portal.
	 */
	private function copyFreeToMove(
		SystemRoleMap $roles,
		int $preparedGenerationId,
		array $portalSide,
		array $prepared,
	): ?array
	{
		$rolesOfThePortal = [];
		foreach ($portalSide as $placement)
		{
			foreach (SystemRoleMap::ROLES as $role)
			{
				if ($roles->holds((int)$placement['GENERATION_ID'], (string)$placement['DIR_MD5'], $role))
				{
					$rolesOfThePortal[$role] = true;
				}
			}
		}

		foreach ($prepared as $placement)
		{
			$role = $roles->roleOf($preparedGenerationId, (string)$placement['DIR_MD5']);

			if ($role === null || !isset($rolesOfThePortal[$role]))
			{
				return $placement;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array> $prepared
	 */
	private function liesInTheFolder(string $dirHash, array $prepared): bool
	{
		foreach ($prepared as $placement)
		{
			if ((string)$placement['DIR_MD5'] === $dirHash)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The move itself: one command to the new source for the whole pair of folders and, once it
	 * went through, the rows of the placements repainted exactly as a move by the user repaints
	 * them.
	 *
	 * The answer of the server is the answer to the pair as a whole - one command carried every
	 * letter of it - so a refusal is counted for every letter the command named. A refused
	 * command changes nothing and schedules nothing: the letters stay where the new source has
	 * them, the refusal is counted, and the walk goes on - a pass that stopped at a folder the
	 * server will not accept would never reach the letters behind it.
	 *
	 * A lost connection is the other thing entirely and the one case this answers false for. The
	 * letters it names were not turned down by anybody, and neither will be the ones behind them:
	 * the page gives up instead {@see closeThePage()}.
	 *
	 * The reading a moving letter is waiting to push goes out first, over the coordinates it is
	 * still reachable by. A placement waiting to push is normally left to the ordinary
	 * synchronization of its folder, which finds the row by the folder and the number it carries
	 * and turns the waiting state into a command - but the move takes both of those away, and a
	 * row the pass leaves behind with no number in it is never picked up by that synchronization
	 * again. So the intention is realized here, in the same breath as the move and before it, and
	 * the row is left holding the settled value the new source has just confirmed.
	 *
	 * A flag the server would not take does not hold the letter of it back: the letter moves all
	 * the same. The move is the promise of the whole feature - a letter the user threw away does
	 * not come back to the inbox of the new source - and the reading of it is the smaller thing.
	 * A refusal of the server to one command of ours is not handled apart from being counted, the
	 * same as a refused move {@see MigrationMetrics::MOVE_REFUSED} is; the counter of it
	 * {@see MigrationMetrics::FLAG_REFUSED} is the count of the readings that did not make it
	 * over, and that reading is lost for good: the move leaves the row with no number, the
	 * synchronization of the new folder picks up no row that has none and brings the letter in as
	 * a row of its own with the flags the server holds, and the binding of the two carries the
	 * identity of the letter over but not the reading. So the letter ends up on the new source
	 * read the way the new source had it, and the counter is the honest size of that loss.
	 *
	 * The row is moved with the wish it carries left exactly as it is, which is a value nothing
	 * reads any more rather than a promise of a later delivery. A settled value in its place would
	 * be worse on both counts: it would claim a confirmation the new source never gave, and the
	 * next run of the pass would find it disagreeing with the portal and write the same wish back
	 * over it anyway.
	 *
	 * @param array{folder: array{path: string, hash: string}, placements: array<int, array>, pending: array<string, string>} $move
	 * @return bool Whether the new source is still there. False holds the whole page back.
	 */
	private function move(
		Context $context,
		MigrationMetrics $metrics,
		Helper\Mailbox\Imap $engine,
		array $move,
	): bool
	{
		['settled' => $settled, 'refused' => $refused, 'lost' => $lost] = $this->pushTheWaitingReadState($engine, $move);

		if ($lost)
		{
			return false;
		}

		if ($refused !== [])
		{
			$metrics->add(MigrationMetrics::FLAG_REFUSED, count($refused));
		}

		if (!$engine->moveMailsToFolder($move['placements'], $move['folder']['path'])->isSuccess())
		{
			if ($engine->hasLostTheConnection())
			{
				return false;
			}

			$metrics->add(MigrationMetrics::MOVE_REFUSED, count($move['placements']));

			return true;
		}

		$metrics->add(MigrationMetrics::MOVED, count($move['placements']));

		/*
			The same rows, the same letters, the new folder and no number in it: the number of a
			letter belongs to the folder it lies in, and the first synchronization after the switch
			is what gives it the one the new source assigned.

			One statement for the whole pair of folders, and one more for each of the two settled
			readings among them, instead of a statement per letter: a mailbox the user tidied up in
			one go brings thousands of letters through one pair.
		*/
		foreach ($this->repaintings($move, $settled) as ['fields' => $fields, 'ids' => $ids])
		{
			MailMessageUidTable::updateList(
				[
					'=MAILBOX_ID' => $context->mailboxId,
					'@ID' => $ids,
				],
				$fields,
				[],
				// The rows of a source the user has not been handed over to yet notify nobody
				false,
			);
		}

		return true;
	}

	/**
	 * The waiting readings of the letters of this pair of folders, pushed to the new source over
	 * the coordinates the move is about to take away {@see move()}.
	 *
	 * One command per state and not per letter: the engine groups the rows it is handed by the
	 * folder they lie in, and every row of a pair lies in the same one. A command the server turns
	 * down is turned down for every letter it named, and each of them is counted as a reading that
	 * did not make it {@see move()}; a connection lost over one of them is the end of the page.
	 *
	 * @param array{placements: array<int, array>, pending: array<string, string>} $move
	 * @return array{settled: array<string, string>, refused: array<string, true>, lost: bool}
	 *         Placement => the settled value the new source confirmed, the placements whose flag
	 *         it would not take, and whether the new source is still there at all.
	 */
	private function pushTheWaitingReadState(Helper\Mailbox\Imap $engine, array $move): array
	{
		$settled = [];
		$refused = [];

		if ($move['pending'] === [])
		{
			return ['settled' => $settled, 'refused' => $refused, 'lost' => false];
		}

		$commands = [
			ReadStateIntent::PENDING_READ => [ReadStateIntent::SETTLED_READ, 'markSeen'],
			ReadStateIntent::PENDING_UNREAD => [ReadStateIntent::SETTLED_UNREAD, 'markUnseen'],
		];

		foreach ($commands as $pending => [$settledValue, $command])
		{
			$rows = array_values(array_filter(
				$move['placements'],
				static fn (array $placement): bool => ($move['pending'][(string)$placement['ID']] ?? '') === $pending,
			));

			if ($rows === [])
			{
				continue;
			}

			if (!$engine->{$command}($rows)->isSuccess())
			{
				if ($engine->hasLostTheConnection())
				{
					return ['settled' => $settled, 'refused' => $refused, 'lost' => true];
				}

				foreach ($rows as $placement)
				{
					$refused[(string)$placement['ID']] = true;
				}

				continue;
			}

			foreach ($rows as $placement)
			{
				$settled[(string)$placement['ID']] = $settledValue;
			}
		}

		return ['settled' => $settled, 'refused' => $refused, 'lost' => false];
	}

	/**
	 * The rows of one pair of folders grouped by the fields they are repainted with, so that each
	 * group is one statement.
	 *
	 * @param array{folder: array{path: string, hash: string}, placements: array<int, array>} $move
	 * @param array<string, string> $settled
	 * @return array<string, array{fields: array<string, mixed>, ids: string[]}>
	 */
	private function repaintings(array $move, array $settled): array
	{
		$groups = [];

		foreach ($move['placements'] as $placement)
		{
			$id = (string)$placement['ID'];
			$readState = $settled[$id] ?? '';

			if (!isset($groups[$readState]))
			{
				$fields = [
					'DIR_MD5' => $move['folder']['hash'],
					'MSG_UID' => 0,
				];

				if ($readState !== '')
				{
					$fields['IS_SEEN'] = $readState;
				}

				$groups[$readState] = ['fields' => $fields, 'ids' => []];
			}

			$groups[$readState]['ids'][] = $id;
		}

		return $groups;
	}

	/**
	 * The reading of the letter, decided whether the letter was moved or not: the two states
	 * are independent, and a letter already lying right may still have been read since.
	 *
	 * Nothing is written here. The intention of the letter joins the ones of the page it belongs
	 * to, and the page writes them all at its end {@see writeTheIntents()}: a page is hundreds of
	 * letters, and a statement for each of them would be hundreds of them under the lease of the
	 * mailbox.
	 *
	 * @param array<int, array> $portalSide The placements the word of the portal is read off.
	 * @param array<int, array> $prepared
	 * @param array<string, string[]> $intents The intentions of the page so far, added to here.
	 * @return array<string, string> Placement => the waiting state it is left in, the ones it was
	 *         already left in by an earlier run included: those have not reached the new source
	 *         either, and a moving letter has to take them along {@see move()}.
	 */
	private function carryReadState(array $portalSide, array $prepared, array &$intents): array
	{
		$portalHasRead = ReadStateIntent::portalHasRead(array_map(
			static fn (array $placement): string => (string)$placement['IS_SEEN'],
			$portalSide,
		));

		$states = [];

		foreach ($prepared as $placement)
		{
			$state = (string)$placement['IS_SEEN'];

			/*
				No third argument: every row this pass writes belongs to the generation being
				prepared, which the user cannot reach, so a waiting state in it is our own answer
				of an earlier run and is recomputed rather than guarded {@see ReadStateIntent}.
			*/
			$intent = ReadStateIntent::forPlacement($portalHasRead, $state);

			if ($intent !== null)
			{
				$intents[$intent][] = (string)$placement['ID'];

				$state = $intent;
			}

			if ($state === ReadStateIntent::PENDING_READ || $state === ReadStateIntent::PENDING_UNREAD)
			{
				$states[(string)$placement['ID']] = $state;
			}
		}

		return $states;
	}

	/**
	 * The intentions the whole page decided on, one statement for each of the two and not one per
	 * letter: a page is hundreds of letters and the lease of the mailbox is held for all of it.
	 *
	 * The list of a statement is a page of the walk at most {@see PAGE_SIZE} - so is the longest
	 * list the repainting after a move ever names - which stays well inside what a statement may
	 * carry, so neither is cut into batches. Both go through the ORM rather than a statement of
	 * ours: the filter is an IN over the keys, which every database the portal runs on reads the
	 * same.
	 *
	 * @param array<string, string[]> $intents The intention to write => the rows it goes into.
	 */
	private function writeTheIntents(Context $context, MigrationMetrics $metrics, array $intents): void
	{
		foreach ($intents as $intent => $ids)
		{
			if ($ids === [])
			{
				continue;
			}

			MailMessageUidTable::updateList(
				[
					'=MAILBOX_ID' => $context->mailboxId,
					'@ID' => $ids,
				],
				['IS_SEEN' => $intent],
				[],
				// The rows of a source the user has not been handed over to yet notify nobody
				false,
			);

			$metrics->add(MigrationMetrics::READ_INTENTS, count($ids));
		}
	}

	/**
	 * Every live placement of the letters of the page, of every generation of the mailbox.
	 *
	 * @param int[] $messageIds
	 * @return array<int, array<int, array>> Letter => its placements, the one it arrived as first.
	 */
	private function loadPlacements(int $mailboxId, array $messageIds): array
	{
		$rows = MailMessageUidTable::getList([
			'select' => ['ID', 'MESSAGE_ID', 'GENERATION_ID', 'DIR_MD5', 'MSG_UID', 'IS_SEEN'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => array_map('intval', $messageIds),
				'==DELETE_TIME' => 0,
			],
			// The order the oldest placement of a letter is taken by {@see replayLetter()}
			'order' => ['INTERNALDATE' => 'ASC', 'DATE_INSERT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$placements = [];

		foreach ($rows as $row)
		{
			$placements[(int)$row['MESSAGE_ID']][] = $row;
		}

		return $placements;
	}

	/**
	 * The system roles of the folders of the mailbox, read once for the whole pass: a page is
	 * hundreds of letters, one answer holds the roles of every generation of the mailbox, and
	 * nothing of the pass writes a folder row.
	 */
	private function systemRoles(int $mailboxId): SystemRoleMap
	{
		if ($this->rolesOf !== $mailboxId || $this->roles === null)
		{
			$this->rolesOf = $mailboxId;
			$this->roles = SystemRoleMap::ofMailbox($mailboxId);
		}

		return $this->roles;
	}

	private function folderPairs(Context $context): FolderPairMap
	{
		if ($this->pairsOf !== $context->generationId || $this->pairs === null)
		{
			$this->pairsOf = $context->generationId;
			$this->pairs = FolderPairMap::ofGeneration($context->mailboxId, $context->generationId);
		}

		return $this->pairs;
	}

	/**
	 * The engine talking to the new source, built on the first page of the walk and kept for
	 * the rest of the operation: a connection, a login and a folder listing is what one of them
	 * costs, and one page pays it for every page behind it.
	 */
	private function engineOfThePreparedGeneration(Context $context, array $connection): ?Helper\Mailbox\Imap
	{
		$key = $context->mailboxId . ':' . $context->generationId;

		if ($this->preparedFor !== $key)
		{
			$engine = $this->createPreparedGenerationEngine($context, $connection);

			/*
				A failure is not remembered: the key is set only once there is an engine to go
				with it, so the next call tries the new source again instead of being handed the
				refusal of the previous one.
			*/
			if ($engine === null)
			{
				return null;
			}

			$this->preparedFor = $key;
			$this->prepared = $engine;

			/*
				Building the engine is the one moment of the pass that reaches the folders of the
				new source, so the map read before it is dropped rather than trusted. It costs one
				read of the folders of the mailbox for the whole operation: the engine is built
				once, and every page after this one asks the map that was read with it.
			*/
			$this->roles = null;
		}

		return $this->prepared;
	}

	protected function createPreparedGenerationEngine(Context $context, array $connection): ?Helper\Mailbox\Imap
	{
		$engine = Helper\Mailbox::createGenerationInstance($context->mailboxId, $context, $connection);

		return $engine instanceof Helper\Mailbox\Imap ? $engine : null;
	}
}
