<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message\Conversation;

use Bitrix\Mail\Internal\Repository\ConversationProjectionException;
use Bitrix\Mail\Internal\Repository\MessageConversationRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Projects the closure of a mailbox onto conversations: one conversation per connected component of
 * messages, one conversation per message.
 *
 * Transaction contract: every public method owns its transaction and must not be called from inside
 * another one. A nested transaction of Bitrix is a savepoint whose rollback is reported as an error
 * ("Nested rollbacks are unsupported"), which would break the bounded retry of {@see self::index()};
 * and the transaction level of the connection is not readable, so an outer transaction cannot be
 * detected here.
 */
class ConversationIndexer
{
	public const ERROR_PROJECTION_FAILED = 'MAIL_CONVERSATION_PROJECTION_FAILED';
	public const ERROR_UNIQUE_CONFLICT = 'MAIL_CONVERSATION_UNIQUE_CONFLICT';
	public const ERROR_MESSAGE_NOT_REMOVED = 'MAIL_CONVERSATION_MESSAGE_NOT_REMOVED';

	private const MAX_WRITE_ATTEMPTS = 3;
	public const MAX_PARTICIPANTS_PER_CALL = 100;

	public function __construct(
		private readonly MessageConversationRepository $repository = new MessageConversationRepository(),
		private readonly ConversationDiagnostics $diagnostics = new ConversationDiagnostics(),
	)
	{
	}

	/**
	 * Incrementally joins the message to its projected neighbours. One call moves at most
	 * {@see self::MAX_PARTICIPANTS_PER_CALL} existing members; repeating calls converge on the same
	 * canonical conversation without materializing the connected component in PHP.
	 *
	 * `getData()` holds `conversationId`, `complete`, `merged` and `processed`. `complete=false` asks
	 * the background pass to repeat the message; `merged=true` asks it to make another stable pass.
	 *
	 * Must not be called from inside a transaction, see the class contract.
	 * `getData()` holds `rebuildRequired`: the caller must suspend grouping and bind the background
	 * pass when the affected conversation was too large to split inline.
	 */
	public function index(
		int $messageId,
		int $mailboxId,
		int $participantLimit = self::MAX_PARTICIPANTS_PER_CALL,
	): Result
	{
		$participantLimit = max(1, min($participantLimit, self::MAX_PARTICIPANTS_PER_CALL));
		$result = new Result();
		$lastConflict = null;
		for ($attempt = 1; $attempt <= self::MAX_WRITE_ATTEMPTS; $attempt++)
		{
			if (!$this->repository->isMessageStored($messageId, $mailboxId))
			{
				return $result->setData([
					'conversationId' => null,
					'complete' => true,
					'merged' => false,
					'processed' => 0,
				]);
			}

			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				$projection = $this->mergeAdjacentConversations($messageId, $mailboxId, $participantLimit);
				$connection->commitTransaction();

				return $result->setData($this->publicProjection($projection));
			}
			catch (DuplicateEntryException $exception)
			{
				// A parallel synchronization claimed one of the messages; the component is read again.
				$connection->rollbackTransaction();
				$lastConflict = $exception;
			}
			catch (ConversationProjectionException|SqlQueryException $exception)
			{
				$connection->rollbackTransaction();

				return $result->addError(
					new Error($exception->getMessage(), self::ERROR_PROJECTION_FAILED),
				);
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();

				throw $exception;
			}
		}

		return $result->addError(new Error($lastConflict->getMessage(), self::ERROR_UNIQUE_CONFLICT));
	}

	/**
	 * Projects one bounded backfill portion in one transaction.
	 *
	 * @param int[] $messageIds
	 */
	public function indexMany(
		array $messageIds,
		int $mailboxId,
		int $participantLimit = self::MAX_PARTICIPANTS_PER_CALL,
	): Result
	{
		$messageIds = array_values(array_unique(array_filter(
			array_map('intval', $messageIds),
			static fn(int $messageId): bool => $messageId > 0,
		)));
		$participantLimit = max(1, min($participantLimit, self::MAX_PARTICIPANTS_PER_CALL));
		$messageIds = array_slice($messageIds, 0, $participantLimit);
		$result = new Result();
		if ($messageIds === [])
		{
			return $result->setData(['items' => [], 'processed' => 0]);
		}

		$lastConflict = null;
		for ($attempt = 1; $attempt <= self::MAX_WRITE_ATTEMPTS; $attempt++)
		{
			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				$storedMessageIds = $this->repository->findStoredMessageIds($messageIds, $mailboxId);
				$storedMessageLookup = array_fill_keys($storedMessageIds, true);
				$knownMemberships = $this->repository->findMemberships($storedMessageIds);
				$adjacentCandidates = $this->repository->findAdjacentMembershipCandidates(
					$storedMessageIds,
					$mailboxId,
				);
				$batchRelations = $this->repository->findRelationsAmongMessages($storedMessageIds);
				$items = [];
				$processed = 0;
				$activityMessageIds = [];
				foreach ($messageIds as $messageId)
				{
					$remainingBudget = $participantLimit - $processed;
					if ($remainingBudget <= 0)
					{
						break;
					}

					$membership = $knownMemberships[$messageId] ?? null;
					$batchAdjacent = $this->batchAdjacentMembership(
						$messageId,
						$membership,
						$adjacentCandidates,
						$batchRelations,
						$knownMemberships,
						$mailboxId,
					);
					$projection = isset($storedMessageLookup[$messageId])
						? $this->mergeAdjacentConversations(
							$messageId,
							$mailboxId,
							$remainingBudget,
							knownMembership: $membership,
							membershipIsKnown: true,
							refreshActivities: false,
							knownAdjacent: $batchAdjacent['membership'],
							adjacencyIsKnown: true,
							knownAdditionalAdjacency: $batchAdjacent['hasAnother'],
						)
						: [
							'conversationId' => null,
							'complete' => true,
							'merged' => false,
							'processed' => 0,
							'affectedConversationIds' => [],
							'activityMessageIds' => [],
							'conversationRedirect' => null,
						];
					foreach ($projection['activityMessageIds'] as $activityMessageId)
					{
						$activityMessageIds[$activityMessageId] = true;
					}
					if ($membership === null && $projection['conversationId'] !== null)
					{
						$knownMemberships[$messageId] = [
							'conversationId' => $projection['conversationId'],
							'mailboxId' => $mailboxId,
						];
					}
					if ($projection['conversationRedirect'] !== null)
					{
						[$sourceId, $targetId] = $projection['conversationRedirect'];
						$this->redirectBatchConversation(
							$sourceId,
							$targetId,
							$knownMemberships,
							$adjacentCandidates,
						);
					}
					$items[] = ['messageId' => $messageId, ...$this->publicProjection($projection)];
					$processed += $projection['processed'];
					if (!$projection['complete'])
					{
						break;
					}
				}
				$this->repository->updateConversationActivitiesForMessages(
					array_keys($activityMessageIds),
					$mailboxId,
				);

				$connection->commitTransaction();

				return $result->setData(['items' => $items, 'processed' => $processed]);
			}
			catch (DuplicateEntryException $exception)
			{
				$connection->rollbackTransaction();
				$lastConflict = $exception;
			}
			catch (ConversationProjectionException|SqlQueryException $exception)
			{
				$connection->rollbackTransaction();

				return $result->addError(
					new Error($exception->getMessage(), self::ERROR_PROJECTION_FAILED),
				);
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();

				throw $exception;
			}
		}

		return $result->addError(new Error($lastConflict->getMessage(), self::ERROR_UNIQUE_CONFLICT));
	}

	/**
	 * Drops the message from its conversation and splits what is left into the components it really
	 * consists of. The old identifier stays with the component holding the newest message; the other
	 * components get new conversations, and an emptied conversation is deleted.
	 *
	 * Precondition: the message row is already gone from `b_mail_message`. While it is stored, it
	 * still connects the closure and the remaining messages would be read back as one component.
	 *
	 * Must not be called from inside a transaction, see the class contract.
	 */
	public function remove(int $messageId, int $mailboxId): Result
	{
		$result = new Result();
		if ($this->repository->isMessageStored($messageId, $mailboxId))
		{
			return $result->addError(new Error(
				'A conversation member is removed only after the message row itself is deleted.',
				self::ERROR_MESSAGE_NOT_REMOVED,
			));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$rebuildRequired = $this->splitConversationOf($messageId, $mailboxId);
			$connection->commitTransaction();

			return $result->setData(['rebuildRequired' => $rebuildRequired]);
		}
		catch (ConversationProjectionException|SqlQueryException $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(new Error($exception->getMessage(), self::ERROR_PROJECTION_FAILED));
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	/**
	 * Drops a batch of members and rebuilds every affected conversation once.
	 *
	 * @param int[] $messageIds
	 */
	public function removeMany(array $messageIds, int $mailboxId): Result
	{
		$messageIds = array_values(array_unique(array_filter(
			array_map('intval', $messageIds),
			static fn(int $messageId): bool => $messageId > 0,
		)));
		$result = new Result();
		if ($messageIds === [])
		{
			return $result->setData(['removed' => 0, 'rebuildRequired' => false]);
		}

		if ($this->repository->findStoredMessageIds($messageIds, $mailboxId) !== [])
		{
			return $result->addError(new Error(
				'Conversation members are removed only after their message rows are deleted.',
				self::ERROR_MESSAGE_NOT_REMOVED,
			));
		}

		$memberships = $this->repository->findMemberships($messageIds);
		$conversationIds = [];
		foreach ($memberships as $membership)
		{
			if ($membership['mailboxId'] !== $mailboxId)
			{
				return $result->addError(new Error(
					'A message cannot be removed from a conversation in another mailbox.',
					self::ERROR_PROJECTION_FAILED,
				));
			}
			$conversationIds[$membership['conversationId']] = true;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->repository->deleteMembers(array_keys($memberships), $mailboxId);
			$rebuildRequired = false;
			foreach (array_keys($conversationIds) as $conversationId)
			{
				$rebuildRequired = $this->rebuildConversation($conversationId, $mailboxId) || $rebuildRequired;
			}
			$connection->commitTransaction();

			return $result->setData([
				'removed' => count($memberships),
				'rebuildRequired' => $rebuildRequired,
			]);
		}
		catch (ConversationProjectionException|SqlQueryException $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(new Error($exception->getMessage(), self::ERROR_PROJECTION_FAILED));
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	/**
	 * Rebuilds only activity rows for placement changes that do not alter conversation membership.
	 *
	 * @param int[] $messageIds
	 */
	public function refreshActivitiesForMessages(array $messageIds, int $mailboxId): Result
	{
		$messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
		$result = new Result();
		if ($messageIds === [])
		{
			return $result;
		}

		$conversationIds = [];
		foreach ($this->repository->findMemberships($messageIds) as $membership)
		{
			if ($membership['mailboxId'] !== $mailboxId)
			{
				return $result->addError(new Error(
					'A placement cannot refresh a conversation in another mailbox.',
					self::ERROR_PROJECTION_FAILED,
				));
			}
			$conversationIds[$membership['conversationId']] = true;
		}
		if ($conversationIds === [])
		{
			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->repository->incrementConversationRevisions(array_keys($conversationIds), $mailboxId);
			$this->repository->refreshConversationActivities(array_keys($conversationIds), $mailboxId);
			$connection->commitTransaction();

			return $result;
		}
		catch (ConversationProjectionException|SqlQueryException $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(new Error($exception->getMessage(), self::ERROR_PROJECTION_FAILED));
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	/**
	 * @param array{conversationId: int, mailboxId: int}|null $knownMembership
	 * @return array{conversationId: int, complete: bool, merged: bool, processed: int,
	 *     affectedConversationIds: int[], activityMessageIds: int[], conversationRedirect: array{int, int}|null}
	 * @throws ConversationProjectionException
	 */
	private function mergeAdjacentConversations(
		int $messageId,
		int $mailboxId,
		int $participantLimit,
		?array $knownMembership = null,
		bool $membershipIsKnown = false,
		bool $refreshActivities = true,
		?array $knownAdjacent = null,
		bool $adjacencyIsKnown = false,
		bool $knownAdditionalAdjacency = false,
	): array
	{
		$membership = $membershipIsKnown ? $knownMembership : $this->repository->findMembership($messageId);
		if ($membership !== null && $membership['mailboxId'] !== $mailboxId)
		{
			throw new ConversationProjectionException('A message cannot be merged across mailboxes.');
		}

		$now = new DateTime();
		$currentConversationId = $membership['conversationId'] ?? null;
		$adjacent = $adjacencyIsKnown
			? $knownAdjacent
			: $this->repository->findAdjacentMembership($messageId, $mailboxId, $currentConversationId);
		if ($currentConversationId === null)
		{
			$conversationId = $adjacent === null
				? $this->repository->createConversation($mailboxId, $now)
				: $adjacent['conversationId'];
			if (!$adjacencyIsKnown)
			{
				$this->repository->assertConversationsBelongToMailbox([$conversationId], $mailboxId);
			}
			$written = $membershipIsKnown
				? $this->repository->upsertMembersWithKnownMemberships(
					[$messageId],
					$conversationId,
					$mailboxId,
					$now,
					$membership === null ? [] : [$messageId => $membership],
				)
				: $this->repository->upsertMembers([$messageId], $conversationId, $mailboxId, $now);
			if ($adjacent !== null && $written > 0)
			{
				$this->repository->incrementConversationRevisions([$conversationId], $mailboxId);
			}
			if ($refreshActivities)
			{
				$this->repository->updateConversationActivitiesForMessages([$messageId], $mailboxId);
			}

			return [
				'conversationId' => $conversationId,
				'complete' => $adjacencyIsKnown
					? !$knownAdditionalAdjacency
					: $this->repository->findAdjacentMembership(
						$messageId,
						$mailboxId,
						$conversationId,
					) === null,
				'merged' => false,
				'processed' => 1,
				'affectedConversationIds' => [$conversationId],
				'activityMessageIds' => [$messageId],
				'conversationRedirect' => null,
			];
		}

		if ($adjacent === null)
		{
			if ($refreshActivities)
			{
				$this->repository->updateConversationActivitiesForMessages([$messageId], $mailboxId);
			}

			return [
				'conversationId' => $currentConversationId,
				'complete' => true,
				'merged' => false,
				'processed' => 1,
				'affectedConversationIds' => [$currentConversationId],
				'activityMessageIds' => [$messageId],
				'conversationRedirect' => null,
			];
		}

		$adjacentConversationId = $adjacent['conversationId'];
		if (!$membershipIsKnown || !$adjacencyIsKnown)
		{
			$this->repository->assertConversationsBelongToMailbox(
				[$currentConversationId, $adjacentConversationId],
				$mailboxId,
			);
		}
		$canonicalId = min($currentConversationId, $adjacentConversationId);
		$sourceId = max($currentConversationId, $adjacentConversationId);
		$move = $this->repository->moveConversationMembers(
			$sourceId,
			$canonicalId,
			$mailboxId,
			$now,
			$participantLimit,
		);
		$sourceDeleted = $this->repository->deleteConversationIfEmpty($sourceId, $mailboxId);
		if ($move['moved'] > 0)
		{
			$changedConversationIds = [$canonicalId];
			if (!$sourceDeleted)
			{
				$changedConversationIds[] = $sourceId;
			}
			$this->repository->incrementConversationRevisions($changedConversationIds, $mailboxId);
		}
		$activityMessageIds = array_values(array_unique([$messageId, ...$move['messageIds']]));
		if ($refreshActivities)
		{
			$this->repository->updateConversationActivitiesForMessages($activityMessageIds, $mailboxId);
		}

		return [
			'conversationId' => $canonicalId,
			'complete' => $sourceDeleted,
			'merged' => true,
			'processed' => max(1, $move['moved']),
			'affectedConversationIds' => [$canonicalId, $sourceId],
			'activityMessageIds' => $activityMessageIds,
			'conversationRedirect' => $sourceDeleted ? [$sourceId, $canonicalId] : null,
		];
	}

	/** @throws ConversationProjectionException */
	private function splitConversationOf(int $messageId, int $mailboxId): bool
	{
		$membership = $this->repository->findMembership($messageId);
		if ($membership === null)
		{
			return false;
		}
		if ($membership['mailboxId'] !== $mailboxId)
		{
			throw new ConversationProjectionException(
				'A message cannot be removed from a conversation in another mailbox.',
			);
		}

		$conversationId = $membership['conversationId'];
		$this->repository->assertConversationsBelongToMailbox([$conversationId], $mailboxId);
		$this->repository->deleteMember($messageId);

		return $this->rebuildConversation($conversationId, $mailboxId);
	}

	/** @throws ConversationProjectionException */
	private function rebuildConversation(int $conversationId, int $mailboxId): bool
	{
		$this->repository->assertConversationsBelongToMailbox([$conversationId], $mailboxId);

		$remainingMessageIds = $this->repository->findConversationMessageIds(
			$conversationId,
			$mailboxId,
			self::MAX_PARTICIPANTS_PER_CALL + 1,
		);
		if ($remainingMessageIds === [])
		{
			$this->repository->deleteConversationIfEmpty($conversationId, $mailboxId);

			return false;
		}
		if (count($remainingMessageIds) > self::MAX_PARTICIPANTS_PER_CALL)
		{
			$this->diagnostics->conversationRebuildRequired(
				$mailboxId,
				$conversationId,
				self::MAX_PARTICIPANTS_PER_CALL,
			);
			$this->repository->deleteConversationProjection($conversationId, $mailboxId);

			return true;
		}

		$components = $this->partitionMessageIds($remainingMessageIds, $mailboxId);
		$retainedComponentIndex = $this->findNewestComponentIndex($components, $mailboxId);
		$now = new DateTime();
		$affectedConversationIds = [$conversationId];
		foreach ($components as $index => $componentIds)
		{
			if ($index === $retainedComponentIndex)
			{
				// Its members already point at this conversation; only its composition has changed.
				$this->repository->incrementConversationRevisions([$conversationId], $mailboxId);

				continue;
			}

			$newConversationId = $this->repository->createConversation($mailboxId, $now);
			$this->repository->upsertMembers(
				$componentIds,
				$newConversationId,
				$mailboxId,
				$now,
			);
			$affectedConversationIds[] = $newConversationId;
		}
		$this->repository->refreshConversationActivities($affectedConversationIds, $mailboxId);

		return false;
	}

	/**
	 * @param array{conversationId: int|null, complete: bool, merged: bool, processed: int} $projection
	 * @return array{conversationId: int|null, complete: bool, merged: bool, processed: int}
	 */
	private function publicProjection(array $projection): array
	{
		return [
			'conversationId' => $projection['conversationId'],
			'complete' => $projection['complete'],
			'merged' => $projection['merged'],
			'processed' => $projection['processed'],
		];
	}

	/**
	 * @param array{conversationId: int, mailboxId: int}|null $membership
	 * @param array<int, int[]> $adjacentCandidates
	 * @param array<int, int[]> $batchRelations
	 * @param array<int, array{conversationId: int, mailboxId: int}> $knownMemberships
	 * @return array{membership: array{conversationId: int, mailboxId: int}|null, hasAnother: bool}
	 */
	private function batchAdjacentMembership(
		int $messageId,
		?array $membership,
		array $adjacentCandidates,
		array $batchRelations,
		array $knownMemberships,
		int $mailboxId,
	): array
	{
		$currentConversationId = $membership['conversationId'] ?? null;
		$candidates = [];
		foreach ($adjacentCandidates[$messageId] ?? [] as $conversationId)
		{
			if ($conversationId !== $currentConversationId)
			{
				$candidates[$conversationId] = true;
			}
		}
		foreach ($batchRelations[$messageId] ?? [] as $relatedMessageId)
		{
			$conversationId = $knownMemberships[$relatedMessageId]['conversationId'] ?? null;
			if ($conversationId !== null && $conversationId !== $currentConversationId)
			{
				$candidates[$conversationId] = true;
			}
		}

		$conversationIds = array_keys($candidates);
		sort($conversationIds, SORT_NUMERIC);
		$conversationId = $conversationIds[0] ?? null;

		return [
			'membership' => $conversationId === null
				? null
				: ['conversationId' => $conversationId, 'mailboxId' => $mailboxId],
			'hasAnother' => count($conversationIds) > 1,
		];
	}

	/**
	 * @param array<int, array{conversationId: int, mailboxId: int}> $knownMemberships
	 * @param array<int, int[]> $adjacentCandidates
	 */
	private function redirectBatchConversation(
		int $sourceId,
		int $targetId,
		array &$knownMemberships,
		array &$adjacentCandidates,
	): void
	{
		foreach ($knownMemberships as &$knownMembership)
		{
			if ($knownMembership['conversationId'] === $sourceId)
			{
				$knownMembership['conversationId'] = $targetId;
			}
		}
		unset($knownMembership);

		foreach ($adjacentCandidates as &$conversationIds)
		{
			foreach ($conversationIds as &$conversationId)
			{
				if ($conversationId === $sourceId)
				{
					$conversationId = $targetId;
				}
			}
			unset($conversationId);
			$conversationIds = array_values(array_unique($conversationIds));
		}
		unset($conversationIds);
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, int[]>
	 */
	private function partitionMessageIds(array $messageIds, int $mailboxId): array
	{
		$unassigned = array_fill_keys($messageIds, true);
		$components = [];
		while ($unassigned !== [])
		{
			$anchorId = (int)array_key_first($unassigned);
			$connectedIds = $this->repository->findConnectedMessageIds(
				$anchorId,
				$mailboxId,
				self::MAX_PARTICIPANTS_PER_CALL,
			);
			$component = [];
			foreach ($connectedIds as $connectedId)
			{
				if (isset($unassigned[$connectedId]))
				{
					$component[] = $connectedId;
					unset($unassigned[$connectedId]);
				}
			}
			if ($component === [])
			{
				$component[] = $anchorId;
				unset($unassigned[$anchorId]);
			}
			sort($component, SORT_NUMERIC);
			$components[] = $component;
		}

		return $components;
	}

	/** @param array<int, int[]> $components */
	private function findNewestComponentIndex(array $components, int $mailboxId): int
	{
		$retainedIndex = 0;
		$retainedKey = $this->repository->findNewestMessageKey($components[0], $mailboxId);
		foreach ($components as $index => $component)
		{
			if ($index === 0)
			{
				continue;
			}

			$key = $this->repository->findNewestMessageKey($component, $mailboxId);
			if (
				$key['timestamp'] > $retainedKey['timestamp']
				|| (
					$key['timestamp'] === $retainedKey['timestamp']
					&& $key['messageId'] < $retainedKey['messageId']
				)
			)
			{
				$retainedIndex = $index;
				$retainedKey = $key;
			}
		}

		return $retainedIndex;
	}
}
