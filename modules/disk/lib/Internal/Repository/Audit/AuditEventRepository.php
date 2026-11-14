<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Audit;

use Bitrix\Disk\Internal\Dto\Audit\AuditFilter;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use Bitrix\Disk\Internal\Model\AuditEventTable;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Throwable;

class AuditEventRepository
{
	public const ERROR_DUPLICATE_PAYLOAD_MISMATCH = 'DISK_AUDIT_DUPLICATE_PAYLOAD_MISMATCH';
	public const ERROR_DUPLICATE_ROW_NOT_FOUND = 'DISK_AUDIT_DUPLICATE_ROW_NOT_FOUND';
	public const ERROR_PERSISTENCE = 'DISK_AUDIT_PERSISTENCE_ERROR';

	private const EXISTING_SELECT = [
		'ID',
		'IDEMPOTENCY_KEY',
		'EVENT_CODE',
		'TARGET_TYPE',
		'TARGET_ID',
		'SUBJECT_TYPE',
		'SUBJECT_ID',
	];

	private const CANONICAL_COMPARE_FIELDS = [
		'IDEMPOTENCY_KEY',
		'EVENT_CODE',
		'TARGET_TYPE',
		'TARGET_ID',
		'SUBJECT_TYPE',
		'SUBJECT_ID',
	];

	private const LIST_SELECT = [
		'ID',
		'EVENT_CODE',
		'OCCURRED_AT',
		'STORAGE_ID',
		'STORAGE_NAME',
		'INITIATOR_TYPE',
		'INITIATOR_ID',
		'INITIATOR_NAME',
		'CHANNEL',
		'CONTEXT',
		'CONTEXT_ENTITY_TYPE',
		'CONTEXT_ENTITY_ID',
		'CONTEXT_ENTITY_NAME',
		'TARGET_TYPE',
		'TARGET_ID',
		'TARGET_NAME',
		'RESULT',
	];
	private const CARD_SELECT = [
		'IP',
		'TARGET_PATH',
		'TARGET_PARENT_ID',
		'DETAILS',
	];

	public function add(array $fields): Result
	{
		$result = new Result();

		try
		{
			$addResult = AuditEventTable::add($fields);
		}
		catch (DuplicateEntryException)
		{
			return $this->handleDuplicate($fields);
		}
		catch (Throwable $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), self::ERROR_PERSISTENCE),
			);
		}

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result->setData([
			'id' => (int)$addResult->getId(),
			'alreadyExists' => false,
		]);
	}

	public function findComparableByIdempotencyKey(string $idempotencyKey): ?array
	{
		$row = AuditEventTable::query()
			->setSelect(self::EXISTING_SELECT)
			->where('IDEMPOTENCY_KEY', $idempotencyKey)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	public function findPage(AuditFilter $filter): array
	{
		if ($this->hasEmptySelection($filter))
		{
			return [];
		}

		$query = AuditEventTable::query()
			->setSelect(self::LIST_SELECT)
			->where($this->buildFilter($filter))
			->setOrder([
				'OCCURRED_AT' => 'DESC',
				'ID' => 'DESC',
			])
			->setOffset(($filter->page - 1) * $filter->pageSize)
			->setLimit($filter->pageSize)
		;

		return $query->fetchAll();
	}

	public function count(AuditFilter $filter): int
	{
		if ($this->hasEmptySelection($filter))
		{
			return 0;
		}

		return (int)AuditEventTable::query()
			->where($this->buildFilter($filter))
			->queryCountTotal()
		;
	}

	public function findById(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = AuditEventTable::query()
			->setSelect([...self::LIST_SELECT, ...self::CARD_SELECT])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	private function buildFilter(AuditFilter $filter): ConditionTree
	{
		$condition = Query::filter();

		if ($filter->from !== null)
		{
			$condition->where('OCCURRED_AT', '>=', $filter->from);
		}
		if ($filter->to !== null)
		{
			$condition->where('OCCURRED_AT', '<=', $filter->to);
		}

		$this->applyInitiatorFilter($condition, $filter);
		$this->applyArrayFilter($condition, 'EVENT_CODE', $filter->eventCode);
		$this->applyArrayFilter($condition, 'TARGET_TYPE', $filter->targetType);
		$this->applyArrayFilter($condition, 'STORAGE_ID', $filter->storageId);
		$this->applyArrayFilter($condition, 'CHANNEL', $filter->channel);
		$this->applyArrayFilter($condition, 'RESULT', $filter->result);
		$this->applySearchFilter($condition, $filter->search);

		return $condition;
	}

	private function applyInitiatorFilter(ConditionTree $condition, AuditFilter $filter): void
	{
		if ($filter->initiator === null)
		{
			return;
		}

		$initiatorCondition = Query::filter()->logic('or');
		foreach ($filter->initiator as $initiator)
		{
			$itemCondition = Query::filter()->where('INITIATOR_TYPE', $initiator->type);
			if ($initiator->id !== null)
			{
				$itemCondition->where('INITIATOR_ID', $initiator->id);
			}

			$initiatorCondition->where($itemCondition);
		}

		$condition->where($initiatorCondition);
	}

	private function applyArrayFilter(ConditionTree $condition, string $field, ?array $values): void
	{
		if ($values !== null)
		{
			$condition->whereIn($field, $values);
		}
	}

	private function applySearchFilter(ConditionTree $condition, ?string $search): void
	{
		if ($search === null)
		{
			return;
		}

		if (preg_match('/^[0-9]+$/D', $search) === 1)
		{
			$condition
				->whereIn('TARGET_TYPE', [AuditTarget::TYPE_FILE, AuditTarget::TYPE_FOLDER])
				->where('TARGET_ID', $search)
			;

			return;
		}

		$condition->whereLike('TARGET_NAME_SEARCH', $search . '%');
	}

	private function hasEmptySelection(AuditFilter $filter): bool
	{
		return $filter->initiator === []
			|| $filter->eventCode === []
			|| $filter->targetType === []
			|| $filter->storageId === []
			|| $filter->channel === []
			|| $filter->result === [];
	}

	private function handleDuplicate(array $fields): Result
	{
		$result = new Result();
		$idempotencyKey = (string)($fields['IDEMPOTENCY_KEY'] ?? '');
		$existing = $this->findComparableByIdempotencyKey($idempotencyKey);

		if ($existing === null)
		{
			return $result->addError(
				new Error('Duplicate audit event row was not found.', self::ERROR_DUPLICATE_ROW_NOT_FOUND),
			);
		}

		if (!$this->isSameCanonicalPayload($fields, $existing))
		{
			return $result->addError(
				new Error('Duplicate audit event payload does not match stored row.', self::ERROR_DUPLICATE_PAYLOAD_MISMATCH),
			);
		}

		return $result->setData([
			'id' => (int)$existing['ID'],
			'alreadyExists' => true,
		]);
	}

	private function isSameCanonicalPayload(array $fields, array $existing): bool
	{
		foreach (self::CANONICAL_COMPARE_FIELDS as $fieldName)
		{
			if (!$this->isSameValue($fields[$fieldName] ?? null, $existing[$fieldName] ?? null))
			{
				return false;
			}
		}

		return true;
	}

	private function isSameValue(mixed $expected, mixed $actual): bool
	{
		if ($expected === null || $actual === null)
		{
			return $expected === null && $actual === null;
		}

		return (string)$expected === (string)$actual;
	}
}
