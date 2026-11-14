<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Async\Promise\BackgroundJobPromise;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Search\Access\CodeCarrierResolver;
use Bitrix\Im\V2\Integration\Search\Access\OpenCodeReachResolver;
use Bitrix\Im\V2\Integration\Search\Update\AgentRegistrar;
use Bitrix\Im\V2\Integration\Search\Update\ChatConversionPermissionStepper;
use Bitrix\Im\V2\Integration\Search\Update\OpenCodeSynchronizer;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Domain facade over the search index: the only write path of the module.
 * Obtain it through ServiceLocator only - the deindexing buffer lives in the instance
 * and a burst of deletions within one hit has to reach the adapter as a single call.
 */
class IndexService
{
	private const DELETION_PORTION_OPTION = 'message_search_deletion_portion';
	private const DEFAULT_DELETION_PORTION = 500;
	private const DELETION_BUDGET_OPTION = 'message_search_deletion_portion_budget';
	private const DEFAULT_DELETION_BUDGET = 10;
	private const HISTORY_REBAKE_THRESHOLD_OPTION = 'message_search_history_rebake_threshold';
	private const DEFAULT_HISTORY_REBAKE_THRESHOLD = 5000;
	private const GRANT_BUDGET_OPTION = 'message_search_grant_budget';
	private const DEFAULT_GRANT_BUDGET = 50;
	private const LOG_TAG = 'message-search-index';

	private array $deleteBuffer = [];
	private bool $isFlushScheduled = false;

	public function __construct(
		private readonly SearchAdapter $adapter,
		private readonly ContentBuilder $contentBuilder,
		private readonly HygieneRegistry $hygieneRegistry,
		private readonly PermissionBuilder $permissionBuilder,
		private readonly CodeCarrierResolver $codeCarrierResolver,
		private readonly OpenCodeReachResolver $openCodeReachResolver,
		private readonly OpenCodeSynchronizer $openCodeSynchronizer,
	)
	{}

	/**
	 * The row is read inside the job by identifier and not carried into it by the object of the write
	 * path: a deletion of this very message may reach the index while the job waits, and the object
	 * would then write the text back into an index the deletion has already cleaned. Nothing comes
	 * after that - the deletion has already reported itself, and no second event of it is due.
	 */
	public function onMessageSend(Message $message, Chat $chat): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		$messageId = $message->getMessageId() ?? 0;
		if ($messageId <= 0)
		{
			return;
		}

		$this->defer(fn () => $this->indexStoredMessage($messageId, $chat, false));
	}

	/**
	 * Read inside the job for the same reason a send is: the object of the write path carries the text
	 * of the moment it was built, and a deletion that overtakes the job would find its entry written
	 * back by it.
	 */
	public function onMessageUpdate(Message $message, Chat $chat): void
	{
		$this->onMessageUpdatedById($message->getMessageId() ?? 0, $chat);
	}

	/**
	 * The same as onMessageUpdate, entered by the identifier of the message: the wide message row is
	 * read inside the job that writes the entry, so a portal with the pipeline off never pays for it.
	 * A caller that deferred the read on its own would nest a job in a job.
	 */
	public function onMessageUpdatedById(int $messageId, Chat $chat): void
	{
		if (!Features::isMessageSearchIndexingEnabled() || $messageId <= 0)
		{
			return;
		}

		$this->defer(fn () => $this->indexStoredMessage($messageId, $chat));
	}

	public function onMessageDelete(array $messageIds): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		$this->pushToDeleteBuffer($messageIds);
	}

	public function onMessageDisappear(int $messageId): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		$this->pushToDeleteBuffer([$messageId]);
	}

	/**
	 * The chat is not the whole address of the command, exactly as it is not for the taking off. A chat
	 * needing membership in its parent is answered by that parent, and the relations of such a child
	 * outlive the leaving of the parent: the walk of the departure took the names off the entries of
	 * the subtree, and nothing below is due to hand them back to a member the project holds again. The
	 * messenger shows him those chats the moment he returns, so the subtree goes to the queue.
	 *
	 * @param int[] $userIds Members that received a relation of their own.
	 */
	public function onUsersAdded(Chat $chat, array $userIds): void
	{
		if (!Features::isMessageSearchIndexingEnabled() || $this->isAddressedByParentChat($chat))
		{
			return;
		}

		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0 || $userIds === [])
		{
			return;
		}

		$this->permissionBuilder->forgetChat($chatId);
		$this->defer(function () use ($chat, $chatId, $userIds): void {
			// before the command of the chat itself, exactly as the taking off queues it: a refused
			// grant leaves this job by the road of the retry, and the subtree would then wait for an
			// event that is never due
			if (ChatConversionPermissionStepper::enqueueMemberDescendants($chatId, $userIds) !== [])
			{
				AgentRegistrar::registerQueueRemainder();
			}

			$this->grantChatAccess($chat, $userIds);
		});
	}

	/**
	 * One command covers every branch that takes a member off a chat, the legacy ones included: the
	 * whole chat at once, without a boundary and whatever the openness of the chat. An open context
	 * bakes no name of a carrier but keeps the names of the time it was private, and a name left behind
	 * is a stranger holding the archive once the chat turns private again.
	 *
	 * The chat is not the whole address of the command either. A chat needing membership in its parent
	 * is answered by that parent - Chat::checkAccess() hands the answer over the whole chain - while the
	 * relations of such a child are left where they stood, so the entries of the subtree go on naming a
	 * member the project no longer holds. A request may not walk a tree: the subtree goes to the queue.
	 */
	public function onUserDeleted(Chat $chat, int $userId): void
	{
		if (!Features::isMessageSearchIndexingEnabled() || $this->isAddressedByParentChat($chat))
		{
			return;
		}

		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0 || $userId <= 0)
		{
			return;
		}

		$this->permissionBuilder->forgetChat($chatId);
		$this->defer(function () use ($chat, $chatId, $userId): void {
			// before the command of the chat itself and not after it: a refused taking off leaves this job
			// by the road of the retry, and the subtree would then wait for an event that is never due
			if (ChatConversionPermissionStepper::enqueueMemberDescendants($chatId, [$userId]) !== [])
			{
				AgentRegistrar::registerQueueRemainder();
			}

			$code = PermissionBuilder::buildUserCode($userId);
			if (!$this->adapter->deletePermission($code, ['=PARAM1' => $chatId]))
			{
				$this->retryRevocation($code, $chatId);

				return;
			}

			// the chat may have taken the member back while the job waited: the code goes back with the
			// boundary his relation carries now, exactly as the queue of the walk hands it back. Without
			// this a leaving and a joining of one member land in whatever order their jobs end, and the
			// member let back in stays out of his own archive with nothing left to put him back into it
			$boundary = $this->readCurrentBoundary($chat, $userId);
			if (
				$boundary !== null
				&& !$this->adapter->addPermission($code, ['=PARAM1' => $chatId, '>=PARAM2' => $boundary])
			)
			{
				$this->retryGrant($code, $chatId, $boundary);
			}
		});
	}

	/**
	 * The range of a granted code cannot be narrowed, so the code goes off the chat and comes back with
	 * the new boundary. Above the threshold the handing back goes to the queue of the conversion
	 * stepper: every command walks all the entries of the chat, and cleaning the history is available
	 * to every member and repeatable.
	 *
	 * The taking off may not wait - the search page of the portal answers by the baked codes alone, so
	 * until then the member goes on finding what he has just cleaned away. An undershow in the other
	 * direction is allowed. An open context is unaffected: its entries carry the shared code alone.
	 */
	public function onHistoryCleaned(int $chatId, int $userId, int $startId): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		if ($chatId <= 0 || $userId <= 0)
		{
			return;
		}

		// the boundary of the member is part of the membership the builder remembers
		$this->permissionBuilder->forgetChat($chatId);
		$this->defer(function () use ($chatId, $userId, $startId): void {
			$code = PermissionBuilder::buildUserCode($userId);
			$requestedBoundary = ContentBuilder::buildMessageKey($startId);
			$isPaced = $this->isRebakePaced($chatId, $userId);

			if (!$this->adapter->deletePermission($code, ['=PARAM1' => $chatId]))
			{
				// the handing back goes with the taking off and not before it: a boundary handed out over
				// a code still standing is that code widened, and the retry would then strip both. The
				// queue reads the boundary of the member anew as well and takes this one for a named code
				// no further than the shape of the element
				$this->retryRevocation($code, $chatId, $isPaced ? null : $requestedBoundary);

				return;
			}

			if ($isPaced)
			{
				ChatConversionPermissionStepper::enqueueMember($chatId, $userId);
				// the member stands in the queue already: this only gives the walk an agent to run in
				AgentRegistrar::registerChatConversionRebake();

				return;
			}

			// the boundary is read after the taking off and not taken from the call: two cleanings of one
			// history reach their jobs in whatever order the requests end, and the older of them would
			// hand the code back over the range the newer one has just cut away
			$boundary = $this->readCurrentBoundary(Chat::getInstance($chatId), $userId);
			if ($boundary === null)
			{
				return;
			}

			// the code is already off: a refused handing back leaves the member without his remaining
			// history, so it goes to the queue exactly as the taking off would
			if (!$this->adapter->addPermission($code, ['=PARAM1' => $chatId, '>=PARAM2' => $boundary]))
			{
				$this->retryGrant($code, $chatId, $boundary);
			}
		});
	}

	/**
	 * History boundary a member is due right now, as the key an entry is addressed by. Read at the
	 * moment the command is carried out and never carried into the job: the deferring is what puts the
	 * command out of the write path, and the membership may move while it waits.
	 *
	 * @return string|null Null for a member the chat no longer holds: nothing of it is due to him.
	 */
	private function readCurrentBoundary(Chat $chat, int $userId): ?string
	{
		$boundaries = $this->permissionBuilder->getHistoryBoundaries($chat, [$userId]);

		return isset($boundaries[$userId]) ? ContentBuilder::buildMessageKey($boundaries[$userId]) : null;
	}

	/**
	 * Hands a refused taking off of a code over to the queue of the walk. Waiting for the success here
	 * is not an option: the taking off is deferred out of the write path of a message on purpose, and a
	 * command of the rights queues a walk over every entry of the chat behind it.
	 *
	 * @param string|null $boundary Message key the code comes back from, null for a plain revocation.
	 */
	private function retryRevocation(string $code, int $chatId, ?string $boundary = null): void
	{
		ChatConversionPermissionStepper::enqueueRevocation($code, $chatId, $boundary);
		AgentRegistrar::registerChatConversionRebake();
	}

	/**
	 * The same for a handing out the index refused. A code that never reached the entries of a chat is
	 * an archive its members cannot find, and the write path learns of the refusal at the call alone.
	 *
	 * @param string|null $boundary Message key the code is handed out from, null for the whole chat.
	 */
	private function retryGrant(string $code, int $chatId, ?string $boundary = null): void
	{
		ChatConversionPermissionStepper::enqueueGrant($code, $chatId, $boundary);
		AgentRegistrar::registerChatConversionRebake();
	}

	/**
	 * Whether handing the code back is worth the pace of the walk. A member the entries of the chat
	 * never name is left out first: the walk would hand him a code an open context may not carry. The
	 * size asked for is the message counter of the chat row, an upper bound of what the command would
	 * walk, so the answer errs towards the queue and never the other way.
	 */
	private function isRebakePaced(int $chatId, int $userId): bool
	{
		$chat = Chat::getInstance($chatId);
		if (!$this->usesNamedCode($chat, $userId))
		{
			return false;
		}

		$threshold = (int)Option::get(
			'im',
			self::HISTORY_REBAKE_THRESHOLD_OPTION,
			self::DEFAULT_HISTORY_REBAKE_THRESHOLD,
		);

		return $chat->getMessageCount() > $threshold;
	}

	/**
	 * Messages taken out of the database along with the history a member cleared: everything of the chat
	 * standing below the boundary. Addressed by the boundary and not by a list of identifiers - the
	 * caller walks a whole personal history, and a list of it neither fits a hit nor survives one.
	 */
	public function onHistoryRemoved(int $chatId, int $boundaryMessageId): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		$this->deferDeletion([
			DeletionAddress::ofHistoryBelow($chatId, ContentBuilder::buildMessageKey($boundaryMessageId)),
		]);
	}

	/**
	 * Messages taken out of the database directly, past the deletion service and its transition.
	 *
	 * @param int[] $messageIds
	 */
	public function onMessagesRemoved(array $messageIds): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		if ($messageIds === [])
		{
			return;
		}

		$this->pushToDeleteBuffer($messageIds);
	}

	/**
	 * Openness is what the codes of an entry are built on, so a change of it is the only conversion the
	 * index answers; a conversion that leaves openness alone is not reported here at all.
	 *
	 * The named codes of a chat turned private are not rebaked here: every member has a history
	 * boundary of his own, that is one command per member with a full walk over the entries of the chat
	 * behind each, and a burst of those is what the queue of the conversion stepper exists for.
	 *
	 * @param bool $becameOpen Direction of the conversion that reported itself. What the job applies is
	 *                         the openness of the table and not this one: conversions of one chat land
	 *                         in whatever order the requests that made them end.
	 */
	public function onChatConverted(Chat $chat, bool $becameOpen): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return;
		}

		// the whole memory of the builder and not the chat alone: the openness of a chat answers for its
		// children as well, and a conversion is rare enough to pay a reread of the memberships for it
		$this->permissionBuilder->forgetAllChats();

		$this->defer(function () use ($chatId, $becameOpen): void {
			// the openness is read from the table and not taken from the call: a private-open-private
			// burst reaches its jobs in any order, and the one carrying the older direction would give
			// the shared code back to a chat that stands private, with no event left to take it off
			$isOpen = $this->readOpenness($chatId);
			if ($isOpen === null)
			{
				// the chat is gone: its entries go off by the deletion, and no code of theirs is due
				return;
			}

			if ($isOpen !== $becameOpen)
			{
				(new Logger(self::LOG_TAG))->log(
					"chat {$chatId} reported a conversion into " . ($becameOpen ? 'an open' : 'a private')
					. ' context and stands ' . ($isOpen ? 'open' : 'private')
					. ': the openness of the table is the one applied'
				);
			}

			// the openness of the chat is not the whole answer: a chat standing under a parent it needs
			// membership in is reachable exactly as that parent is, and the shared code names the whole
			// portal. Such a chat carries the names of its members instead, by the rebake below
			// the chat answers for its children as well: an open chat standing under a parent it needs
			// membership in is reachable exactly as that parent is, so a conversion moves the reach of
			// the whole subtree and not of the converted chat alone. The children are walked by the
			// queue and never here - a request may not walk a tree
			ChatConversionPermissionStepper::enqueueDescendants($chatId);

			if ($isOpen && $this->openCodeReachResolver->isReachedById($chatId))
			{
				AgentRegistrar::cancelChatConversionRebake($chatId);
				if (!$this->adapter->addPermission(PermissionBuilder::OPEN_ACCESS_CODE, ['=PARAM1' => $chatId]))
				{
					// the pending rebake is already cancelled, so nothing else would come back for this
					// chat: without the shared code its archive is out of reach of the new members
					$this->retryGrant(PermissionBuilder::OPEN_ACCESS_CODE, $chatId);
				}

				AgentRegistrar::registerQueueRemainder();

				return;
			}

			if (!$this->adapter->deletePermission(PermissionBuilder::OPEN_ACCESS_CODE, ['=PARAM1' => $chatId]))
			{
				$this->retryRevocation(PermissionBuilder::OPEN_ACCESS_CODE, $chatId);
			}

			AgentRegistrar::registerChatConversionRebake($chatId);
		});
	}

	/**
	 * Openness of a chat straight from the table. The instance of a chat carries the state its own
	 * request has set, so it answers for the conversion that built it and not for the one that stands.
	 *
	 * @return bool|null Null for a chat the table no longer holds.
	 */
	private function readOpenness(int $chatId): ?bool
	{
		$row = ChatTable::query()
			->setSelect(['TYPE', 'ENTITY_TYPE'])
			->where('ID', $chatId)
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(Chat\Type\TypeRegistry::class)
			->getByLiteralAndEntity((string)$row['TYPE'], $row['ENTITY_TYPE'] === null ? null : (string)$row['ENTITY_TYPE'])
			->isOpen
		;
	}

	/**
	 * A chat moved across the tree. While the type of a chat needs membership in its parent, the reach
	 * of the shared code is answered by that parent, so hanging a chat elsewhere moves the answer as
	 * surely as a conversion does - and nothing in the write path of the tree reports it.
	 *
	 * The dangerous direction is the attaching: an open chat that carried the shared code is hung under
	 * a closed project, every entry written after that is built by the names of the members, and the
	 * entries already written keep the code. The general search surface of the portal runs no re-check
	 * of its own and would answer with their texts to every employee.
	 *
	 * A moved chat answers for its own subtree as well, so the walk over the descendants is queued for
	 * each of them, exactly as a conversion queues it.
	 *
	 * @param int[] $chatIds Chats whose parent has changed.
	 */
	public function onChatReparented(array $chatIds): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		$chatIds = array_values(array_unique(array_filter(
			array_map('intval', $chatIds),
			static fn (int $chatId): bool => $chatId > 0,
		)));
		if ($chatIds === [])
		{
			return;
		}

		// the whole memory of the builder and not the chats alone: the reach of a moved chat answers for
		// its children as well
		$this->permissionBuilder->forgetAllChats();

		$this->defer(function () use ($chatIds): void {
			foreach ($chatIds as $chatId)
			{
				$this->openCodeSynchronizer->sync($chatId);
				ChatConversionPermissionStepper::enqueueDescendants($chatId);
			}

			AgentRegistrar::registerQueueRemainder();
		});
	}

	/**
	 * Deferred and portioned on both branches: the core takes an entry off one at a time, so a channel
	 * of a hundred thousand messages is hundreds of thousands of statements. The deferring costs no
	 * overshow - an entry whose chat is gone is dropped by the facade on enrichment. The job is bounded
	 * as well, and what it does not reach goes to the queue of the walk.
	 *
	 * A comment chat is how the deletion of a channel post reaches the index: its comments are
	 * addressed by the channel and the key of the post, so a walk over their own chat finds none.
	 */
	public function onChatDeleted(Chat $chat): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		if ($chat instanceof Chat\CommentChat)
		{
			$this->deleteCommentsOfPost($chat);

			return;
		}

		$this->deferDeletion([DeletionAddress::ofChat($chat->getId() ?? 0)]);
	}

	/**
	 * Auto-deletion switched on makes every message of the chat non-indexable by the rule of the
	 * registry, and the facade never re-reads that rule: what the chat already has in the index goes off
	 * it whole, the same address a deleted chat is taken off by.
	 *
	 * Switching the setting back off brings nothing back: the messages of the time it stood are simply
	 * not indexed, and an undershow is the direction the domain allows.
	 */
	public function onMessagesAutoDeleteEnabled(int $chatId): void
	{
		if (!Features::isMessageSearchIndexingEnabled() || $chatId <= 0)
		{
			return;
		}

		$this->deferDeletion([DeletionAddress::ofChat($chatId)]);
	}

	/**
	 * Deletion of a portal user: what he authored anywhere on the portal, and the chats erased together
	 * with him. Those chats matter on their own - they are erased whole, messages of the other party
	 * included, and nothing walks their entries by author.
	 *
	 * One job covers both walks: a job per portion would let the walk of a user be lost by halves. What
	 * the budget does not reach stands in the queue of the walk.
	 *
	 * @param int[] $chatIds Chats deleted along with the user.
	 */
	public function onPortalUserDeleted(int $userId, array $chatIds = []): void
	{
		if (!Features::isMessageSearchIndexingEnabled() || $userId <= 0)
		{
			return;
		}

		$addresses = [DeletionAddress::ofAuthor($userId)];
		foreach (array_unique(array_map('intval', $chatIds)) as $chatId)
		{
			$addresses[] = DeletionAddress::ofChat($chatId);
		}

		$this->deferDeletion($addresses);
	}

	/**
	 * Entry of the one-off walks: the very same registry, builder and adapter the online pipeline
	 * writes through, and the entry is replaced whole, so a repeated walk creates no duplicate.
	 *
	 * Synchronous on purpose. The core stores the cursor of the stepper only after execute() has
	 * returned, so a deferred write would let the cursor pass records whose write is still queued.
	 *
	 * @return bool Whether the entry of the message stands as the rule of the domain says it should. A
	 *              walk may not carry its cursor past a message that answered false: the index would
	 *              then be missing an entry nothing comes back for.
	 */
	public function reindexMessage(Message $message, Chat $chat): bool
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return true;
		}

		return $this->index($message, $chat, true);
	}

	private function defer(callable $work): void
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			return;
		}

		BackgroundJobPromise::deferJob($work);
	}

	/**
	 * @return bool Whether the entry now stands as the rule says it should.
	 */
	private function index(Message $message, Chat $chat, bool $overwrite): bool
	{
		$messageId = $message->getMessageId();
		if ($messageId === null)
		{
			return true;
		}

		// a rewrite is the only moment the pipeline learns that the rule has turned against a message the
		// index already holds, and the facade never re-reads the registry: an entry left standing here
		// goes on being found by the text the rule has just forbidden
		if (!$this->hygieneRegistry->isIndexable($message, $chat))
		{
			return !$overwrite || $this->takeEntryOff($messageId);
		}

		$entry = $this->contentBuilder->build($message, $chat);
		if ($entry === [])
		{
			return true;
		}

		// an entry no code reaches is not written at all, and a rewrite takes off the one already
		// standing: the core replaces the codes of an entry only when the new set is not empty, so
		// the names the chat no longer holds would stay on it
		if ($entry['PERMISSIONS'] === [])
		{
			return !$overwrite || $this->takeEntryOff($messageId);
		}

		return $this->adapter->index($messageId, $entry, $overwrite);
	}

	/**
	 * Takes off the entry of a message the rule no longer lets into the index. A refusal is not left at
	 * the call: the online pipeline meets it inside a background job with nothing behind it to carry the
	 * command further, so the identifier goes to the queue of the walk exactly as a refused deletion of
	 * the write path does. A walk that called this reads the answer as well and holds its cursor.
	 */
	private function takeEntryOff(int $messageId): bool
	{
		if ($this->adapter->deleteByMessageIds([$messageId]))
		{
			return true;
		}

		ChatConversionPermissionStepper::enqueueMessageDeletion([$messageId], self::getDeletionPortion());
		AgentRegistrar::registerQueueRemainder();

		return false;
	}

	/**
	 * Every code handed out is an INSERT ... SELECT over all the entries of the chat and a
	 * synchronization of the rights of the engine behind it, so a structure sync bringing thousands of
	 * members at once would hold a single worker for the whole of it. The job spends a budget and hands
	 * the rest over to the queue of the walk by name, which grants a portion at a time at its own pace.
	 *
	 * The budget is counted over the members that carry a name of their own: a member of an open context
	 * reaches the entries by the shared code, and a command for him would change nothing.
	 *
	 * @param int[] $userIds
	 */
	private function grantChatAccess(Chat $chat, array $userIds): void
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return;
		}

		$named = array_values(array_filter(
			array_map('intval', $userIds),
			fn (int $userId): bool => $userId > 0 && $this->usesNamedCode($chat, $userId),
		));
		$budget = self::getGrantBudget();
		$deferred = array_slice($named, $budget);
		$boundaries = $this->permissionBuilder->getHistoryBoundaries($chat, array_slice($named, 0, $budget));

		foreach ($boundaries as $userId => $startId)
		{
			$code = PermissionBuilder::buildUserCode($userId);
			$boundary = ContentBuilder::buildMessageKey($startId);

			if (!$this->adapter->addPermission($code, ['=PARAM1' => $chatId, '>=PARAM2' => $boundary]))
			{
				$this->retryGrant($code, $chatId, $boundary);
			}
		}

		if ($deferred !== [])
		{
			ChatConversionPermissionStepper::enqueueMembers($chatId, $deferred);
			AgentRegistrar::registerChatConversionRebake();
		}
	}

	/**
	 * A member of an open context reaches its entries by the shared code, and no name of his is ever
	 * baked into them: a command for such a member finds nothing to change, yet still queues a walk
	 * over every entry of the largest chats of the portal.
	 *
	 * Which contexts those are is the reach of the shared code and not the open type alone - the very
	 * question the builder of the codes asks. A chat under a parent it needs membership in carries the
	 * names of all its members, the employees included, so a name left out here is an archive the member
	 * never finds: nothing comes back for a command that was never issued.
	 */
	private function usesNamedCode(Chat $chat, int $userId): bool
	{
		return !$this->openCodeReachResolver->isReached($chat) || !$this->codeCarrierResolver->isCarrier($userId);
	}

	/**
	 * Entries of a comment live on its channel, addressed by the key of the post, and a relation of a
	 * comment chat is a subscription to the thread. A command by such a chat would miss every entry it
	 * means to reach and, on an archive collected before the comments were readdressed, would hand out
	 * findability by subscription instead.
	 */
	private function isAddressedByParentChat(Chat $chat): bool
	{
		return $chat instanceof Chat\CommentChat;
	}

	private function deleteCommentsOfPost(Chat\CommentChat $chat): void
	{
		$channelChatId = $chat->getParentChatId() ?? 0;
		$postId = $chat->getParentMessageId();
		if ($channelChatId <= 0 || $postId <= 0)
		{
			return;
		}

		$this->deferDeletion([DeletionAddress::ofPost($channelChatId, ContentBuilder::buildMessageKey($postId))]);
	}

	/**
	 * @param array<DeletionAddress|null> $addresses
	 */
	private function deferDeletion(array $addresses): void
	{
		$addresses = array_values(array_filter($addresses));
		if ($addresses === [])
		{
			return;
		}

		$this->defer(fn () => $this->takeAddressesOff($addresses));
	}

	/**
	 * Takes off the addresses of one deletion, a portion at a time and no further than the budget of a
	 * single unit of work. A background job runs under the time limit of the request and keeps no
	 * cursor, so without a budget the walk is dropped halfway with no way back to it. Above the budget
	 * the rest of the deletion goes to the queue of the walk.
	 *
	 * @param DeletionAddress[] $addresses
	 */
	private function takeAddressesOff(array $addresses): void
	{
		$budget = self::getDeletionBudget();
		$left = [];
		$isRefused = false;

		foreach ($addresses as $address)
		{
			if ($isRefused || $budget <= 0)
			{
				$left[] = $address;

				continue;
			}

			$spent = $this->walkAddress($address, $budget);
			if ($spent === null)
			{
				// the index refused this address and would refuse the rest of them alike: every one of
				// them keeps whatever it still holds and goes to the queue as it is
				$isRefused = true;
				$left[] = $address;

				continue;
			}

			if ($spent === $budget)
			{
				$left[] = $address;
				$budget = 0;

				continue;
			}

			// an address that answered a short portion still cost a read of the index: without a unit
			// spent on it a user deleted along with a thousand small chats would walk them all in one job
			$budget -= max(1, $spent);
		}

		if ($left === [])
		{
			return;
		}

		ChatConversionPermissionStepper::enqueueDeletions($left);
		AgentRegistrar::registerQueueRemainder();
	}

	/**
	 * One unit of work of a deletion carried by the walk of the queue: the same budget, the same
	 * portion, the step of an agent instead of the job of a hit.
	 *
	 * @return bool Whether the address needs no unit of work of its own any more. A refusal of the index
	 *              is not an answer of that kind: the address keeps its place in the queue.
	 */
	public function takeAddressOff(DeletionAddress $address): bool
	{
		$budget = self::getDeletionBudget();
		$spent = $this->walkAddress($address, $budget);

		return $spent !== null && $spent < $budget;
	}

	/**
	 * The same within an allowance the caller keeps for itself, so that one unit of work may carry
	 * several addresses: every change of the queue rewrites the whole of it, and a mass deletion stands
	 * in it as thousands of addresses, most of which hold a handful of entries each.
	 *
	 * @return int|null Portions of the allowance spent while the address kept answering full ones. A
	 *                  number below the allowance means the address holds nothing more. Null means the
	 *                  index refused to answer.
	 */
	public function takeAddressOffWithin(DeletionAddress $address, int $allowance): ?int
	{
		return $this->walkAddress($address, max(1, $allowance));
	}

	/**
	 * Takes off what the address holds, a portion at a time and inside the unit of work it is called
	 * from: a walk that queued its portions would let the caller run past entries whose deletion is
	 * still waiting.
	 *
	 * The walk consumes what it reads, so the next portion is what is left of the address and no cursor
	 * is needed. An entry of the previous portion coming back means the deletion is not moving, and a
	 * unit of work may not spin on it.
	 *
	 * @return int|null Portions of the allowance spent while the address kept answering full ones. A
	 *                  number below the allowance means the walk stopped on its own and the address
	 *                  holds nothing more. Null means the index refused to answer, which is not the
	 *                  same and may not be taken for an emptied address: the walk is handed back
	 *                  unfinished and the address is walked again.
	 */
	private function walkAddress(DeletionAddress $address, int $allowance): ?int
	{
		$portion = self::getDeletionPortion();
		$previous = [];

		for ($spent = 0; $spent < $allowance; $spent++)
		{
			$messageIds = $this->readAddressPortion($address, $portion);
			if ($messageIds === null)
			{
				return null;
			}

			// an entry of the previous portion coming back means the deletion is not moving; the address
			// is left alone on purpose, a unit of work may not spin on it and neither may the queue
			if ($messageIds === [] || array_intersect($messageIds, $previous) !== [])
			{
				return $spent;
			}

			if (!$this->adapter->deleteByMessageIds($messageIds))
			{
				return null;
			}

			if (count($messageIds) < $portion)
			{
				return $spent;
			}

			$previous = $messageIds;
		}

		return $allowance;
	}

	/**
	 * @return int[]|null Null means the index cannot be read, which ends the walk without pretending
	 *                    the address is empty.
	 */
	private function readAddressPortion(DeletionAddress $address, int $portion): ?array
	{
		return match ($address->type)
		{
			DeletionAddress::CHAT => $this->adapter->listMessageIdsOfChat($address->id, $portion),
			DeletionAddress::POST => $this->adapter->listMessageIdsOfPost($address->id, $address->postKey, $portion),
			DeletionAddress::AUTHOR => $this->adapter->listMessageIdsOfAuthor($address->id, $portion),
			DeletionAddress::HISTORY => $this->adapter->listMessageIdsBelow($address->id, $address->boundaryKey, $portion),
		};
	}

	/**
	 * Codes one job of a mass grant is allowed to hand out. The rest of them goes to the queue of the
	 * walk, which spends a portion of its own on every step.
	 */
	private static function getGrantBudget(): int
	{
		return max(1, (int)Option::get('im', self::GRANT_BUDGET_OPTION, self::DEFAULT_GRANT_BUDGET));
	}

	private static function getDeletionPortion(): int
	{
		return max(1, (int)Option::get('im', self::DELETION_PORTION_OPTION, self::DEFAULT_DELETION_PORTION));
	}

	/**
	 * Portions of one deletion a single unit of work is allowed to spend. Read by the walk of the queue
	 * as well: a step of it carries the addresses it can within the very same allowance.
	 */
	public static function getDeletionBudget(): int
	{
		return max(1, (int)Option::get('im', self::DELETION_BUDGET_OPTION, self::DEFAULT_DELETION_BUDGET));
	}

	/**
	 * The stored message, read at the moment its entry is written and not before.
	 *
	 * A refusal is not left at the call: the online road meets it inside a background job with nothing
	 * behind it to write the entry later, and the walk of the archive is below this message for good
	 * once its cursor has passed. The identifier goes to the queue of the walk, exactly as a refused
	 * deletion of the write path does.
	 */
	protected function indexStoredMessage(int $messageId, Chat $chat, bool $overwrite = true): void
	{
		if ($this->index(new Message($messageId), $chat, $overwrite))
		{
			return;
		}

		ChatConversionPermissionStepper::enqueueReindex($messageId);
		AgentRegistrar::registerQueueRemainder();
	}

	private function pushToDeleteBuffer(array $messageIds): void
	{
		// asked of the flush and not of the buffer: what a refused flush left in it needs a flush of its
		// own, and the one that was scheduled has already run
		if (!$this->isFlushScheduled)
		{
			$this->isFlushScheduled = true;
			$this->defer(fn () => $this->flushDeleteBuffer());
		}

		foreach ($messageIds as $messageId)
		{
			$this->deleteBuffer[] = (int)$messageId;
		}
	}

	/**
	 * Empties the buffer a portion at a time: a burst of deletions of one hit reaches the core as a
	 * single filter, and without a portion that filter is a list of every message the hit removed.
	 *
	 * What the index refused goes to the queue of the walk and not back into the buffer: the buffer lives
	 * in the process, and a refusal on the last deletion of a hit has nothing behind it to carry the rest
	 * along - an entry whose identifier is gone is reachable by no address of the queue and would stay in
	 * the index until the hygiene walk is run by hand.
	 */
	private function flushDeleteBuffer(): void
	{
		$this->isFlushScheduled = false;
		$portion = self::getDeletionPortion();

		while ($this->deleteBuffer !== [])
		{
			$messageIds = array_slice($this->deleteBuffer, 0, $portion);
			if (!$this->adapter->deleteByMessageIds($messageIds))
			{
				(new Logger(self::LOG_TAG))->log(
					'the index refused a deletion of ' . count($this->deleteBuffer)
					. ' entries, they go to the queue of the walk'
				);
				$this->handOverDeletion($this->deleteBuffer, $portion);
				$this->deleteBuffer = [];

				return;
			}

			$this->deleteBuffer = array_slice($this->deleteBuffer, count($messageIds));
		}
	}

	/**
	 * @param int[] $messageIds
	 */
	private function handOverDeletion(array $messageIds, int $portion): void
	{
		ChatConversionPermissionStepper::enqueueMessageDeletion($messageIds, $portion);
		AgentRegistrar::registerQueueRemainder();
	}
}
