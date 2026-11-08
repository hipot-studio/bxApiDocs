<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentResourcePayload;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Model\StorageRecordDataTable;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepositoryInterface;

/**
 * Cleanup participant of the storage data owned by a managed system AI agent instance.
 *
 * The storage type is a shared container that other agents and manual templates keep using, therefore it is
 * never deleted here: one service row of this type stands for the whole scope of the copy, and the actual
 * data are the rows of b_bp_storage_record_data that carry the TEMPLATE_ID of that copy. The RESOURCE_ID is
 * that TEMPLATE_ID, so a single row describes an unbounded amount of data instead of duplicating it in the
 * registry.
 *
 * A batch first reads the ordered ids over the (TEMPLATE_ID, ID) key and then deletes all of them by primary
 * key in one statement, so the speed of the drain is set by the budget of the pass and not by the number of
 * round trips to the database. The MySQL specific DELETE ... LIMIT is deliberately not used, because the schema
 * has to stay portable to PostgreSQL. This participant is the one that runs into the limit of the pass most
 * often, so a pending result with data still left is its normal answer and not a sign of a stuck dependency.
 *
 * The batch is deleted from the table directly and not through
 * {@see \Bitrix\Bizproc\Public\Command\StorageItem\DeleteStorageItemCommandHandler}, which is what marks a
 * deleted row for the recalculation of an analytical view while the data-view feature is on. A row of a copy never
 * needs that mark, by two independent properties of the schema: the mark is put on rows whose WORKFLOW_ID
 * is one of the two markers of a view ({@see \Bitrix\Bizproc\Internal\Entity\DataView\RowIdentity}), while a
 * row a copy writes carries the id of the workflow that wrote it; and the rows of a view are written with
 * TEMPLATE_ID = 0, therefore they stay outside the selection of this participant, which is the positive
 * template id of the copy. The one writer that could put both on a single row is the update mode of
 * CBPWriteDataStorageActivity over the result storage of a view, and the agents this module ships write new
 * rows only.
 *
 * The budget is charged for the rows of the batch while their ids are read, and the delete of the whole batch
 * is charged one unit, because it is one statement over rows the pass has already paid for. Reading the ids is
 * therefore what bounds the pass, and one pass performs exactly one batch: the drain of one instance is about
 * {@see ManagedResourceCleanupBudget::DEFAULT_ROW_LIMIT} rows per pass, which the background continuation
 * repeats every {@see \Bitrix\Bizproc\Infrastructure\Agent\ManagedSystemAiAgentCleanupAgent::PERIOD_SECONDS}
 * seconds - around 144 thousand rows a day. A scope that is larger than that is drained faster by a larger row
 * budget of the pass and not by a larger batch.
 */
final class StorageScopeCleanup implements ManagedResourceCleanupInterface
{
	/**
	 * Own deadline of this participant: the pass as a whole lasts five seconds and is shared by five types.
	 */
	public const DEFAULT_DEADLINE_SECONDS = 1.0;

	/**
	 * Largest batch of storage rows one call deletes, additionally bounded by the rows the pass has left.
	 *
	 * At the delivered values this ceiling never binds: the row budget of the pass is the same number and the
	 * ids of the batch reach it first. It bounds the size of one statement, and lowering it can only make the
	 * drain slower, because a pass performs one batch either way.
	 */
	public const DEFAULT_BATCH_LIMIT = 500;

	public const ERROR_DATA_INVALID = 'AI_AGENT_STORAGE_DATA_INVALID';

	public const ERROR_RESOURCE_ID_INVALID = 'AI_AGENT_STORAGE_TEMPLATE_INVALID';

	public const ERROR_OWNER_UNREADABLE = 'AI_AGENT_STORAGE_OWNER_UNREADABLE';

	public const ERROR_READ_FAILED = 'AI_AGENT_STORAGE_READ_FAILED';

	public const ERROR_WRITE_FAILED = 'AI_AGENT_STORAGE_WRITE_FAILED';

	public const ERROR_DELETE_FAILED = 'AI_AGENT_STORAGE_DELETE_FAILED';

	/**
	 * A bounded batch is deleted while rows of the copy are still there, hence the next pass continues.
	 */
	public const REASON_ROWS_REMAIN = 'AI_AGENT_STORAGE_ROWS_REMAIN';

	private const TEMPLATE_ID_PATTERN = '/^[1-9][0-9]{0,17}$/D';

	private readonly ?ManagedAgentInstanceRepositoryInterface $instanceRepository;

	private readonly ?ManagedAgentResourceRepositoryInterface $resourceRepository;

	public function __construct(
		?ManagedAgentInstanceRepositoryInterface $instanceRepository = null,
		?ManagedAgentResourceRepositoryInterface $resourceRepository = null,
		private readonly float $deadlineSeconds = self::DEFAULT_DEADLINE_SECONDS,
		private readonly int $batchLimit = self::DEFAULT_BATCH_LIMIT,
	)
	{
		$this->instanceRepository = $instanceRepository ?? Container::getManagedAgentInstanceRepository();
		$this->resourceRepository = $resourceRepository ?? Container::getManagedAgentResourceRepository();
	}

	public function type(): ManagedAgentResourceType
	{
		return ManagedAgentResourceType::StorageScope;
	}

	public function reconcile(
		ManagedAgentInstance $instance,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$budget->startParticipantDeadline($this->type(), $this->deadlineSeconds);

		if ($this->resourceRepository === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
		}

		$instanceId = (int)$instance->getId();
		if ($instanceId <= 0)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_OWNER_UNREADABLE);
		}

		$templateId = $instance->getTemplateId();
		if ($templateId === null)
		{
			// Without a copy no scope of the instance can exist; a stale row is confirmed by cleanup().
			return ManagedResourceCleanupResult::createComplete();
		}

		try
		{
			$present = self::readRecordIds($templateId, 1) !== [];
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		$budget->consumeRows(1);

		if (!$present)
		{
			// The copy owns no storage data, so the scope needs no service row of its own.
			return ManagedResourceCleanupResult::createComplete();
		}

		try
		{
			$this->adopt($instanceId, (string)$templateId, $budget);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
		}

		return ManagedResourceCleanupResult::createComplete();
	}

	public function cleanup(
		ManagedAgentResource $resource,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$budget->startParticipantDeadline($this->type(), $this->deadlineSeconds);

		if (!ManagedAgentResourcePayload::matchesSchema($resource->getData(), $this->type()))
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DATA_INVALID);
		}

		$templateId = self::parseTemplateId($resource->getResourceId());
		if ($templateId === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_RESOURCE_ID_INVALID);
		}

		if ($this->instanceRepository === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_OWNER_UNREADABLE);
		}

		try
		{
			$ownedTemplateId = $this->instanceRepository->getById($resource->getInstanceId())?->getTemplateId();
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if ($ownedTemplateId === null || $ownedTemplateId !== $templateId)
		{
			// Ownership of this row is not confirmed, so the data of another template stay untouched.
			return ManagedResourceCleanupResult::createComplete();
		}

		return $this->deleteRecordBatch($templateId, $budget);
	}

	/**
	 * Deletes one bounded batch of the storage rows of the copy, keeping the shared storage type itself.
	 */
	private function deleteRecordBatch(
		int $templateId,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		$limit = min($this->batchLimit, $budget->getRemainingRows());
		if ($limit < 1)
		{
			return ManagedResourceCleanupResult::createPending();
		}

		try
		{
			$recordIds = self::readRecordIds($templateId, $limit);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		$budget->consumeRows(count($recordIds));

		if ($recordIds === [])
		{
			return ManagedResourceCleanupResult::createComplete();
		}

		try
		{
			// The whole batch is one statement, so the drain is bounded by the budget of the pass and not by
			// the number of round trips to the database. The ids are already read, therefore the filter needs
			// no LIMIT and stays portable to PostgreSQL.
			StorageRecordDataTable::deleteByFilter(['@ID' => $recordIds]);
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DELETE_FAILED);
		}

		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending(self::REASON_ROWS_REMAIN);
		}

		try
		{
			$remaining = self::readRecordIds($templateId, 1) !== [];
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		$budget->consumeRows(1);

		// A portion that was really removed and a batch that removed nothing carry the same code here, so the
		// outcome deliberately charges no delay: a large volume must not be stretched by one portion a pass.
		return $remaining
			? ManagedResourceCleanupResult::createPending(self::REASON_ROWS_REMAIN)
			: ManagedResourceCleanupResult::createComplete()
		;
	}

	/**
	 * Registers the scope of the copy that has no ownership row yet, which closes the window between the
	 * binding of the copy and its registration.
	 */
	private function adopt(int $instanceId, string $resourceId, ManagedResourceCleanupBudget $budget): void
	{
		$stored = $this->resourceRepository->findByLogicalKey($instanceId, $this->type(), $resourceId);
		$budget->consumeRows(1);

		if ($stored !== null)
		{
			return;
		}

		$this->resourceRepository->save(
			new ManagedAgentResource(null, $instanceId, $this->type(), $resourceId, ManagedAgentResourcePayload::build()),
		);
		$budget->consumeRows(1);
	}

	/**
	 * @return list<int> ids of the storage rows of the copy, ordered so that repeated batches make progress
	 */
	private static function readRecordIds(int $templateId, int $limit): array
	{
		$rows = StorageRecordDataTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	private static function parseTemplateId(string $resourceId): ?int
	{
		return preg_match(self::TEMPLATE_ID_PATTERN, $resourceId) === 1 ? (int)$resourceId : null;
	}
}
