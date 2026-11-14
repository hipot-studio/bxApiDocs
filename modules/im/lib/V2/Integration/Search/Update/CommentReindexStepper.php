<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Search\IndexService;
use Bitrix\Im\V2\Integration\Search\WalkPortion;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Update\Stepper;

Loc::loadMessages(__FILE__);

/**
 * Rebuilds the entries of the comments an archive collected before they were readdressed: an entry
 * comes back whole, with the parent channel in PARAM1, the key of the post in PARAM2 and the rights
 * inherited from the channel. Amending the rights alone would not do - the addressing of the entry
 * itself is what changes.
 *
 * The walk is two-level, comment chats and the messages inside them, because that is the shape the
 * table of messages is indexed for: a portion inside a thread is a range over its own chat.
 */
final class CommentReindexStepper extends Stepper
{
	private const PERIOD_OPTION = 'message_search_comment_period';
	private const PORTION_OPTION = 'message_search_comment_portion';
	private const CHAT_BUDGET_OPTION = 'message_search_comment_chat_budget';
	private const DEFAULT_PERIOD = 60;
	private const DEFAULT_PORTION = 500;
	/**
	 * Threads one step is allowed to move on to. The archive of a portal is mostly short threads, and a
	 * step that carried one of them and gave the hit back would spend a run of the agent per thread:
	 * with the standard period of a minute the whole walk would move at some seven hundred threads a
	 * day whatever the portion is set to.
	 */
	private const DEFAULT_CHAT_BUDGET = 100;

	protected static $moduleId = 'im';

	public static function getTitle()
	{
		return Loc::getMessage('IM_SEARCH_COMMENT_STEPPER_TITLE');
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
			// and the two cursors of the walk live in that state - a rollback of the flag would then
			// cost every thread rebuilt so far
			return self::CONTINUE_EXECUTION;
		}

		if (!isset($option['chatCursor']))
		{
			$this->startWalk($option);
		}

		$messageBudget = $this->getPortion();
		$chatBudget = $this->getChatBudget();
		$indexed = 0;
		$openedChats = 0;

		while (true)
		{
			if ((int)$option['chatId'] <= 0)
			{
				if ($openedChats >= $chatBudget)
				{
					return self::CONTINUE_EXECUTION;
				}

				$chatId = $this->readNextChat((int)$option['chatCursor']);
				if ($chatId === 0)
				{
					return self::FINISH_EXECUTION;
				}

				$option['chatId'] = $chatId;
				$option['messageCursor'] = 0;
				$openedChats++;
			}

			$chatId = (int)$option['chatId'];
			$messages = $this->readPortion($chatId, (int)$option['messageCursor']);

			if ($messages->count() === 0)
			{
				$option['chatCursor'] = $chatId;
				$option['chatId'] = 0;
				$option['messageCursor'] = 0;
				$option['steps'] = (int)($option['steps'] ?? 0) + 1;

				continue;
			}

			$walked = $this->reindexPortion($messages, $chatId);
			if ($walked['indexed'] === 0)
			{
				// the index refused the comment the portion starts at: the cursor of the thread stays where
				// it stood and the very same portion is walked again by the next step
				return self::CONTINUE_EXECUTION;
			}

			$option['messageCursor'] = $walked['cursor'];
			$option['processed'] = (int)($option['processed'] ?? 0) + $walked['indexed'];
			$indexed += $walked['indexed'];

			if ($indexed >= $messageBudget)
			{
				return self::CONTINUE_EXECUTION;
			}
		}
	}

	/**
	 * The indicator counts threads and not comments: a count over the comments of the portal costs a
	 * walk of the message table, and the volume of the operation is measured before it is raised, by
	 * an SQL counter of the operator, not by the first step of the walk.
	 */
	private function startWalk(array &$option): void
	{
		$option['chatCursor'] = 0;
		$option['chatId'] = 0;
		$option['messageCursor'] = 0;
		$option['processed'] = 0;
		$option['steps'] = 0;
		$option['count'] = ChatTable::getCount(['=TYPE' => Chat::IM_TYPE_COMMENT]);
	}

	/**
	 * The rights of a comment are those of the parent channel, read once per thread and released with
	 * the portion, however the portion ends: a step may share its process with another walk of the
	 * domain, and outside the portion nothing reports a channel that changed. The thread is handed to
	 * the portion, which releases the parent channel along with it.
	 *
	 * A comment the index refuses ends the portion where it stands: the walk visits every thread once,
	 * so a cursor carried past a refusal leaves that comment out of the index for good.
	 *
	 * @return array{cursor: int, indexed: int} Id of the last comment written, and how many of them the
	 *                                          portion got through.
	 */
	private function reindexPortion(MessageCollection $messages, int $chatId): array
	{
		$indexService = ServiceLocator::getInstance()->get(IndexService::class);
		$portion = new WalkPortion();
		$lastId = 0;
		$indexed = 0;

		try
		{
			$chat = Chat::getInstance($chatId);
			$portion->addChat($chat);
			$portion->addMessages($messages);

			foreach ($messages as $message)
			{
				$messageId = $message->getMessageId() ?? 0;
				if ($messageId <= 0)
				{
					continue;
				}

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

	private function readNextChat(int $cursor): int
	{
		$row = ChatTable::query()
			->setSelect(['ID'])
			->where('TYPE', Chat::IM_TYPE_COMMENT)
			->where('ID', '>', $cursor)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)$row['ID'] : 0;
	}

	/**
	 * The portion as a collection and not as rows: its parameters are then filled by a single query
	 * for the whole portion, while a message built on its own loads them one by one - and both the
	 * hygiene registry and the builder of the entry ask for them.
	 */
	private function readPortion(int $chatId, int $cursor): MessageCollection
	{
		$rows = MessageTable::query()
			->setSelect(['*'])
			->where('CHAT_ID', $chatId)
			->where('ID', '>', $cursor)
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->getPortion())
			->fetchAll()
		;

		$messages = new MessageCollection($rows);
		$messages->fillParams();

		return $messages;
	}

	private function getPortion(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PORTION_OPTION, self::DEFAULT_PORTION));
	}

	private function getChatBudget(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::CHAT_BUDGET_OPTION, self::DEFAULT_CHAT_BUDGET));
	}
}
