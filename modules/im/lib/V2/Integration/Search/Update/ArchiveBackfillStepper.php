<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Search\IndexService;
use Bitrix\Im\V2\Integration\Search\SearchAdapter;
use Bitrix\Im\V2\Integration\Search\WalkPortion;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Walks the archive of messages into the search index: a keyset walk of b_im_message by descending
 * ID, a portion per step, the cursor in the state of the stepper.
 *
 * The walk takes an optional lower bound and then covers only the tail of the archive - the shape of
 * the catch-up runs after the pipeline flag was switched off or after an incident of the cluster
 * (ALG-06). The bound is passed as an argument of the agent: execAgent() takes arbitrary arguments
 * and reproduces them in the name of the next run on its own.
 *
 * The agent is registered by hand through AgentRegistrar and by nothing else: neither the installer
 * nor an updater brings it.
 */
final class ArchiveBackfillStepper extends Stepper
{
	/** The walk covers the whole archive and may report it finished. */
	public const MODE_FULL = 'full';

	/** The walk covers a tail of the archive: finishing it says nothing about the readiness of the portal. */
	public const MODE_CATCH_UP = 'catchUp';

	/**
	 * Completion mark of a full walk: the date and the number of records processed.
	 *
	 * An option of im and not the state of the stepper: the core deletes the state as soon as the walk
	 * is over, and an empty state is indistinguishable from 'the walk was never started'.
	 */
	public const COMPLETED_OPTION = 'message_search_backfill_completed';

	private const PERIOD_OPTION = 'message_search_backfill_period';
	private const PORTION_OPTION = 'message_search_backfill_portion';
	private const DEFAULT_PERIOD = 60;
	private const DEFAULT_PORTION = 500;
	private const LOG_TAG = 'message-search-backfill';

	/**
	 * Overlap of a catch-up whose bound was computed and not recorded: the index is written down after
	 * the message, so the newest entry it holds is not necessarily the newest message it covers.
	 */
	private const BACKFILL_OVERLAP = 1000;

	protected static $moduleId = 'im';

	public static function getTitle()
	{
		return Loc::getMessage('IM_SEARCH_BACKFILL_STEPPER_TITLE');
	}

	public static function getPeriod(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PERIOD_OPTION, self::DEFAULT_PERIOD));
	}

	/**
	 * @inheritdoc
	 */
	public function execute(array &$option)
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			// the walk stands still but does not end: ending it would make the core delete the state,
			// and the cursor of the walk lives in that state - a rollback of the flag would then cost
			// the whole archive walked so far
			return self::CONTINUE_EXECUTION;
		}

		if (!isset($option['cursor']))
		{
			$this->startWalk($option);
		}

		$messages = $this->readPortion((int)$option['cursor'], (int)$option['lowerBound']);
		if ($messages->count() === 0)
		{
			$this->finish($option);

			return self::FINISH_EXECUTION;
		}

		$walked = $this->indexPortion($messages);
		if ($walked['indexed'] === 0)
		{
			// the index refused the message the portion starts at: the cursor stays where it stood and
			// the very same portion is walked again by the next step
			return self::CONTINUE_EXECUTION;
		}

		$option['cursor'] = $walked['cursor'];
		$option['processed'] = (int)($option['processed'] ?? 0) + $walked['indexed'];
		$option['steps'] = $option['processed'];

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Lower bound of the walk as the operator recorded it, taken from the argument of the agent.
	 *
	 * @return int|null Null when no bound was passed at all: that is the full walk of the archive.
	 */
	public function getRecordedBoundary(): ?int
	{
		$boundary = (int)($this->getOuterParams()[0] ?? 0);

		return $boundary > 0 ? $boundary : null;
	}

	/**
	 * Message the walk stops at, given a date: the oldest message written at that moment or later.
	 *
	 * A date past the last message of the archive leaves nothing to catch up, and the answer says so -
	 * a bound above the whole archive, not a walk of everything.
	 */
	public static function resolveBoundaryOfDate(DateTime $date): int
	{
		$row = MessageTable::query()
			->setSelect(['ID'])
			->where('DATE_CREATE', '>=', $date)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		if (is_array($row))
		{
			return (int)$row['ID'];
		}

		return self::getMaxMessageId() + 1;
	}

	/**
	 * Lower bound of the walk (ALG-06).
	 *
	 * An empty index of the module is the one case where the recorded bound is thrown away: the general
	 * index of the portal was rebuilt while the pipeline was off, so a catch-up 'for the window of the
	 * downtime' would leave the whole history before that window out. The completion mark goes off with
	 * it - it described the index that has been erased.
	 *
	 * Without a recorded moment the last entry of im is the bound, with an overlap on top: writing the
	 * entries it covers twice is cheaper than the risk of a gap.
	 */
	private function resolveLowerBound(?int $recordedBoundary): int
	{
		$lastIndexed = ServiceLocator::getInstance()->get(SearchAdapter::class)->getLastIndexedMessageId();

		if ($lastIndexed === null)
		{
			// unreadable is not empty: the mark of a previous walk stays, and so does the recorded bound
			$this->getLogger()->log('backfill: the index cannot be read, the walk goes by the recorded boundary alone');

			return max(0, $recordedBoundary ?? 0);
		}

		if ($lastIndexed === 0)
		{
			$this->getLogger()->log('backfill: index is empty, recorded boundary ignored, full pass');
			Option::delete(self::$moduleId, ['name' => self::COMPLETED_OPTION]);

			return 0;
		}

		if ($recordedBoundary !== null)
		{
			return $recordedBoundary;
		}

		// the tail is caught up only over an archive some walk has already been through: on a portal
		// where the pipeline has just been switched on, a single message of the online pipeline is
		// enough to make the index non-empty, and a bound computed from it would leave the history
		// before it out for good - the walk would finish as a catch-up and never mark the portal ready
		if (!self::hasCompletedFullWalk())
		{
			$this->getLogger()->log('backfill: no full walk has ever finished, the first one covers the whole archive');

			return 0;
		}

		return max(0, $lastIndexed - self::BACKFILL_OVERLAP);
	}

	/**
	 * @return bool Whether a full walk of the archive has ever reached its end on this portal.
	 */
	private static function hasCompletedFullWalk(): bool
	{
		return Option::get(self::$moduleId, self::COMPLETED_OPTION, '') !== '';
	}

	/**
	 * Resolves the bound once, at the start of the walk, and keeps it in the state: the mode of the
	 * walk follows from it and may not change halfway.
	 */
	private function startWalk(array &$option): void
	{
		$lowerBound = $this->resolveLowerBound($this->getRecordedBoundary());

		$option['lowerBound'] = $lowerBound;
		$option['mode'] = $lowerBound === 0 ? self::MODE_FULL : self::MODE_CATCH_UP;
		$option['cursor'] = self::getMaxMessageId() + 1;
		$option['processed'] = 0;
		$option['count'] = $this->countMessagesToWalk($lowerBound, (int)$option['cursor']);
	}

	/**
	 * A full walk that reached the end of the archive is what the checklist of the enabling reads as
	 * 'the portal is ready'; a catch-up of the tail is not, and leaves the mark alone.
	 */
	private function finish(array &$option): void
	{
		if (($option['mode'] ?? self::MODE_FULL) !== self::MODE_FULL)
		{
			return;
		}

		Option::set(self::$moduleId, self::COMPLETED_OPTION, Json::encode([
			'date' => (new DateTime())->format('Y-m-d H:i:s'),
			'processed' => (int)($option['processed'] ?? 0),
		]));
	}

	/**
	 * The chats a record is built on are read once per chat and released with the portion, however the
	 * portion ends: a step may share its process with another walk of the domain, and outside the
	 * portion nothing reports a chat that changed.
	 *
	 * A message the index refuses ends the portion where it stands. Walking past it would leave the
	 * archive with a hole nothing comes back for: the walk marks itself complete once, and the retry of
	 * the write path knows message identifiers alone, not the record that was never created.
	 *
	 * @return array{cursor: int, indexed: int} Id of the last message written, and how many of them the
	 *                                          portion got through.
	 */
	private function indexPortion(MessageCollection $messages): array
	{
		$indexService = ServiceLocator::getInstance()->get(IndexService::class);
		$portion = new WalkPortion();
		$lastId = 0;
		$indexed = 0;

		try
		{
			$portion->addMessages($messages);
			$portion->addChatsOf($messages);

			foreach ($messages as $message)
			{
				$messageId = $message->getMessageId() ?? 0;
				if ($messageId <= 0)
				{
					continue;
				}

				$chat = Chat::getInstance($message->getChatId());
				$portion->addChat($chat);

				if (!$indexService->reindexMessage($message, $chat))
				{
					break;
				}

				$lastId = $messageId;
				$indexed++;
			}
		}
		finally
		{
			$portion->release();
		}

		return ['cursor' => $lastId, 'indexed' => $indexed];
	}

	/**
	 * The portion as a collection and not as rows: its parameters are then filled by a single query
	 * for the whole portion, while a message built on its own loads them one by one - and both the
	 * hygiene registry and the builder of the entry ask for them.
	 */
	private function readPortion(int $cursor, int $lowerBound): MessageCollection
	{
		$rows = MessageTable::query()
			->setSelect(['*'])
			->where('ID', '<', $cursor)
			->where('ID', '>=', $lowerBound)
			->setOrder(['ID' => 'DESC'])
			->setLimit($this->getPortion())
			->fetchAll()
		;

		$messages = new MessageCollection($rows);
		$messages->fillParams();

		return $messages;
	}

	/**
	 * Counted once, at the start of the walk: the indicator of the admin panel needs a whole, and a
	 * count over the archive on every step would cost more than the step itself.
	 */
	private function countMessagesToWalk(int $lowerBound, int $cursor): int
	{
		return MessageTable::getCount(['>=ID' => $lowerBound, '<ID' => $cursor]);
	}

	private static function getMaxMessageId(): int
	{
		$row = MessageTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)$row['ID'] : 0;
	}

	private function getPortion(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PORTION_OPTION, self::DEFAULT_PORTION));
	}

	private function getLogger(): Logger
	{
		return new Logger(self::LOG_TAG);
	}
}
