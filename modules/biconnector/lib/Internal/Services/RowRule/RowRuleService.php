<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

use Bitrix\BIConnector\Internal\Entity\RowRule;
use Bitrix\BIConnector\Internal\Repository\Mapper\RowRuleMapper;
use Bitrix\BIConnector\Internal\Repository\RowRuleRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Row rules: reading in the model of the API, creating, changing and deleting, the state of a rule.
 *
 * Any holder of the right may change any rule; the last saved change wins, rules have no version.
 * Each call reads the table descriptions anew, so a rule is checked and shown by the current catalog.
 */
final class RowRuleService
{
	public const STATE_OK = 'OK';
	public const STATE_TABLE_MISSING = 'TABLE_MISSING';
	public const STATE_FIELD_MISSING = 'FIELD_MISSING';
	public const STATE_FIELD_CHANGED = 'FIELD_CHANGED';

	private readonly RowRuleRepository $repository;
	private readonly RuleValueCanonizer $canonizer;
	private readonly RowRulePresenter $presenter;

	/**
	 * @param \Closure|null $tableLoader Loader of table descriptions for FieldDescriptorReader;
	 *     the catalog of BI Builder when null.
	 * @param RowRuleRepository|null $repository
	 * @param RuleValueCanonizer|null $canonizer
	 * @param RowRulePresenter|null $presenter
	 */
	public function __construct(
		private readonly ?\Closure $tableLoader = null,
		?RowRuleRepository $repository = null,
		?RuleValueCanonizer $canonizer = null,
		?RowRulePresenter $presenter = null,
	)
	{
		$this->repository = $repository ?? new RowRuleRepository(new RowRuleMapper());
		$this->canonizer = $canonizer ?? new RuleValueCanonizer();
		$this->presenter = $presenter ?? new RowRulePresenter();
	}

	/**
	 * @param array $filter Filter of the repository: `tableCode`, `createdById`.
	 * @param array $order `field` and `direction`.
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return array{items: array[], total: int} Models of the API.
	 */
	public function getList(array $filter, array $order, int $offset, int $limit): array
	{
		return [
			'items' => $this->present($this->repository->findList($filter, $order, $offset, $limit)),
			'total' => $this->repository->count($filter),
		];
	}

	/**
	 * @param int $id
	 *
	 * @return Result Data: `rule` - the model of the API; ROW_RULE_NOT_FOUND when there is no such rule.
	 */
	public function get(int $id): Result
	{
		$rule = $id > 0 ? $this->repository->getById($id) : null;
		if (!$rule instanceof RowRule)
		{
			return $this->createNotFoundResult($id);
		}

		[$model] = $this->present([$rule]);

		return (new Result())->setData(['rule' => $model]);
	}

	/**
	 * @param RowRule[] $rules
	 *
	 * @return array[] Models of the API in the order of the rules.
	 */
	public function present(array $rules): array
	{
		$reader = $this->createReader();

		return $this->presenter->present(
			$rules,
			$reader,
			fn(RowRule $rule): string => $this->resolveState($rule, $reader),
		);
	}

	/**
	 * State of a rule by the current table description; it is computed on each output and never stored.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public function getState(RowRule $rule): string
	{
		return $this->resolveState($rule, $this->createReader());
	}

	/**
	 * @param array $fields Fields of the API `add` request.
	 * @param int $userId Author of the rule.
	 *
	 * @return Result Data: `rule` - the saved RowRule.
	 *
	 * @throws PersistenceException
	 */
	public function add(array $fields, int $userId): Result
	{
		$result = $this->prepare($fields, null);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$now = new DateTime();
		$rule = new RowRule(
			$data['tableCode'],
			$data['fieldCode'],
			$data['values'],
			$data['description'],
			$userId,
			$userId,
			$now,
			$now,
		);
		$this->repository->save($rule);

		return (new Result())->setData(['rule' => $rule]);
	}

	/**
	 * @param int $id
	 * @param array $fields The whole rule, as in `add`.
	 * @param int $userId Author of the change.
	 *
	 * @return Result Data: `rule` - the saved RowRule.
	 *
	 * @throws PersistenceException
	 */
	public function update(int $id, array $fields, int $userId): Result
	{
		$rule = $this->repository->getById($id);
		if (!$rule instanceof RowRule)
		{
			return $this->createNotFoundResult($id);
		}

		$result = $this->prepare($fields, $id);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$rule
			->setTableCode($data['tableCode'])
			->setFieldCode($data['fieldCode'])
			->setValues($data['values'])
			->setDescription($data['description'])
			->setUpdatedById($userId)
			->setDateModify(new DateTime())
		;
		$this->repository->save($rule);

		// A rule deleted between the read and the save is not re-created: the UPDATE matches no row.
		// MySQL reports changed rows only, so the affected rows count cannot tell this apart from a no-op save.
		if (!$this->repository->exists($id))
		{
			return $this->createNotFoundResult($id);
		}

		return (new Result())->setData(['rule' => $rule]);
	}

	/**
	 * @param int $id
	 *
	 * @return Result Data: `id` of the deleted rule.
	 *
	 * @throws PersistenceException
	 */
	public function delete(int $id): Result
	{
		if (!$this->repository->getById($id) instanceof RowRule)
		{
			return $this->createNotFoundResult($id);
		}

		$this->repository->delete($id);

		return (new Result())->setData(['id' => $id]);
	}

	/**
	 * @return Result Data: canonical `tableCode`, `fieldCode`, `values`, `description`.
	 */
	private function prepare(array $fields, ?int $excludeId): Result
	{
		$reader = $this->createReader();
		$result = $this->canonizer->validate($fields, $reader);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$tableCode = (string)$fields['tableCode'];
		$fieldCode = (string)$fields['fieldCode'];
		$data = $result->getData();

		$existingId = $this->findDuplicate(
			$tableCode,
			$fieldCode,
			$data['keys'],
			$reader->getField($tableCode, $fieldCode),
			$excludeId,
		);
		if ($existingId !== null)
		{
			$error = RowRuleError::create(RowRuleError::DUPLICATE, ['existingId' => $existingId]);

			return (new Result())->addError($error);
		}

		return (new Result())->setData([
			'tableCode' => $tableCode,
			'fieldCode' => $fieldCode,
			'values' => $data['values'],
			'description' => $data['description'],
		]);
	}

	/**
	 * There is no unique index: rules of the same table and field are read and their values compared in PHP,
	 * so two simultaneous equal creations may both pass.
	 *
	 * @param string[] $keys Comparison keys of the checked values, in any order.
	 * @param int|null $excludeId The rule being updated.
	 *
	 * @return int|null Identifier of the first stored duplicate.
	 */
	private function findDuplicate(
		string $tableCode,
		string $fieldCode,
		array $keys,
		FieldDescriptor $field,
		?int $excludeId,
	): ?int
	{
		$keys = array_values(array_unique(array_map('strval', $keys)));
		sort($keys, SORT_STRING);

		foreach ($this->repository->findByTableAndField($tableCode, $fieldCode, $excludeId) as $rule)
		{
			if ($this->canonizer->getStoredKeys($field, $rule->getValues()) === $keys)
			{
				return $rule->getId();
			}
		}

		return null;
	}

	private function resolveState(RowRule $rule, FieldDescriptorReader $reader): string
	{
		if (!$reader->hasTable($rule->getTableCode()))
		{
			return self::STATE_TABLE_MISSING;
		}

		$field = $reader->getField($rule->getTableCode(), $rule->getFieldCode());
		if ($field === null || !$field->isSupported)
		{
			return self::STATE_FIELD_MISSING;
		}

		$values = $rule->getValues();
		if ($values === [])
		{
			return self::STATE_FIELD_CHANGED;
		}

		foreach ($values as $item)
		{
			if (!is_array($item) || !$this->canonizer->isValueAcceptable($field, $item))
			{
				return self::STATE_FIELD_CHANGED;
			}
		}

		return self::STATE_OK;
	}

	private function createReader(): FieldDescriptorReader
	{
		return new FieldDescriptorReader($this->tableLoader);
	}

	private function createNotFoundResult(int $id): Result
	{
		return (new Result())->addError(RowRuleError::create(RowRuleError::NOT_FOUND, ['id' => $id]));
	}
}
