<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Integration\Search\ContentBuilder;
use Bitrix\Im\V2\Integration\Search\Access\OpenCodeReachResolver;
use Bitrix\Im\V2\Integration\Search\DeletionAddress;
use Bitrix\Im\V2\Integration\Search\IndexService;
use Bitrix\Im\V2\Integration\Search\PermissionBuilder;
use Bitrix\Im\V2\Integration\Search\SearchAdapter;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * The resumable walk of the domain: everything the write path may not finish inside a hit stands in
 * one queue and is carried at the pace of a single agent.
 *
 * Rebaking the named access codes of a chat that turned from open into private is what the walk was
 * built for: one permission command per member, and behind each of them the engine walks every entry
 * of the chat through the shared queue of the search module.
 *
 * The queue is an option and not the state of the stepper: the core deletes the state as soon as the
 * walk is over, while a conversion puts a chat in the queue from the request path, at a moment when
 * no agent may exist at all.
 *
 * Seven kinds of element stand in it, and each is addressed by what it means to change:
 *
 * - a chat under the key of its identifier, carrying both a cursor over the whole membership (set by
 *   a conversion, null when no such walk stands) and the members awaiting a boundary of their own.
 *   Both hand out the same command with the same boundary, so one element covers them and the walk
 *   never goes backwards for a member it passed;
 * - an address of the index a deletion could not empty within the budget of one job, under a key of
 *   its own;
 * - a list of message identifiers the write path could not take off, under a key built from the list
 *   itself. An identifier is reachable by no address of the queue, so a refused deletion of separate
 *   messages has nowhere else to wait;
 * - a baked code the write path could not take off a chat, under a key of its own, carrying the
 *   handing back that belonged to the same command;
 * - a baked code the write path or a walk could not hand out, under a key of its own. The core
 *   answers nothing from its permission calls either way, so a refused command is known at the call
 *   alone, and a code that never reached the entries of a chat is an archive its members cannot find;
 * - a message whose entry the write path could not write, under a key of its own. The online road
 *   reaches the index inside a background job with nothing behind it, and the archive walk passes the
 *   message by for good once its cursor is below it, so a refused write has nowhere else to wait;
 * - the descendants of a chat whose conversion moved the reach of the shared code under it, under a
 *   key of its own, carrying a cursor over the children and the level they stand at. An open chat is
 *   answered by the parent it needs membership in, so a parent that closed leaves the shared code on
 *   the entries of its open children - the whole portal reading a chat only the project may see. The
 *   very same element carries the names of the members the chat has lost or taken back, and the walk
 *   is then theirs alone: a chat needing membership in its parent is answered by that parent, and the
 *   entries of the whole subtree go on describing a membership that has moved.
 *

 * They share the step, the named lock and the guard of the queue.
 *
 * Beside the queue stand the parked changes: what the named lock was never taken for waits in a row
 * of its own until a step brings it back in. A change dropped instead would take the taking off of a
 * personal code with it, and nothing would be left to say that the code has to come off.
 */
class ChatConversionPermissionStepper extends Stepper
{
	/**
	 * The queue stands apart from the options of im: it is written by every step of the walk and from
	 * the request path alike, while a write clears the cached options of the whole module it belongs
	 * to - and the options of im are read on nearly every hit of the portal.
	 */
	public const QUEUE_MODULE_ID = 'im.message_search';

	public const QUEUE_OPTION = 'message_search_conversion_queue';

	private const PERIOD_OPTION = 'message_search_conversion_period';
	private const PORTION_OPTION = 'message_search_conversion_portion';
	private const DEFAULT_PERIOD = 120;
	private const DEFAULT_PORTION = 50;
	/** Attempts allowed to any element that retries a refused command, the name of the option aside. */
	private const REVOCATION_ATTEMPTS_OPTION = 'message_search_revocation_attempts';
	private const DEFAULT_REVOCATION_ATTEMPTS = 10;
	private const REVOCATION_KEY_PREFIX = 'revoke';
	private const GRANT_KEY_PREFIX = 'grant';
	private const ENTRIES_KEY_PREFIX = 'entries';
	private const DOCUMENTS_KEY_PREFIX = 'documents';
	private const REINDEX_KEY_PREFIX = 'reindex';
	private const DESCENDANTS_KEY_PREFIX = 'descendants';
	private const QUEUE_LOCK = 'im_message_search_conversion_queue';
	private const QUEUE_LOCK_TIMEOUT = 10;
	private const QUEUE_LOCK_ATTEMPTS = 3;
	/**
	 * Rows a change that never got the lock is parked in. A row of its own per change is written with no
	 * lock at all - options are separate rows - and the step brings them back into the queue. Public
	 * because an operator looking for work that has not landed yet finds it by this prefix alone.
	 */
	public const PARKED_OPTION_PREFIX = 'message_search_conversion_parked_';
	/**
	 * Parked rows one step brings back into the queue. The name deliberately carries no parked prefix:
	 * a numeric setting standing among the parked rows themselves would be read as one of them.
	 */
	private const PARKED_PORTION_OPTION = 'message_search_parked_absorb_portion';
	private const DEFAULT_PARKED_PORTION = 20;
	private const DELETION_QUEUE_PORTION_OPTION = 'message_search_deletion_queue_portion';
	private const DEFAULT_DELETION_QUEUE_PORTION = 50;
	private const LOG_TAG = 'message-search-conversion';

	protected static $moduleId = 'im';

	public static function getTitle()
	{
		return Loc::getMessage('IM_SEARCH_CONVERSION_STEPPER_TITLE');
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
			// the walk stands still but does not end: ending it would make the core take the agent off,
			// and the queue outlives the state of the stepper - what stands in it would then wait for a
			// call by hand instead of for the flag to come back
			return self::CONTINUE_EXECUTION;
		}

		// before the queue is read and not after: a parked change is work of its own, and a walk that
		// ended on an empty queue takes its agent with it
		$queue = self::absorbParkedChanges();
		if ($queue === [])
		{
			return $this->finishOrContinue();
		}

		$key = array_key_first($queue);
		$element = $queue[$key];

		$queue = match (true)
		{
			isset($element['delete']) => $this->takeEntriesOff($queue, $option),
			isset($element['entries']) => $this->takeMessagesOff((string)$key, $element['entries'], $option),
			isset($element['documents']) => $this->takeDocumentsOff((string)$key, $element['documents'], $option),
			isset($element['revoke']) => $this->retryRevocation((string)$key, $element['revoke'], $option),
			isset($element['grant']) => $this->retryGrant((string)$key, $element['grant'], $option),
			isset($element['reindex']) => $this->retryReindex((string)$key, $element['reindex'], $option),
			isset($element['descendants']) => $this->syncDescendants((string)$key, $element['descendants'], $option),
			default => $this->rebakeChat((int)$key, $element, $option),
		};

		return $queue === [] ? $this->finishOrContinue() : self::CONTINUE_EXECUTION;
	}

	/**
	 * Ends the walk only when nothing stands behind it, and takes the agent off itself while it holds
	 * the lock of the queue.
	 *
	 * The core deletes the agent of a step that answered FINISH_EXECUTION, and it does so after the
	 * step is over. Work queued in that window used to find the agent of the walk still standing, so
	 * the registration it asked for did nothing and the core then deleted the very agent it had been
	 * counted on: the work waited for an unrelated registration. Taking the agent off under the same
	 * lock the queue is written and registered under leaves a change queued meanwhile only two ways
	 * out, and both of them end with an executor - the read below sees it, or the registration that
	 * follows it finds no agent and raises one of its own.
	 *
	 * A lock that was never taken keeps the agent: a walk that cannot read its queue may not declare
	 * it empty.
	 *
	 * The lock alone does not serialise everything either. A change that never got the lock is parked
	 * in a row of its own, and parking takes no lock at all, so a change can land while this step holds
	 * it - and the registration behind that change, which comes after the parking, falls back to
	 * running without the lock and reads the agent as still standing. The taking off therefore comes
	 * first and the parked rows are read once more after it: a registration reading the agent after
	 * that raises one of its own, and a parking earlier than the reread is seen right here.
	 */
	private function finishOrContinue(): bool
	{
		$connection = Application::getConnection();

		for ($attempt = 1; $attempt <= self::QUEUE_LOCK_ATTEMPTS; $attempt++)
		{
			if (!$connection->lock(self::QUEUE_LOCK, self::getLockTimeout()))
			{
				continue;
			}

			try
			{
				if (self::getQueue() !== [] || self::hasParkedChanges())
				{
					return self::CONTINUE_EXECUTION;
				}

				$this->takeAgentOff();

				if (self::hasParkedChanges())
				{
					// the executor of that change is this walk: the registration behind it has already
					// read the agent as standing and registered nothing
					AgentRegistrar::raiseQueueWalkUnderHeldLock();

					return self::CONTINUE_EXECUTION;
				}
			}
			finally
			{
				$connection->unlock(self::QUEUE_LOCK);
			}

			return self::FINISH_EXECUTION;
		}

		(new Logger(self::LOG_TAG))->log(
			'the queue lock was not taken on the finish of the walk, its agent stays'
		);

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Protected, and the class is open for the same reason SearchAdapter and IndexService are: a case has
	 * to be able to park a change exactly where a parallel one lands - between the reading of the queue
	 * by the finishing step and the taking off of its agent - and no two processes run inside one case.
	 */
	protected function takeAgentOff(): void
	{
		AgentRegistrar::remove(self::class);
	}

	/**
	 * Runs the registration of the agent under the lock of the queue, so that it cannot fall between
	 * the reading of the queue by a finishing step and the taking off of its agent.
	 *
	 * A lock that was never taken runs the work all the same: a registration missed costs an executor,
	 * while a registration made in vain costs nothing - a second agent is not raised beside a standing
	 * one. Reading a standing agent without the lock is safe by the order the finishing step keeps: it
	 * takes its agent off before it reads the parked rows for the last time, so a registration that
	 * still sees the agent is a registration whose own change that reread catches.
	 */
	public static function registerUnderQueueLock(\Closure $work): void
	{
		$connection = Application::getConnection();

		for ($attempt = 1; $attempt <= self::QUEUE_LOCK_ATTEMPTS; $attempt++)
		{
			if (!$connection->lock(self::QUEUE_LOCK, self::getLockTimeout()))
			{
				continue;
			}

			try
			{
				$work();
			}
			finally
			{
				$connection->unlock(self::QUEUE_LOCK);
			}

			return;
		}

		$work();
	}

	/**
	 * One portion of the named codes a chat is waiting for, spent on both halves of its element.
	 *
	 * The members the element names go first: their code is off from the moment their history was
	 * cleaned. What is left of the portion carries the walk over the whole membership forward only - a
	 * boundary that moved behind the cursor is handed out by name and never by sending the walk back.
	 *
	 * A member who left the chat meanwhile is granted nothing: rights of the index follow the
	 * membership and never the queue. A member the required ancestors of the chat deny is granted
	 * nothing either, and his name comes off the entries instead: a chat hung under a closed project
	 * keeps the relations it had, and this walk is what the reparenting raises to bring them to what
	 * Chat::checkAccess() answers now.
	 *
	 * A member whose command the index refused stays named in the element, the walked ones included:
	 * the cursor moves on regardless, and a name it passed would otherwise never be handed out again.
	 * The element counts those attempts, so an index that keeps refusing does not hold the whole queue
	 * behind its head for good.
	 *
	 * @param array{cursor: int|null, awaiting: int[], attempts?: int} $element
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function rebakeChat(int $chatId, array $element, array &$option): array
	{
		$portion = self::getPortion();
		$cursor = $element['cursor'];
		$handed = array_slice($element['awaiting'], 0, $portion);
		$left = $portion - count($handed);

		$walked = $cursor !== null && $left > 0 ? self::readMembers($chatId, $cursor, $left) : [];
		$isWalkedThrough = $cursor !== null && $left > 0 && $walked === [];

		// the membership is read raw and split here: the walk is the only road that comes after a tree
		// that moved, so a member the ancestors of the chat deny has to be met by it and taken off
		$members = self::readMembersByIds($chatId, $handed) + $walked;
		$allowed = self::readAllowedMembersByIds($chatId, array_keys($members));
		$named = array_intersect_key($members, $allowed);
		$this->revokeAccess($chatId, array_keys(array_diff_key($members, $allowed)));

		// an element that has already counted an attempt is standing on names the index refused, so their
		// codes may be in the SQL and unknown to the engine; the members the walk reaches now are first
		// attempts and the core tells the engine of them itself
		$retried = ($element['attempts'] ?? 0) > 0 ? $handed : [];
		$refused = $this->grantAccess($chatId, $named, $retried);
		$attempts = $refused === [] ? 0 : ($element['attempts'] ?? 0) + 1;

		if ($refused !== [] && $attempts >= self::getRetryAttempts())
		{
			(new Logger(self::LOG_TAG))->log(
				'the codes of ' . count($refused) . " members of the chat {$chatId} could not be handed out"
				. " in {$attempts} attempts, those members are left without them"
			);
			$refused = [];
			$attempts = 0;
		}

		$nextCursor = $cursor;
		if ($isWalkedThrough)
		{
			$nextCursor = null;
		}
		elseif ($walked !== [])
		{
			$nextCursor = (int)array_key_last($walked);
		}

		$queue = self::settleChat(
			$chatId,
			array_values(array_diff($handed, $refused)),
			$cursor,
			$nextCursor,
			$refused,
			$attempts,
		);
		$settled = $queue[$chatId] ?? ['cursor' => null, 'awaiting' => []];

		$option['steps'] = (int)($option['steps'] ?? 0) + count($handed) + count($walked);
		$option['count'] = $option['steps'] + count($settled['awaiting'])
			+ self::trackMembersLeft($option, $chatId, $settled['cursor'], count($walked))
		;

		return $queue;
	}

	/**
	 * Members the walk of a chat still has ahead of it: counted once when the chat comes to the head of
	 * the queue and carried down by every portion after it.
	 *
	 * Counting it anew after each portion asks the same rows over and over - a chat of a hundred
	 * thousand members walked by fifty spends about a hundred million rows on the counter of the
	 * progress alone. The number is an estimate either way: a membership that changes under a walk
	 * moves it, and the walk itself ends by an empty portion and never by this count.
	 *
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @param int $walked Members the portion just walked through.
	 */
	private static function trackMembersLeft(array &$option, int $chatId, ?int $cursor, int $walked): int
	{
		if ($cursor === null)
		{
			unset($option['membersLeft']);

			return 0;
		}

		$tracked = $option['membersLeft'] ?? null;
		$left = is_array($tracked) && ($tracked['chatId'] ?? null) === $chatId
			? max(0, (int)$tracked['left'] - $walked)
			: self::countMembersLeft($chatId, $cursor)
		;

		$option['membersLeft'] = ['chatId' => $chatId, 'left' => $left];

		return $left;
	}

	/**
	 * One unit of work of a deletion a job could not finish: the same portioned walk under the same
	 * budget, carried by the agent instead of the hit.
	 *
	 * An address the step walked through leaves the queue, and so does one the option cannot be read as
	 * an address at all: an element the step cannot act on would stand at its head for good.
	 *
	 * The step carries as many addresses as its allowance and its portion let it, and settles them with
	 * a single change of the queue. Every change rewrites the whole queue, while a mass deletion stands
	 * in it as thousands of addresses holding a handful of entries each: taking one address per change
	 * would spend the walk on encoding the queue and not on emptying the index.
	 *
	 * @param array<int|string, array> $queue The queue as the step read it.
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function takeEntriesOff(array $queue, array &$option): array
	{
		$service = ServiceLocator::getInstance()->get(IndexService::class);
		$allowance = IndexService::getDeletionBudget();
		$portion = self::getDeletionQueuePortion();
		$emptied = [];

		foreach ($queue as $key => $element)
		{
			if (!isset($element['delete']) || $allowance <= 0 || count($emptied) >= $portion)
			{
				continue;
			}

			$address = DeletionAddress::fromArray($element['delete']);
			if ($address === null)
			{
				$emptied[] = (string)$key;

				continue;
			}

			$spent = $service->takeAddressOffWithin($address, $allowance);
			if ($spent === null || $spent >= $allowance)
			{
				// a refusal of the index would meet the rest of the addresses alike, and an address that
				// answered full portions all the way holds more: both keep their place in the queue and
				// the step ends on them
				break;
			}

			$emptied[] = (string)$key;
			// an address that answered nothing still cost a read of the index
			$allowance -= max(1, $spent);
		}

		$queue = $emptied === [] ? self::getQueue() : self::dropElements($emptied);

		$option['steps'] = (int)($option['steps'] ?? 0) + max(1, count($emptied));
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * One unit of work of a deletion the write path could not carry out: the identifiers are known and
	 * nothing is walked, so the step is the single command the hit could not get through.
	 *
	 * @param array{messageIds: int[], attempts: int} $entries
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function takeMessagesOff(string $key, array $entries, array &$option): array
	{
		$messageIds = $entries['messageIds'];
		$attempts = $entries['attempts'] + 1;

		$isTakenOff = ServiceLocator::getInstance()->get(SearchAdapter::class)->deleteByMessageIds($messageIds);
		$isGivenUp = !$isTakenOff && $attempts >= self::getRetryAttempts();
		if ($isGivenUp)
		{
			(new Logger(self::LOG_TAG))->log(
				'the index refused a deletion of ' . count($messageIds)
				. " entries in {$attempts} attempts, they are left in it"
			);
		}

		$queue = $isTakenOff || $isGivenUp ? self::dropElement($key) : self::countAttempt($key, 'entries', $attempts);

		$option['steps'] = (int)($option['steps'] ?? 0) + 1;
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * One unit of work of a deletion the engine refused after the core had already taken the rows of the
	 * entries off. Nothing addresses those documents any more - neither an address of the index, which
	 * reads the rows, nor the hygiene walk, which reads them too - so the identifiers themselves stand
	 * in the queue and the command goes straight to the engine.
	 *
	 * @param array{documentIds: int[], attempts: int} $documents
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function takeDocumentsOff(string $key, array $documents, array &$option): array
	{
		$documentIds = $documents['documentIds'];
		$attempts = $documents['attempts'] + 1;

		$isTakenOff = ServiceLocator::getInstance()->get(SearchAdapter::class)->deleteDocuments($documentIds);
		$isGivenUp = !$isTakenOff && $attempts >= self::getRetryAttempts();
		if ($isGivenUp)
		{
			(new Logger(self::LOG_TAG))->log(
				'the engine refused a deletion of ' . count($documentIds)
				. " documents in {$attempts} attempts, they are left in it"
			);
		}

		$queue = $isTakenOff || $isGivenUp
			? self::dropElement($key)
			: self::countAttempt($key, 'documents', $attempts)
		;

		$option['steps'] = (int)($option['steps'] ?? 0) + 1;
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * One portion of the children of a chat whose conversion moved the reach of the shared code under
	 * it. The reach of an open chat is answered by the parent it needs membership in, so a converted
	 * chat answers not only for its own entries: a project that closed leaves the shared code on the
	 * entries of its open children, and the general search surface of the portal, which runs no
	 * re-check of its own, would answer with their texts.
	 *
	 * The names of the members move with the very same chain, and for them the openness of a child says
	 * nothing: a private chat of a project that closed keeps the names of the members the project no
	 * longer holds. Every child that needs membership in its parent is therefore queued for a rebake of
	 * its names, and the shared code is brought over the open ones besides.
	 *
	 * The walk goes only through the children the chain runs through - the ones needing membership in
	 * their parent. A child of any other kind answers for itself alone, and so does everything below
	 * it: the chain of the resolver stops on such a chat either way.
	 *
	 * Its depth is the depth of that chain and not of the tree: a chat standing deeper than the
	 * resolver walks is answered as unreachable whatever its ancestors do, so nothing there moves with
	 * the conversion.
	 *
	 * A walk carrying the names of members is theirs alone: the openness of a child says nothing about
	 * the names baked into its entries, so every child the chain runs through is queued for the one
	 * answer of the domain - the rebake reads the chain anew and either takes a name off the entries or
	 * hands it back, should the chat hold him again by then. The names go to the child in one command:
	 * the queue is rewritten whole under its lock, and a project hands over its members by thousands.
	 *
	 * @param array{chatId: int, cursor: int, depth: int, userIds?: int[]} $descendants
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function syncDescendants(string $key, array $descendants, array &$option): array
	{
		$chatId = (int)$descendants['chatId'];
		$cursor = (int)$descendants['cursor'];
		$depth = (int)$descendants['depth'];
		$userIds = $descendants['userIds'] ?? [];

		$children = self::readChildren($chatId, $cursor, self::getPortion());
		if ($children === [])
		{
			$queue = self::dropElement($key);

			$option['steps'] = (int)($option['steps'] ?? 0) + 1;
			$option['count'] = $option['steps'] + count($queue);

			return $queue;
		}

		$chained = [];

		foreach ($children as $child)
		{
			if (!$child['requiresParentMembership'])
			{
				continue;
			}

			if ($userIds !== [])
			{
				self::enqueueMembers($child['id'], $userIds);
			}
			elseif ($child['isOpen'])
			{
				ServiceLocator::getInstance()->get(OpenCodeSynchronizer::class)->sync($child['id']);
			}
			else
			{
				self::enqueue($child['id']);
			}

			$chained[] = $child['id'];
		}

		if ($depth < ChatAncestorNavigator::DEFAULT_DEPTH)
		{
			foreach (self::keepParents($chained) as $parentId)
			{
				self::queueDescendants($parentId, $depth + 1, $userIds);
			}
		}

		$queue = self::advanceDescendants($key, $cursor, (int)end($children)['id']);

		$option['steps'] = (int)($option['steps'] ?? 0) + count($children);
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * One unit of work of a write the online road could not carry out. The message is read anew and put
	 * through the very same rule as the write path: the element was queued before the moment the walk
	 * reaches it, and a message deleted or turned non-indexable meanwhile may not be written back.
	 *
	 * A message that is gone leaves the queue without a command: its entry, if one was ever written, is
	 * addressed by the deletion road and never by this one.
	 *
	 * @param array{messageId: int, attempts: int} $reindex
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function retryReindex(string $key, array $reindex, array &$option): array
	{
		$messageId = (int)$reindex['messageId'];
		$attempts = $reindex['attempts'] + 1;

		$message = new Message($messageId);
		$chatId = $message->getChatId() ?? 0;
		$isWritten = $chatId > 0
			&& ServiceLocator::getInstance()
				->get(IndexService::class)
				->reindexMessage($message, Chat::getInstance($chatId))
		;

		$isGone = $chatId <= 0;
		$isGivenUp = !$isWritten && !$isGone && $attempts >= self::getRetryAttempts();
		if ($isGivenUp)
		{
			(new Logger(self::LOG_TAG))->log(
				"the index refused the entry of the message {$messageId} in {$attempts} attempts,"
				. ' the message stays unfindable'
			);
		}

		$queue = $isWritten || $isGone || $isGivenUp
			? self::dropElement($key)
			: self::countAttempt($key, 'reindex', $attempts)
		;

		$option['steps'] = (int)($option['steps'] ?? 0) + 1;
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * One unit of work of a handing out the write path or a walk could not carry out. Rights are
	 * additive and the command is idempotent, so a retry costs a repeated walk of the entries of the
	 * chat and nothing else.
	 *
	 * What the element may not carry over is the access behind the code: it was written before the
	 * moment the queue reaches it. A member gone meanwhile had his code taken off by the handler of the
	 * leaving, and a chat turned private had the shared code taken off by the handler of the
	 * conversion - handing either of them back is the archive of the chat opened to a stranger. So the
	 * access is read anew and an element it no longer holds leaves the queue without a command.
	 *
	 * @param array{code: string, chatId: int, boundary: string|null, attempts: int} $grant
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function retryGrant(string $key, array $grant, array &$option): array
	{
		$code = (string)$grant['code'];
		$chatId = (int)$grant['chatId'];
		$attempts = $grant['attempts'] + 1;

		$filter = self::buildGrantFilter($code, $chatId, $grant['boundary']);
		if ($filter === null)
		{
			(new Logger(self::LOG_TAG))->log(
				"the code {$code} is no longer due on the chat {$chatId}, it is dropped without a command"
			);

			$queue = self::dropElement($key);

			$option['steps'] = (int)($option['steps'] ?? 0) + 1;
			$option['count'] = $option['steps'] + count($queue);

			return $queue;
		}

		$adapter = ServiceLocator::getInstance()->get(SearchAdapter::class);
		// the element stands here because a first attempt was refused, so the change may be applied in
		// the SQL already: then the command touches no row, the core tells the engine nothing of its
		// own accord, and the element would leave the queue with the engine still holding the old rights
		$isHandedOut = $adapter->addPermission($code, $filter) && $adapter->resyncPermission($code, $filter);
		$isGivenUp = !$isHandedOut && $attempts >= self::getRetryAttempts();
		if ($isGivenUp)
		{
			(new Logger(self::LOG_TAG))->log(
				"the code {$code} could not be handed out on the chat {$chatId} in {$attempts} attempts,"
				. ' the archive stays out of reach'
			);
		}

		$queue = $isHandedOut || $isGivenUp ? self::dropElement($key) : self::countAttempt($key, 'grant', $attempts);

		$option['steps'] = (int)($option['steps'] ?? 0) + 1;
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * One unit of work of a taking off the write path could not carry out. The handing back the element
	 * carries goes only after the taking off has gone through: the range of a granted code cannot be
	 * narrowed, so a boundary handed out before the old code is off is the old code widened.
	 *
	 * The access is read anew here as it is on the handing out: the element was written before the
	 * moment the queue reaches it, and what it names may have come back meanwhile. The shared code of a
	 * chat that is an open context again is due exactly as it stands, so the element leaves the queue
	 * without a command instead of taking the archive of an open chat away from everyone.
	 *
	 * The handing back is read against the membership and not taken from the element: the taking off
	 * may have waited in the queue while the member left the chat, and the boundary the element carries
	 * would then hand his archive back to someone the chat no longer holds. A member with no relation
	 * left gets the taking off alone.
	 *
	 * An element the index keeps refusing leaves the queue after the allowed number of attempts, loudly:
	 * it stands at the head of the queue, and everything behind it waits while it is retried.
	 *
	 * @param array<string, mixed> $revocation
	 * @param array<string, mixed> $option State of the stepper, carried by the core between steps.
	 * @return array<int|string, array> The queue as it stands after the step.
	 */
	private function retryRevocation(string $key, array $revocation, array &$option): array
	{
		$adapter = ServiceLocator::getInstance()->get(SearchAdapter::class);
		$code = (string)$revocation['code'];
		$chatId = (int)$revocation['chatId'];
		$attempts = (int)$revocation['attempts'] + 1;

		if (self::isRevocationOutdated($code, $chatId))
		{
			(new Logger(self::LOG_TAG))->log(
				"the code {$code} is due on the chat {$chatId} again, the taking off is dropped without a command"
			);

			$queue = self::dropElement($key);

			$option['steps'] = (int)($option['steps'] ?? 0) + 1;
			$option['count'] = $option['steps'] + count($queue);

			return $queue;
		}

		// the taking off is repeated here, so the delivery to the engine goes with it and not by the
		// grace of the SQL: a repeat of a change already applied touches no row, and the core keeps
		// silent exactly then
		$filter = ['=PARAM1' => $chatId];
		$isTakenOff = $adapter->deletePermission($code, $filter) && $adapter->resyncPermission($code, $filter);
		if ($isTakenOff)
		{
			$boundary = self::resolveHandBack($code, $chatId, $revocation['boundary']);
			if ($boundary !== null && !$adapter->addPermission($code, ['=PARAM1' => $chatId, '>=PARAM2' => $boundary]))
			{
				// the code is already off: a refused handing back leaves the member without his remaining
				// history, and this element is about to leave the queue
				self::enqueueGrant($code, $chatId, $boundary);
			}
		}

		$isGivenUp = !$isTakenOff && $attempts >= self::getRetryAttempts();
		if ($isGivenUp)
		{
			(new Logger(self::LOG_TAG))->log(
				"the code {$code} could not be taken off the chat {$chatId} in {$attempts} attempts, it is left on it"
			);
		}

		$queue = $isTakenOff || $isGivenUp
			? self::dropElement($key)
			: self::countAttempt($key, 'revoke', $attempts)
		;

		$option['steps'] = (int)($option['steps'] ?? 0) + 1;
		$option['count'] = $option['steps'] + count($queue);

		return $queue;
	}

	/**
	 * Filter of a queued handing out, built on the access as it stands now.
	 *
	 * A code that names a member goes with the boundary of his relation and not with the one the element
	 * was written with: the relation is what the entries of the chat are baked against, and the element
	 * may be older than the last move of the boundary. A member the required ancestors of the chat deny
	 * by then is due nothing at all - the element may have waited in the queue while the chat was hung
	 * elsewhere. The shared code goes only while the chat is still an open context.
	 *
	 * @return array<string, mixed>|null Null when the command is no longer due at all.
	 */
	private static function buildGrantFilter(string $code, int $chatId, ?string $boundary): ?array
	{
		$userId = PermissionBuilder::parseUserCode($code);
		if ($userId !== null)
		{
			$members = self::readAllowedMembersByIds($chatId, [$userId]);
			if (!isset($members[$userId]))
			{
				return null;
			}

			return [
				'=PARAM1' => $chatId,
				'>=PARAM2' => ContentBuilder::buildMessageKey($members[$userId]),
			];
		}

		if ($code === PermissionBuilder::OPEN_ACCESS_CODE && !self::openCodeReach()->isReachedById($chatId))
		{
			return null;
		}

		$filter = ['=PARAM1' => $chatId];
		if ($boundary !== null)
		{
			$filter['>=PARAM2'] = (string)$boundary;
		}

		return $filter;
	}

	/**
	 * Boundary the code comes back with once the taking off has gone through, read the same way.
	 *
	 * A code that names a member is due exactly as his relation says now, whether the element carried a
	 * boundary or not: the element of a leaving may have waited while the member came back, and the one
	 * of a cleaned history while the boundary moved again. Both are the same command, and the relation
	 * answers for both. A member the chat no longer holds gets the taking off alone.
	 *
	 * @return string|null Null when the element carries no handing back at all, and when the member it
	 *                     names is no longer in the chat or is denied by its required ancestors.
	 */
	private static function resolveHandBack(string $code, int $chatId, ?string $boundary): ?string
	{
		$userId = PermissionBuilder::parseUserCode($code);
		if ($userId === null)
		{
			return $boundary === null ? null : (string)$boundary;
		}

		$members = self::readAllowedMembersByIds($chatId, [$userId]);

		return isset($members[$userId]) ? ContentBuilder::buildMessageKey($members[$userId]) : null;
	}

	/**
	 * Whether the access the element stands for has come back before the queue reached it. Only the
	 * shared code is answered here: it belongs to the openness of the chat alone, and a chat open again
	 * carries it by right. A code that names a member is not dropped but read anew - the same element
	 * covers a member who came back and a boundary that moved, and both are handed their range.
	 */
	private static function isRevocationOutdated(string $code, int $chatId): bool
	{
		return $code === PermissionBuilder::OPEN_ACCESS_CODE
			&& self::openCodeReach()->isReachedById($chatId)
		;
	}

	/**
	 * The one reader of the openness in the queue: the shared code belongs to a chat the whole portal
	 * reaches, and a chat answered by a parent it needs membership in is not one, whatever its own type
	 * says.
	 */
	private static function openCodeReach(): OpenCodeReachResolver
	{
		return ServiceLocator::getInstance()->get(OpenCodeReachResolver::class);
	}

	/**
	 * Writes down an attempt of an element that retries a refused command. The count is left alone when
	 * it already stands at the number reached, so a step of the queue writes only what it changed.
	 *
	 * @param string $kind Key the element is carried under: revoke, grant or entries.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function countAttempt(string $key, string $kind, int $attempts): array
	{
		return self::mutateQueue(static function (array &$queue) use ($key, $kind, $attempts): bool {
			if (!isset($queue[$key][$kind]) || ($queue[$key][$kind]['attempts'] ?? null) === $attempts)
			{
				return false;
			}

			$queue[$key][$kind]['attempts'] = $attempts;

			return true;
		});
	}

	private static function getRetryAttempts(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::REVOCATION_ATTEMPTS_OPTION, self::DEFAULT_REVOCATION_ATTEMPTS));
	}

	private static function buildRevocationKey(string $code, int $chatId): string
	{
		return self::REVOCATION_KEY_PREFIX . ':' . $chatId . ':' . $code;
	}

	private static function buildGrantKey(string $code, int $chatId, ?string $boundary): string
	{
		$key = self::GRANT_KEY_PREFIX . ':' . $chatId . ':' . $code;

		return $boundary === null ? $key : $key . ':' . $boundary;
	}

	/**
	 * Identity of a list of identifiers: the list itself. The same deletion refused twice stands once,
	 * and a list that differs by a single message is an element of its own.
	 *
	 * @param int[] $messageIds Sorted and deduplicated by the caller.
	 */
	private static function buildEntriesKey(array $messageIds): string
	{
		return self::ENTRIES_KEY_PREFIX . ':' . md5(implode(',', $messageIds));
	}

	private static function buildDocumentsKey(array $documentIds): string
	{
		return self::DOCUMENTS_KEY_PREFIX . ':' . md5(implode(',', $documentIds));
	}

	private static function buildReindexKey(int $messageId): string
	{
		return self::REINDEX_KEY_PREFIX . ':' . $messageId;
	}

	/**
	 * The walk of the members stands under a key of its own: the membership of a project moves far more
	 * often than a walk over its subtree ends, and one key for both would keep sending the walk of the
	 * conversion back to the position it starts from.
	 *
	 * Every member of one chat shares that key whatever command queued him. The walk asks the same
	 * thing of every child for all the names it carries at once, so a key per member would buy nothing
	 * and cost a step of the agent apiece - a structure sync hands over thousands of members in one go.
	 */
	private static function buildDescendantsKey(int $chatId, bool $ofMembers = false): string
	{
		$key = self::DESCENDANTS_KEY_PREFIX . ':' . $chatId;

		return $ofMembers ? $key . ':members' : $key;
	}

	/**
	 * The names of one walk, joined and never narrowed: a member the walk does not carry keeps whatever
	 * its entries say of him, and for a member let back into a project that is an archive he cannot find.
	 *
	 * @param int[] $stands
	 * @param int[] $added
	 * @return int[]
	 */
	private static function mergeUserIds(array $stands, array $added): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', [...array_values($stands), ...array_values($added)]),
			static fn (int $userId): bool => $userId > 0,
		)));
		sort($userIds);

		return $userIds;
	}

	/**
	 * Reads the queue past the option cache of the process: an option is cached in a static of the
	 * process that loaded it, so a step running for seconds would otherwise compare against its own
	 * copy, taken before the conversion.
	 *
	 * @return array<int|string, array> chatId => {cursor of the walk over its members, the members
	 *                                  awaiting a boundary of their own}, or the key of an address of
	 *                                  the index => the address left to take off
	 */
	public static function getQueue(): array
	{
		$connection = Application::getConnection();
		$name = $connection->getSqlHelper()->forSql(self::QUEUE_OPTION);

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$row = $connection
				->query(
					'SELECT VALUE FROM b_option'
					. " WHERE MODULE_ID = '" . self::QUEUE_MODULE_ID . "' AND NAME = '{$name}'"
				)
				->fetch()
			;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		return self::parseQueue((string)($row['VALUE'] ?? ''));
	}

	/**
	 * A chat already queued gets its cursor reset: its membership has to be walked anew. Members that
	 * waited for a boundary of their own leave with it - the walk hands every member the boundary his
	 * relation carries at the moment it reaches him.
	 *
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueue(int $chatId): array
	{
		return self::mutateQueue(static function (array &$queue) use ($chatId): bool {
			$element = ['cursor' => 0, 'awaiting' => []];
			if (($queue[$chatId] ?? null) === $element)
			{
				return false;
			}

			$queue[$chatId] = $element;

			return true;
		});
	}

	/**
	 * Queues one member of a chat whose history boundary has moved, so that his code comes back with
	 * the new boundary at the pace of the walk and not from the request that moved it.
	 *
	 * A walk still to reach the member needs nothing added. A walk that has gone past him is not sent
	 * back: rerunning it over everyone in between would cost a full pass over the entries of the chat
	 * per member, so the member is named instead and gets a command of his own.
	 *
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueMember(int $chatId, int $userId): array
	{
		return self::mutateQueue(static function (array &$queue) use ($chatId, $userId): bool {
			$element = $queue[$chatId] ?? ['cursor' => null, 'awaiting' => []];

			if ($element['cursor'] !== null && $element['cursor'] < $userId)
			{
				return false;
			}

			if (in_array($userId, $element['awaiting'], true))
			{
				return false;
			}

			$element['awaiting'][] = $userId;
			$queue[$chatId] = $element;

			return true;
		});
	}

	/**
	 * The same for the members of one chat at once: every change of the queue reads, decodes, encodes
	 * and writes the whole of it under the named lock, and a structure sync hands over thousands of
	 * members in one go.
	 *
	 * @param int[] $userIds
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueMembers(int $chatId, array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
		if ($chatId <= 0 || $userIds === [])
		{
			return self::getQueue();
		}

		return self::mutateQueue(static function (array &$queue) use ($chatId, $userIds): bool {
			$element = $queue[$chatId] ?? ['cursor' => null, 'awaiting' => []];
			$named = array_flip($element['awaiting']);
			$isChanged = false;

			foreach ($userIds as $userId)
			{
				if ($element['cursor'] !== null && $element['cursor'] < $userId)
				{
					continue;
				}

				if (isset($named[$userId]))
				{
					continue;
				}

				$named[$userId] = true;
				$element['awaiting'][] = $userId;
				$isChanged = true;
			}

			if ($isChanged)
			{
				$queue[$chatId] = $element;
			}

			return $isChanged;
		});
	}

	/**
	 * Queues what a deletion of the index could not empty within the budget of one job. The address is
	 * the identity of the element, so the same deletion queued twice stands once.
	 *
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueDeletion(DeletionAddress $address): array
	{
		return self::enqueueDeletions([$address]);
	}

	/**
	 * The same, for the addresses of one deletion at once: every change of the queue reads, decodes,
	 * encodes and writes the whole of it under the named lock, and a user deleted along with his chats
	 * hands over thousands of addresses.
	 *
	 * @param DeletionAddress[] $addresses
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueDeletions(array $addresses): array
	{
		if ($addresses === [])
		{
			return self::getQueue();
		}

		return self::mutateQueue(static function (array &$queue) use ($addresses): bool {
			$isChanged = false;

			foreach ($addresses as $address)
			{
				$key = $address->getKey();
				if (array_key_exists($key, $queue))
				{
					continue;
				}

				$queue[$key] = ['delete' => $address->toArray()];
				$isChanged = true;
			}

			return $isChanged;
		});
	}

	/**
	 * Queues the identifiers of a deletion the index refused. They are queued and not left in the buffer
	 * of the service: the buffer lives in the process, and a refusal on the last deletion of a hit has
	 * nothing behind it to carry the rest along - the entries would then stay in the index until the
	 * hygiene walk is run by hand.
	 *
	 * The list is split into portions of the deletion, so one element of the queue is one command.
	 *
	 * @param int[] $messageIds
	 * @param int $portion Size of one command, the very portion the write path flushes by.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueMessageDeletion(array $messageIds, int $portion): array
	{
		$messageIds = array_values(array_unique(array_filter(
			array_map('intval', $messageIds),
			static fn (int $messageId): bool => $messageId > 0,
		)));
		if ($messageIds === [])
		{
			return self::getQueue();
		}

		sort($messageIds);
		$chunks = array_chunk($messageIds, max(1, $portion));

		return self::mutateQueue(static function (array &$queue) use ($chunks): bool {
			$isChanged = false;

			foreach ($chunks as $chunk)
			{
				$key = self::buildEntriesKey($chunk);
				if (array_key_exists($key, $queue))
				{
					continue;
				}

				$queue[$key] = ['entries' => ['messageIds' => $chunk, 'attempts' => 0]];
				$isChanged = true;
			}

			return $isChanged;
		});
	}

	/**
	 * Queues the documents of the engine an entry deletion left behind. CSearch::DeleteIndex() takes the
	 * SQL rows off first and only then tells the engine, so a call the engine threw on leaves documents
	 * no address of the index reaches any more: a repeat of the very same deletion finds no row, walks
	 * nothing and reports success. The identifiers are therefore read before the deletion and queued as
	 * they are.
	 *
	 * @param int[] $documentIds Identifiers of the rows of b_search_content the entries stood in.
	 * @param int $portion Size of one command, the very portion the write path flushes by.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueDocumentDeletion(array $documentIds, int $portion): array
	{
		$documentIds = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn (int $documentId): bool => $documentId > 0,
		)));
		if ($documentIds === [])
		{
			return self::getQueue();
		}

		sort($documentIds);
		$chunks = array_chunk($documentIds, max(1, $portion));

		return self::mutateQueue(static function (array &$queue) use ($chunks): bool {
			$isChanged = false;

			foreach ($chunks as $chunk)
			{
				$key = self::buildDocumentsKey($chunk);
				if (array_key_exists($key, $queue))
				{
					continue;
				}

				$queue[$key] = ['documents' => ['documentIds' => $chunk, 'attempts' => 0]];
				$isChanged = true;
			}

			return $isChanged;
		});
	}

	/**
	 * Queues the walk over the descendants of a chat whose conversion moved the reach of the shared
	 * code under it. The chat itself is answered by the conversion at the request path; its open
	 * children are answered by it too, and nothing in the request may walk them.
	 *
	 * A chat holding no children is not queued at all: nearly every conversion is of a chat standing on
	 * its own, and an element of the queue costs a step of the agent whatever it finds.
	 *
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueDescendants(int $chatId): array
	{
		if ($chatId <= 0 || self::keepParents([$chatId]) === [])
		{
			return self::getQueue();
		}

		return self::queueDescendants($chatId, 1);
	}

	/**
	 * Queues the walk over the descendants of a chat whose membership has moved. Chat::checkAccess()
	 * hands the answer of a chat needing membership in its parent over to that parent, while the module
	 * leaves the relations of the child untouched - the messenger drops such a member on the read path
	 * instead. The entries of every dependent chat below therefore go on naming a member the project no
	 * longer holds, and the general search surface of the portal, which runs no re-check of its own,
	 * would answer with their texts.
	 *
	 * The walk is queued for a member let back in as surely as for one who left, and for the same
	 * reason read the other way round: the entries of the subtree name nobody the project handed back,
	 * the messenger shows him those chats again, and nothing but this walk is due to say so to the
	 * index. Which of the two a name stands for is not asked here - the rebake of every child reads the
	 * chain anew and answers for the moment it reaches him.
	 *
	 * A request may not walk a tree, so the subtree goes to the queue, exactly as it does for a chat
	 * whose conversion moved the reach of the shared code under it.
	 *
	 * @param int[] $userIds
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueMemberDescendants(int $chatId, array $userIds): array
	{
		$userIds = self::mergeUserIds($userIds, []);
		if ($chatId <= 0 || $userIds === [] || self::keepParents([$chatId]) === [])
		{
			return self::getQueue();
		}

		return self::queueDescendants($chatId, 1, $userIds);
	}

	/**
	 * The change itself, for a caller that already knows the chat holds children.
	 *
	 * Of two walks over the same chat the broader level is kept: a walk queues the level below itself
	 * as it goes, so the one standing closer to the converted chat covers both.
	 *
	 * The position, on the other hand, is given up whatever the levels are. A child was answered
	 * against the openness of the moment the walk passed it, and a chat converted anew moved that
	 * answer for the whole subtree: a walk resuming where it stood would leave every child below its
	 * position describing a reach that is gone, and no event of that subtree is due afterwards. The
	 * price of starting over is a repeated pass, and the answer of every child is read anew from the
	 * table either way.
	 *
	 * The names, on the other hand, are joined: a walk standing at a position of its own already covers
	 * the children it has passed for the names it carried there, and starting over for the ones added
	 * beside them costs a repeated pass and nothing else.
	 *
	 * @param int $depth Level below the converted chat this walk stands at, counted from its children.
	 * @param int[] $userIds Members the walk is run for, empty for the walk of a conversion.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function queueDescendants(int $chatId, int $depth, array $userIds = []): array
	{
		if ($depth < 1 || $depth > ChatAncestorNavigator::DEFAULT_DEPTH)
		{
			return self::getQueue();
		}

		$key = self::buildDescendantsKey($chatId, $userIds !== []);

		return self::mutateQueue(static function (array &$queue) use ($key, $chatId, $depth, $userIds): bool {
			$stands = $queue[$key]['descendants'] ?? null;
			if (is_array($stands))
			{
				$standingUserIds = $stands['userIds'] ?? [];
				$depth = min((int)$stands['depth'], $depth);
				$userIds = self::mergeUserIds($standingUserIds, $userIds);
				if (
					(int)$stands['cursor'] === 0
					&& (int)$stands['depth'] === $depth
					&& $userIds === $standingUserIds
				)
				{
					return false;
				}
			}

			$element = ['chatId' => $chatId, 'cursor' => 0, 'depth' => $depth];
			if ($userIds !== [])
			{
				$element['userIds'] = $userIds;
			}

			$queue[$key] = ['descendants' => $element];

			return true;
		});
	}

	/**
	 * Queues a message whose entry the index refused to write. The walk reads the message anew and runs
	 * the rule of the write path over it, so an element that stands twice costs a repeated write of the
	 * same entry and never a stale text.
	 *
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueReindex(int $messageId): array
	{
		if ($messageId <= 0)
		{
			return self::getQueue();
		}

		$key = self::buildReindexKey($messageId);

		return self::mutateQueue(static function (array &$queue) use ($key, $messageId): bool {
			if (array_key_exists($key, $queue))
			{
				return false;
			}

			$queue[$key] = ['reindex' => ['messageId' => $messageId, 'attempts' => 0]];

			return true;
		});
	}

	/**
	 * Queues a handing out of a baked code the write path or a walk could not carry out. The command is
	 * idempotent and the rights are additive, so an element that stands twice costs a repeated walk and
	 * never a wrong range.
	 *
	 * @param string|null $boundary Message key the code is handed out from, null for the whole chat.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueGrant(string $code, int $chatId, ?string $boundary = null): array
	{
		if ($code === '' || $chatId <= 0)
		{
			return self::getQueue();
		}

		$key = self::buildGrantKey($code, $chatId, $boundary);

		return self::mutateQueue(static function (array &$queue) use ($key, $code, $chatId, $boundary): bool {
			if (isset($queue[$key]['grant']))
			{
				return false;
			}

			$queue[$key] = ['grant' => [
				'code' => $code,
				'chatId' => $chatId,
				'boundary' => $boundary,
				'attempts' => 0,
			]];

			return true;
		});
	}

	/**
	 * Queues a taking off of a baked code the write path could not carry out: the core answers nothing
	 * from its permission calls, so a refused command is only ever known at the call itself, and a code
	 * left on the entries of a chat is findability of a user whose access is already gone.
	 *
	 * The element carries the handing back as well, when the command it stands for had one: the two
	 * halves may not be split between the request and the queue, or the retried taking off would strip
	 * the boundary the request had already handed out.
	 *
	 * @param string|null $boundary Message key the code comes back from, null for a plain revocation.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function enqueueRevocation(string $code, int $chatId, ?string $boundary = null): array
	{
		if ($code === '' || $chatId <= 0)
		{
			return self::getQueue();
		}

		$key = self::buildRevocationKey($code, $chatId);

		return self::mutateQueue(static function (array &$queue) use ($key, $code, $chatId, $boundary): bool {
			$stands = $queue[$key]['revoke'] ?? null;
			if (is_array($stands) && ($stands['boundary'] ?? null) === $boundary)
			{
				return false;
			}

			$queue[$key] = ['revoke' => [
				'code' => $code,
				'chatId' => $chatId,
				'boundary' => $boundary,
				'attempts' => 0,
			]];

			return true;
		});
	}

	/**
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	public static function dequeue(int $chatId): array
	{
		return self::mutateQueue(static function (array &$queue) use ($chatId): bool {
			if (!array_key_exists($chatId, $queue))
			{
				return false;
			}

			unset($queue[$chatId]);

			return true;
		});
	}

	/**
	 * @param array<int, int> $members userId => startId
	 * @param int[] $retriedIds Members whose command is a repeat: theirs carries the delivery to the
	 *                          engine of its own, the SQL of a repeat being no reason for the core to
	 *                          send one.
	 * @return int[] Members whose command the index refused.
	 */
	private function grantAccess(int $chatId, array $members, array $retriedIds = []): array
	{
		$adapter = ServiceLocator::getInstance()->get(SearchAdapter::class);
		$retried = array_fill_keys($retriedIds, true);
		$refused = [];

		foreach ($members as $userId => $startId)
		{
			$code = PermissionBuilder::buildUserCode($userId);
			$filter = [
				'=PARAM1' => $chatId,
				'>=PARAM2' => ContentBuilder::buildMessageKey($startId),
			];

			$isHandedOut = $adapter->addPermission($code, $filter)
				&& (!isset($retried[$userId]) || $adapter->resyncPermission($code, $filter))
			;

			if (!$isHandedOut)
			{
				$refused[] = $userId;
			}
		}

		return $refused;
	}

	/**
	 * Takes the name of a member off every entry of the chat, exactly as a leaving does. A refusal goes
	 * to the queue of the takings off, which reads the access anew and hands the name back if it is due
	 * again by then.
	 *
	 * @param int[] $userIds
	 */
	private function revokeAccess(int $chatId, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$adapter = ServiceLocator::getInstance()->get(SearchAdapter::class);

		foreach ($userIds as $userId)
		{
			$code = PermissionBuilder::buildUserCode($userId);
			if (!$adapter->deletePermission($code, ['=PARAM1' => $chatId]))
			{
				self::enqueueRevocation($code, $chatId);
			}
		}
	}

	/**
	 * @return array<int, int> userId => startId
	 */
	private static function readMembers(int $chatId, int $cursor, int $limit): array
	{
		$rows = RelationTable::query()
			->setSelect(['USER_ID', 'START_ID'])
			->where('CHAT_ID', $chatId)
			->where('USER_ID', '>', $cursor)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		$members = [];
		foreach ($rows as $row)
		{
			$members[(int)$row['USER_ID']] = (int)$row['START_ID'];
		}

		return $members;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int> userId => startId
	 */
	private static function readMembersByIds(int $chatId, array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['USER_ID', 'START_ID'])
			->where('CHAT_ID', $chatId)
			->whereIn('USER_ID', $userIds)
			->fetchAll()
		;

		$members = [];
		foreach ($rows as $row)
		{
			$members[(int)$row['USER_ID']] = (int)$row['START_ID'];
		}

		return $members;
	}

	/**
	 * Members of the list that may hold a name of their own, with the boundary of their relation. The
	 * builder is asked and not the table: it is the single answerer of the domain, and a member the
	 * required ancestors of the chat deny is refused by Chat::checkAccess() whatever his relation says.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> userId => startId
	 */
	private static function readAllowedMembersByIds(int $chatId, array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		return ServiceLocator::getInstance()
			->get(PermissionBuilder::class)
			->getHistoryBoundaries(Chat::getInstance($chatId), $userIds)
		;
	}

	private static function countMembersLeft(int $chatId, int $cursor): int
	{
		return RelationTable::getCount(['=CHAT_ID' => $chatId, '>USER_ID' => $cursor]);
	}

	/**
	 * Children of a chat in the order of the walk, each with the two answers of its type the reach is
	 * built from. The type is read here and not asked of the resolver: the walk has to tell a child the
	 * chain runs through from one that answers for itself, and the resolver answers only the reach.
	 *
	 * @return list<array{id: int, isOpen: bool, requiresParentMembership: bool}>
	 */
	private static function readChildren(int $chatId, int $cursor, int $limit): array
	{
		$rows = ChatTable::query()
			->setSelect(['ID', 'TYPE', 'ENTITY_TYPE'])
			->where('PARENT_ID', $chatId)
			->where('ID', '>', $cursor)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		$registry = ServiceLocator::getInstance()->get(TypeRegistry::class);
		$children = [];

		foreach ($rows as $row)
		{
			$type = $registry->getByLiteralAndEntity(
				(string)$row['TYPE'],
				$row['ENTITY_TYPE'] === null ? null : (string)$row['ENTITY_TYPE'],
			);

			$children[] = [
				'id' => (int)$row['ID'],
				'isOpen' => $type->isOpen,
				'requiresParentMembership' => $type->requiresParentMembership,
			];
		}

		return $children;
	}

	/**
	 * The chats of the list that hold children of their own, in one query: a walk queued for every chat
	 * of a portion would spend a step of the agent apiece on reading an empty portion.
	 *
	 * @param int[] $chatIds
	 * @return int[]
	 */
	private static function keepParents(array $chatIds): array
	{
		if ($chatIds === [])
		{
			return [];
		}

		$rows = ChatTable::query()
			->setSelect(['PARENT_ID'])
			->whereIn('PARENT_ID', $chatIds)
			->setGroup(['PARENT_ID'])
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['PARENT_ID'], $rows);
	}

	/**
	 * Moves the cursor of a walk over the descendants, and only while the element stands with the very
	 * cursor the step started from: a conversion that queued the walk anew meanwhile outranks the
	 * position this step reached, exactly as it does for the walk over the members of a chat.
	 *
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function advanceDescendants(string $key, int $expectedCursor, int $cursor): array
	{
		return self::mutateQueue(static function (array &$queue) use ($key, $expectedCursor, $cursor): bool {
			$stands = $queue[$key]['descendants'] ?? null;
			if (!is_array($stands) || (int)$stands['cursor'] !== $expectedCursor || $cursor <= $expectedCursor)
			{
				return false;
			}

			$queue[$key]['descendants']['cursor'] = $cursor;

			return true;
		});
	}

	/**
	 * Writes down what the step of a chat has done, and exactly that.
	 *
	 * Of the awaiting members it takes the ones the step handed a boundary to and no others: one whose
	 * boundary moved again while the step was running is queued anew and stays. The members the index
	 * refused are named in the element instead, the walked ones among them added by name - the cursor
	 * has passed them and the walk is never sent back. The cursor moves only while the chat stands with
	 * the very cursor the step started from - a conversion that ran meanwhile outranks the position this
	 * step reached. An empty portion of the walk leaves the chat without a cursor, and with nothing
	 * awaiting either the element goes.
	 *
	 * @param int[] $handed
	 * @param int[] $requeued Members the index refused, to be named in the element anew.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function settleChat(
		int $chatId,
		array $handed,
		?int $expectedCursor,
		?int $cursor,
		array $requeued = [],
		int $attempts = 0,
	): array
	{
		return self::mutateQueue(
			static function (array &$queue) use ($chatId, $handed, $expectedCursor, $cursor, $requeued, $attempts): bool {
				$element = $queue[$chatId] ?? null;
				if ($element === null || isset($element['delete']))
				{
					return false;
				}

				$awaiting = array_values(array_diff($element['awaiting'], $handed));
				foreach ($requeued as $userId)
				{
					if (!in_array($userId, $awaiting, true))
					{
						$awaiting[] = $userId;
					}
				}

				$isChanged = $awaiting !== $element['awaiting'];
				$element['awaiting'] = $awaiting;

				if (($element['attempts'] ?? 0) !== $attempts)
				{
					// the counter is written only while it stands above zero: an element of a chat nothing
					// refuses keeps the shape the queue always held
					if ($attempts > 0)
					{
						$element['attempts'] = $attempts;
					}
					else
					{
						unset($element['attempts']);
					}

					$isChanged = true;
				}

				if ($element['cursor'] === $expectedCursor && $cursor !== $expectedCursor)
				{
					$element['cursor'] = $cursor;
					$isChanged = true;
				}

				if (!$isChanged)
				{
					return false;
				}

				if ($element['cursor'] === null && $element['awaiting'] === [])
				{
					unset($queue[$chatId]);
				}
				else
				{
					$queue[$chatId] = $element;
				}

				return true;
			},
		);
	}

	/**
	 * @param string[] $keys
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function dropElements(array $keys): array
	{
		return self::mutateQueue(static function (array &$queue) use ($keys): bool {
			$isChanged = false;

			foreach ($keys as $key)
			{
				if (array_key_exists($key, $queue))
				{
					unset($queue[$key]);
					$isChanged = true;
				}
			}

			return $isChanged;
		});
	}

	/**
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function dropElement(string $key): array
	{
		return self::mutateQueue(static function (array &$queue) use ($key): bool {
			if (!array_key_exists($key, $queue))
			{
				return false;
			}

			unset($queue[$key]);

			return true;
		});
	}

	/**
	 * Every change of the queue is a delta applied under a named lock to the value read from the
	 * database at that very moment. A whole-map rewrite would drop the chat a conversion queued while
	 * the step was running, and the loss is silent: the members of such a chat would simply never find
	 * its history.
	 *
	 * A timed out lock is not a race that may be ignored: the timeout means another process is holding
	 * it right now, and its own write lands on the whole map afterwards - the operation lost that way
	 * can be the taking off of a personal code, and then the shared search of the portal goes on
	 * answering with the text of a chat to a member already excluded from it.
	 *
	 * Nothing is therefore written without the lock. Reading the change back afterwards is no
	 * substitute for holding it: both processes would read their own write and neither would see the
	 * one that landed after it. The lock is waited for again instead, a bounded number of times and
	 * within the wait one attempt used to take.
	 *
	 * A change that never gets it is not dropped either: it is parked in a row of its own, which the
	 * step brings back into the queue. Dropping it would lose the taking off of a personal code, and
	 * the shared search of the portal would go on answering with the text of a chat to a member already
	 * excluded from it - for good, since nothing would be left to say the code has to come off.
	 *
	 * A parked change raises its executor right here and not at the caller. The queue answered to a
	 * caller is the one that stands, and a change on the parked road stands nowhere in it: a caller
	 * reading an empty queue would take that for 'nothing to walk' and leave the parking without an
	 * agent for good. The order is the one every registration outside the lock keeps - park first, read
	 * the agent after - so a parking the finishing step misses is a parking whose own registration finds
	 * the agent already taken off.
	 *
	 * @param \Closure(array<int|string, array> &$queue): bool $mutator Answers whether the queue changed.
	 * @return array<int|string, array> The queue as it stands after the change.
	 */
	private static function mutateQueue(\Closure $mutator): array
	{
		$queue = static::writeUnderLock($mutator);
		if ($queue !== null)
		{
			return $queue;
		}

		$stored = self::getQueue();
		$pending = $stored;
		if (!$mutator($pending))
		{
			// the queue already holds what the change meant to write: another process wrote it
			return $stored;
		}

		(new Logger(self::LOG_TAG))->log(
			'conversion queue lock timed out ' . self::QUEUE_LOCK_ATTEMPTS . ' times, the change is parked'
		);
		if (self::parkChange($stored, $pending))
		{
			AgentRegistrar::raiseQueueWalkForParkedChange();
		}

		return $stored;
	}

	/**
	 * Protected for the same reason takeAgentOff() is: a case has to be able to send a change onto the
	 * parked road, and the lock of a queue nobody else holds is taken on the first attempt.
	 *
	 * @param \Closure(array<int|string, array> &$queue): bool $mutator Answers whether the queue changed.
	 * @return array<int|string, array>|null Null when the lock was never taken and nothing was written.
	 */
	protected static function writeUnderLock(\Closure $mutator): ?array
	{
		$connection = Application::getConnection();

		for ($attempt = 1; $attempt <= self::QUEUE_LOCK_ATTEMPTS; $attempt++)
		{
			if (!$connection->lock(self::QUEUE_LOCK, self::getLockTimeout()))
			{
				continue;
			}

			try
			{
				$queue = self::getQueue();
				if ($mutator($queue))
				{
					self::writeQueue($queue);
				}
			}
			finally
			{
				$connection->unlock(self::QUEUE_LOCK);
			}

			return $queue;
		}

		return null;
	}

	/**
	 * Puts what the change meant to add into a row of its own. Only the elements it added or replaced
	 * are parked: a change that merely takes elements off loses nothing by staying unwritten, since the
	 * element it meant to drop is retried and every command of the queue is idempotent.
	 *
	 * @param array<int|string, array> $stored
	 * @param array<int|string, array> $pending
	 * @return bool Whether a row was written and work is now waiting in it.
	 */
	private static function parkChange(array $stored, array $pending): bool
	{
		$added = [];
		foreach ($pending as $key => $element)
		{
			if (($stored[$key] ?? null) !== $element)
			{
				$added[$key] = $element;
			}
		}

		if ($added === [])
		{
			return false;
		}

		$payload = Json::encode($added);
		// the same change parked twice stands in one row: the name is the payload itself
		Option::set(self::QUEUE_MODULE_ID, self::PARKED_OPTION_PREFIX . md5($payload), $payload);

		return true;
	}

	/**
	 * Brings the parked changes back into the queue and answers it as it stands afterwards. A row is
	 * taken off only once its elements are in the queue: a merge the lock refused leaves the row for the
	 * next step.
	 *
	 * The transfer is bounded twice over, because it stands before the work of the step and every row of
	 * it costs a lock of its own. The budget bounds the reading of the rows and by that the transfer
	 * itself, so a portal that parked thousands of them at once - a user deleted together with his chats
	 * parks a change per chat - neither loads nor parses more than one step may carry. The first lock a
	 * row could not take ends the transfer altogether: the lock is held by somebody writing the queue
	 * right now, so the rows left would spend the whole allowance of the hit waiting their turn. What is
	 * left waits for the next step, and a step that finished with a parked row standing keeps the agent
	 * of the walk.
	 *
	 * @return array<int|string, array>
	 */
	private static function absorbParkedChanges(): array
	{
		$parked = self::readParkedChanges(self::getParkedPortion());
		if ($parked === [])
		{
			return self::getQueue();
		}

		$queue = null;
		foreach ($parked as $name => $elements)
		{
			if ($elements === [])
			{
				// nothing readable stands in the row: keeping it would only park it forever
				Option::delete(self::QUEUE_MODULE_ID, ['name' => $name]);

				continue;
			}

			$merged = self::writeUnderLock(static function (array &$queue) use ($elements): bool {
				return self::absorbElements($queue, $elements);
			});
			if ($merged === null)
			{
				(new Logger(self::LOG_TAG))->log(
					'the queue lock was not taken by the transfer of a parked change, the rest waits for the next step'
				);

				break;
			}

			$queue = $merged;
			Option::delete(self::QUEUE_MODULE_ID, ['name' => $name]);
		}

		return $queue ?? self::getQueue();
	}

	/**
	 * Parked rows one step brings back into the queue at most.
	 */
	private static function getParkedPortion(): int
	{
		return max(1, (int)Option::get(
			self::$moduleId,
			self::PARKED_PORTION_OPTION,
			self::DEFAULT_PARKED_PORTION,
		));
	}

	/**
	 * Whether any change is parked at all, without reading or parsing a single one of them: the finish
	 * of the walk asks this and nothing else about them.
	 */
	private static function hasParkedChanges(): bool
	{
		$connection = Application::getConnection();
		$prefix = $connection->getSqlHelper()->forSql(self::PARKED_OPTION_PREFIX);

		// the master, exactly as the rows themselves are read: a change parked by another process is
		// worth nothing if the step reads it from a replica that has not caught up
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$row = $connection
				->query(
					"SELECT NAME FROM b_option WHERE MODULE_ID = '" . self::QUEUE_MODULE_ID . "'"
					. " AND NAME LIKE '" . $prefix . "%' LIMIT 1"
				)
				->fetch()
			;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		return is_array($row);
	}

	/**
	 * @param int $limit Rows to read at most; the step carries no more of them than that anyway.
	 * @return array<string, array<int|string, array>> Elements of every parked row by the name of the row.
	 */
	private static function readParkedChanges(int $limit): array
	{
		$connection = Application::getConnection();
		$prefix = $connection->getSqlHelper()->forSql(self::PARKED_OPTION_PREFIX);

		// the master, exactly as the queue is read: a change parked by another process is worth nothing
		// if the step reads it from a replica that has not caught up. Only the rows the step may carry
		// are read: the payload of every parked change is parsed on the way, and a portal that parked
		// thousands of them would pay for all of them to bring back the twenty of the budget
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$rows = $connection
				->query(
					"SELECT NAME, VALUE FROM b_option WHERE MODULE_ID = '" . self::QUEUE_MODULE_ID . "'"
					. " AND NAME LIKE '" . $prefix . "%' ORDER BY NAME LIMIT " . max(1, $limit)
				)
				->fetchAll()
			;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		$parked = [];
		foreach ($rows as $row)
		{
			$parked[(string)$row['NAME']] = self::parseQueue((string)$row['VALUE']);
		}

		return $parked;
	}

	/**
	 * An element standing under the same key already says what the parked one says, with two exceptions,
	 * and both of them carry a position of their own under a key that never changes:
	 *
	 * - a chat carries both a walk and the members named beside it, and the two were queued by different
	 *   commands. Those are joined, and never narrowed - a member the standing element does not name
	 *   would otherwise lose his archive for good;
	 * - a walk over the descendants of a chat carries the children it has passed. A conversion gives that
	 *   position up on purpose, so an element standing at a position of its own may not swallow a parked
	 *   change silently: the children below it were answered against an openness that has moved, and no
	 *   event of that subtree is due afterwards.
	 *
	 * @param array<int|string, array> $queue
	 * @param array<int|string, array> $elements
	 */
	private static function absorbElements(array &$queue, array $elements): bool
	{
		$isChanged = false;

		foreach ($elements as $key => $element)
		{
			if (!array_key_exists($key, $queue))
			{
				$queue[$key] = $element;
				$isChanged = true;

				continue;
			}

			$standing = $queue[$key];
			$merged = self::mergeElements($standing, $element);
			if ($merged !== null && $merged !== $standing)
			{
				$queue[$key] = $merged;
				$isChanged = true;
			}
		}

		return $isChanged;
	}

	/**
	 * @return array|null Null for a pair the standing element already answers for.
	 */
	private static function mergeElements(array $standing, array $parked): ?array
	{
		if (isset($standing['awaiting'], $parked['awaiting']))
		{
			return self::mergeChatElements($standing, $parked);
		}

		if (isset($standing['descendants'], $parked['descendants']))
		{
			return self::mergeDescendantElements($standing, $parked);
		}

		return null;
	}

	/**
	 * @param array{cursor: int|null, awaiting: int[], attempts?: int} $standing
	 * @param array{cursor: int|null, awaiting: int[], attempts?: int} $parked
	 * @return array{cursor: int|null, awaiting: int[], attempts?: int}
	 */
	private static function mergeChatElements(array $standing, array $parked): array
	{
		$awaiting = array_values(array_unique([...$standing['awaiting'], ...$parked['awaiting']]));
		sort($awaiting);
		$standing['awaiting'] = $awaiting;

		// the walk that starts earlier covers the one that starts later, so the lower of the two stands
		if ($parked['cursor'] !== null && ($standing['cursor'] === null || $parked['cursor'] < $standing['cursor']))
		{
			$standing['cursor'] = $parked['cursor'];
		}

		return $standing;
	}

	/**
	 * Of two walks over the descendants of one chat the earlier position stands, exactly as it does for
	 * the walk over the members: a repeated pass costs steps of the agent and nothing else, while a
	 * position kept leaves the children below it describing a reach that is gone.
	 *
	 * The level is the broader of the two for the same reason it is in queueDescendants(): it answers
	 * only for how far the walk queues the next one, and narrowing it by a merge would cut the subtree
	 * short.
	 *
	 * The names are joined and never narrowed, exactly as they are beside a chat: a member the standing
	 * walk does not carry would otherwise lose his archive for good.
	 *
	 * @param array{descendants: array{chatId: int, cursor: int, depth: int, userIds?: int[]}} $standing
	 * @param array{descendants: array{chatId: int, cursor: int, depth: int, userIds?: int[]}} $parked
	 * @return array{descendants: array{chatId: int, cursor: int, depth: int, userIds?: int[]}}
	 */
	private static function mergeDescendantElements(array $standing, array $parked): array
	{
		$standing['descendants']['cursor'] = min(
			(int)$standing['descendants']['cursor'],
			(int)$parked['descendants']['cursor'],
		);
		$standing['descendants']['depth'] = min(
			(int)$standing['descendants']['depth'],
			(int)$parked['descendants']['depth'],
		);

		$userIds = self::mergeUserIds(
			$standing['descendants']['userIds'] ?? [],
			$parked['descendants']['userIds'] ?? [],
		);
		if ($userIds !== [])
		{
			$standing['descendants']['userIds'] = $userIds;
		}

		return $standing;
	}

	/**
	 * Wait of a single attempt at the lock. The whole operation waits no longer than it did with one
	 * attempt of its own: what changed is that a timeout is retried instead of written past.
	 */
	private static function getLockTimeout(): int
	{
		return max(1, intdiv(self::QUEUE_LOCK_TIMEOUT, self::QUEUE_LOCK_ATTEMPTS));
	}

	/**
	 * @param array<int|string, array> $queue
	 */
	private static function writeQueue(array $queue): void
	{
		if ($queue === [])
		{
			// no option means nothing left to rebake: the queue is the completion mark of the stepper
			Option::delete(self::QUEUE_MODULE_ID, ['name' => self::QUEUE_OPTION]);

			return;
		}

		Option::set(self::QUEUE_MODULE_ID, self::QUEUE_OPTION, Json::encode($queue));
	}

	/**
	 * Reads the option into the one shape the step knows, whatever shape it was written in: a portal
	 * updated in the middle of a walk holds elements of the two formats that preceded the merged one,
	 * and a step falling over those would stop the whole queue.
	 *
	 * @return array<int|string, array> The queue, without the elements nothing can be done with.
	 */
	private static function parseQueue(string $value): array
	{
		if ($value === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($value);
		}
		catch (\Throwable $exception)
		{
			(new Logger(self::LOG_TAG))->logThrowable($exception);

			return [];
		}

		if (!is_array($decoded))
		{
			return [];
		}

		$queue = [];
		foreach ($decoded as $key => $element)
		{
			if (is_array($element) && isset($element['delete']))
			{
				$address = is_array($element['delete']) ? DeletionAddress::fromArray($element['delete']) : null;
				if ($address !== null)
				{
					// the key is rebuilt from the address, so the identity of an element cannot drift
					$queue[$address->getKey()] = ['delete' => $address->toArray()];
				}

				continue;
			}

			if (is_array($element) && isset($element['entries']))
			{
				$entries = is_array($element['entries']) ? self::parseEntries($element['entries']) : null;
				if ($entries !== null)
				{
					// the key is rebuilt from the list, so the identity of it cannot drift
					$queue[self::buildEntriesKey($entries['messageIds'])] = ['entries' => $entries];
				}

				continue;
			}

			if (is_array($element) && isset($element['documents']))
			{
				$documents = is_array($element['documents']) ? self::parseDocuments($element['documents']) : null;
				if ($documents !== null)
				{
					// the key is rebuilt from the list, so the identity of it cannot drift
					$queue[self::buildDocumentsKey($documents['documentIds'])] = ['documents' => $documents];
				}

				continue;
			}

			if (is_array($element) && isset($element['revoke']))
			{
				$revocation = is_array($element['revoke']) ? self::parseRevocation($element['revoke']) : null;
				if ($revocation !== null)
				{
					// the key is rebuilt from the element, so the identity of it cannot drift
					$queue[self::buildRevocationKey($revocation['code'], $revocation['chatId'])] = [
						'revoke' => $revocation,
					];
				}

				continue;
			}

			if (is_array($element) && isset($element['grant']))
			{
				$grant = is_array($element['grant']) ? self::parseRevocation($element['grant']) : null;
				if ($grant !== null)
				{
					// the key is rebuilt from the element, so the identity of it cannot drift
					$queue[self::buildGrantKey($grant['code'], $grant['chatId'], $grant['boundary'])] = [
						'grant' => $grant,
					];
				}

				continue;
			}

			if (is_array($element) && isset($element['reindex']))
			{
				$reindex = is_array($element['reindex']) ? self::parseReindex($element['reindex']) : null;
				if ($reindex !== null)
				{
					// the key is rebuilt from the element, so the identity of it cannot drift
					$queue[self::buildReindexKey($reindex['messageId'])] = ['reindex' => $reindex];
				}

				continue;
			}

			if (is_array($element) && isset($element['descendants']))
			{
				$descendants = is_array($element['descendants'])
					? self::parseDescendants($element['descendants'])
					: null
				;
				if ($descendants !== null)
				{
					// the key is rebuilt from the element, so the identity of it cannot drift
					$key = self::buildDescendantsKey($descendants['chatId'], ($descendants['userIds'] ?? []) !== []);
					$read = ['descendants' => $descendants];
					// a walk of the older formats stood under a key per member, and both rebuild into this one
					$queue[$key] = isset($queue[$key])
						? self::mergeDescendantElements($queue[$key], $read)
						: $read
					;
				}

				continue;
			}

			$chatId = (int)$key;
			$chat = $chatId > 0 ? self::parseChatElement($element) : null;
			if ($chat !== null)
			{
				$queue[$chatId] = $chat;
			}
		}

		return $queue;
	}

	/**
	 * The members are written down only while any are named: the walk of a conversion is the shape the
	 * queue has always held, and an element carrying an empty list beside it would be a second shape of
	 * it. A row written before the walk carried more than one name says userId, and it is read as the
	 * list of one it stands for.
	 *
	 * @param array<string, mixed> $descendants
	 * @return array{chatId: int, cursor: int, depth: int, userIds?: int[]}|null
	 */
	private static function parseDescendants(array $descendants): ?array
	{
		$chatId = (int)($descendants['chatId'] ?? 0);
		$depth = (int)($descendants['depth'] ?? 0);
		if ($chatId <= 0 || $depth < 1 || $depth > ChatAncestorNavigator::DEFAULT_DEPTH)
		{
			return null;
		}

		$parsed = [
			'chatId' => $chatId,
			'cursor' => max(0, (int)($descendants['cursor'] ?? 0)),
			'depth' => $depth,
		];

		$userIds = self::mergeUserIds(
			is_array($descendants['userIds'] ?? null) ? $descendants['userIds'] : [],
			[$descendants['userId'] ?? 0],
		);
		if ($userIds !== [])
		{
			$parsed['userIds'] = $userIds;
		}

		return $parsed;
	}

	/**
	 * @param array<string, mixed> $reindex
	 * @return array{messageId: int, attempts: int}|null
	 */
	private static function parseReindex(array $reindex): ?array
	{
		$messageId = (int)($reindex['messageId'] ?? 0);
		if ($messageId <= 0)
		{
			return null;
		}

		return ['messageId' => $messageId, 'attempts' => max(0, (int)($reindex['attempts'] ?? 0))];
	}

	/**
	 * Shape shared by a taking off and a handing out: both name a code, a chat, an optional boundary and
	 * the attempts spent on it, and both are read the same way.
	 *
	 * @param array<string, mixed> $revocation
	 * @return array{code: string, chatId: int, boundary: string|null, attempts: int}|null
	 */
	private static function parseRevocation(array $revocation): ?array
	{
		$code = (string)($revocation['code'] ?? '');
		$chatId = (int)($revocation['chatId'] ?? 0);
		if ($code === '' || $chatId <= 0)
		{
			return null;
		}

		$boundary = $revocation['boundary'] ?? null;

		return [
			'code' => $code,
			'chatId' => $chatId,
			'boundary' => $boundary === null ? null : (string)$boundary,
			'attempts' => max(0, (int)($revocation['attempts'] ?? 0)),
		];
	}

	/**
	 * @param array<string, mixed> $entries
	 * @return array{messageIds: int[], attempts: int}|null
	 */
	private static function parseEntries(array $entries): ?array
	{
		$messageIds = [];
		foreach (is_array($entries['messageIds'] ?? null) ? $entries['messageIds'] : [] as $messageId)
		{
			if ((int)$messageId > 0)
			{
				$messageIds[] = (int)$messageId;
			}
		}

		$messageIds = array_values(array_unique($messageIds));
		if ($messageIds === [])
		{
			return null;
		}

		sort($messageIds);

		return ['messageIds' => $messageIds, 'attempts' => max(0, (int)($entries['attempts'] ?? 0))];
	}

	/**
	 * @return array{documentIds: int[], attempts: int}|null
	 */
	private static function parseDocuments(array $documents): ?array
	{
		$documentIds = [];
		foreach (is_array($documents['documentIds'] ?? null) ? $documents['documentIds'] : [] as $documentId)
		{
			if ((int)$documentId > 0)
			{
				$documentIds[] = (int)$documentId;
			}
		}

		$documentIds = array_values(array_unique($documentIds));
		if ($documentIds === [])
		{
			return null;
		}

		sort($documentIds);

		return ['documentIds' => $documentIds, 'attempts' => max(0, (int)($documents['attempts'] ?? 0))];
	}

	/**
	 * A bare number is the cursor of a walk over the whole membership, a bare list is the members
	 * awaiting a boundary: the two formats that preceded the merged element.
	 *
	 * An element with neither has nothing to hand out and would stand at the head of the queue for
	 * good: the step reads the first element of it and the walk would never end.
	 *
	 * @return array{cursor: int|null, awaiting: int[], attempts?: int}|null
	 */
	private static function parseChatElement(mixed $element): ?array
	{
		if (!is_array($element))
		{
			return ['cursor' => max(0, (int)$element), 'awaiting' => []];
		}

		$isMerged = array_key_exists('cursor', $element) || array_key_exists('awaiting', $element);
		$cursor = $isMerged ? $element['cursor'] ?? null : null;
		$awaiting = self::parseMembers($isMerged ? $element['awaiting'] ?? [] : $element);

		if ($cursor === null && $awaiting === [])
		{
			return null;
		}

		$parsed = ['cursor' => $cursor === null ? null : max(0, (int)$cursor), 'awaiting' => $awaiting];
		$attempts = $isMerged ? max(0, (int)($element['attempts'] ?? 0)) : 0;
		if ($attempts > 0)
		{
			// the counter stands in the element only while the chat is retrying, so an element of a queue
			// nothing refuses keeps the shape it always had
			$parsed['attempts'] = $attempts;
		}

		return $parsed;
	}

	/**
	 * @return int[]
	 */
	private static function parseMembers(mixed $stands): array
	{
		if (!is_array($stands))
		{
			return [];
		}

		$members = [];
		foreach ($stands as $userId)
		{
			if ((int)$userId > 0)
			{
				$members[] = (int)$userId;
			}
		}

		return array_values(array_unique($members));
	}

	private static function getPortion(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PORTION_OPTION, self::DEFAULT_PORTION));
	}

	/**
	 * Addresses one step may settle at once. Bounds the number of times the queue is rewritten and not
	 * the work of the index: what that work costs is bounded by the allowance of the deletion.
	 */
	private static function getDeletionQueuePortion(): int
	{
		return max(1, (int)Option::get(
			self::$moduleId,
			self::DELETION_QUEUE_PORTION_OPTION,
			self::DEFAULT_DELETION_QUEUE_PORTION,
		));
	}
}
