<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\MyCompany;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\MyCompany\BasicCompanyDtoMapper;
use Bitrix\Crm\V2\Internal\Repository\Item\CompanyRepository;
use Bitrix\Crm\V2\Internal\Repository\Requisite\MyCompanyRequisiteRepository;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ItemFieldMapper;
use Bitrix\Crm\V2\Internal\Service\Loader\MultifieldLoader;
use Bitrix\Crm\V2\Internal\Service\Requisite\BankRequisiteService;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\ItemFactory;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

/** @internal */
final class MyCompanyBasicProvider
{
	private const SELECT_FIELDS = [
		'id',
		'title',
		'email',
		'phone',
		'requisites',
		'bankRequisites',
	];

	private const FILTER_FIELDS = [
		'id' => 'ID',
		'title' => 'TITLE',
	];

	private const ORDER_FIELDS = [
		'id' => 'ID',
		'title' => 'TITLE',
	];

	private readonly CompanyRepository $companyRepository;
	private readonly MyCompanyRequisiteRepository $requisiteRepository;
	private readonly BasicCompanyDtoMapper $mapper;

	public function __construct(
		private readonly int $userId,
	)
	{
		$this->companyRepository = new CompanyRepository();
		$this->requisiteRepository = new MyCompanyRequisiteRepository();
		$this->mapper = new BasicCompanyDtoMapper();
	}

	/**
	 * Reads one company visible to the current user.
	 *
	 * @param string[] $select
	 */
	public function getById(int $id, array $select, array $nestedSelect = []): Result
	{
		if (!$this->canReadMyCompanies())
		{
			return self::accessDenied();
		}

		$selectResult = $this->resolveSelect($select);
		if (!$selectResult->isSuccess())
		{
			return $selectResult;
		}

		$resolvedSelect = $selectResult->getData()['select'];
		try
		{
			$companies = $this->loadCompanies(
				$resolvedSelect,
				$this->combineOwnerFilter((new ConditionTree())->where('ID', $id)),
				['ID' => 'ASC'],
				1,
				0,
			);
		}
		catch (\Throwable $exception)
		{
			return self::readError($exception);
		}

		if ($companies === [])
		{
			return self::notFound($id);
		}

		return $this->mapResult($companies, $resolvedSelect, 'item', $nestedSelect);
	}

	/**
	 * Reads a page of companies visible to the current user.
	 *
	 * @param string[] $select
	 */
	public function getList(
		array $select,
		?ItemFilter $filter,
		?ItemSort $sort,
		?PagerInterface $pager,
		array $nestedSelect = [],
	): Result
	{
		if (!$this->canReadMyCompanies())
		{
			return self::accessDenied();
		}

		$selectResult = $this->resolveSelect($select);
		if (!$selectResult->isSuccess())
		{
			return $selectResult;
		}
		$filterResult = $this->resolveFilter($filter);
		if (!$filterResult->isSuccess())
		{
			return $filterResult;
		}
		$orderResult = $this->resolveOrder($sort);
		if (!$orderResult->isSuccess())
		{
			return $orderResult;
		}

		$resolvedSelect = $selectResult->getData()['select'];
		$limit = $pager?->getLimit();
		try
		{
			$companies = $this->loadCompanies(
				$resolvedSelect,
				$filterResult->getData()['filter'],
				$orderResult->getData()['order'],
				$limit === null ? null : $limit + 1,
				$pager?->getOffset() ?? 0,
			);
		}
		catch (\Throwable $exception)
		{
			return self::readError($exception);
		}

		if ($limit !== null && count($companies) > $limit)
		{
			$companies = array_slice($companies, 0, $limit);
		}

		return $this->mapResult($companies, $resolvedSelect, 'items', $nestedSelect);
	}

	/**
	 * @param string[] $select
	 * @return array<int, Company>
	 */
	private function loadCompanies(
		array $select,
		ConditionTree $filter,
		array $order,
		?int $limit,
		int $offset,
	): array
	{
		$ormSelect = ['ID'];
		if (in_array('title', $select, true))
		{
			$ormSelect[] = 'TITLE';
		}

		$companies = [];
		foreach ($this->companyRepository->getList($ormSelect, $filter, $order, $limit, $offset) as $entityObject)
		{
			if (!$entityObject instanceof EntityObject)
			{
				continue;
			}

			$item = ItemFactory::create(EntityType::company());
			ItemFieldMapper::syncFromOrm(
				$entityObject,
				$item,
				FieldRegistry::getInstance(EntityType::company()),
				$ormSelect,
			);
			if ($item instanceof Company && $item->getId() !== null)
			{
				$companies[] = $item;
			}
		}

		$multifieldSelect = new ItemSelect('id');
		if (in_array('email', $select, true))
		{
			$multifieldSelect->withEmails();
		}
		if (in_array('phone', $select, true))
		{
			$multifieldSelect->withPhones();
		}
		if ($multifieldSelect->shouldLoadAnyMultifield())
		{
			(new MultifieldLoader())->load($companies, $multifieldSelect);
		}

		return $companies;
	}

	/**
	 * @param array<int, Company> $companies
	 * @param string[] $select
	 */
	private function mapResult(
		array $companies,
		array $select,
		string $key,
		array $nestedSelect = [],
	): Result
	{
		$companyIds = array_values(array_filter(
			array_map(static fn(Company $company): int => (int)$company->getId(), $companies),
			static fn(int $id): bool => $id > 0,
		));
		$requisitesByCompanyId = [];
		$bankRequisitesByCompanyId = [];

		try
		{
			if (in_array('requisites', $select, true))
			{
				$requisitesByCompanyId = $this->requisiteRepository->findAllByCompanyIds(
					$companyIds,
					$nestedSelect['requisites'] ?? null,
				);
			}
			if (in_array('bankRequisites', $select, true))
			{
				$bankResult = (new BankRequisiteService($this->userId))->getListByCompanyIds(
					$companyIds,
					$nestedSelect['bankRequisites'] ?? null,
				);
				if (!$bankResult->isSuccess())
				{
					return (new Result())->addErrors($bankResult->getErrors());
				}
				$bankRequisitesByCompanyId = $bankResult->getData()['bankRequisites'] ?? [];
			}

			$items = [];
			foreach ($companies as $company)
			{
				$companyId = (int)$company->getId();
				$items[] = [
					'company' => $company,
					'requisites' => $requisitesByCompanyId[$companyId] ?? [],
					'bankRequisites' => $bankRequisitesByCompanyId[$companyId] ?? [],
				];
			}

			$mapped = $this->mapper->mapCollection($items, $select, $nestedSelect);
		}
		catch (\Throwable $exception)
		{
			return self::readError($exception);
		}

		return (new Result())->setData([
			$key => $key === 'item' ? $mapped->first() : $mapped,
		]);
	}

	private function combineOwnerFilter(ConditionTree $filter): ConditionTree
	{
		return (new ConditionTree())
			->logic(ConditionTree::LOGIC_AND)
			->addCondition(new Condition('IS_MY_COMPANY', '=', 'Y'))
			->addCondition($filter)
		;
	}

	private function canReadMyCompanies(): bool
	{
		return Container::getInstance()
			->getUserPermissions($this->userId)
			->myCompany()
			->canReadBaseFields()
		;
	}

	/** @param string[] $select */
	private function resolveSelect(array $select): Result
	{
		$select = $select === [] ? ['id', 'title'] : array_values(array_unique($select));
		foreach ($select as $field)
		{
			if (!is_string($field) || !in_array($field, self::SELECT_FIELDS, true))
			{
				return self::validationError('Unknown company select field.');
			}
		}

		return (new Result())->setData(['select' => $select]);
	}

	private function resolveFilter(?ItemFilter $filter): Result
	{
		if ($filter === null)
		{
			return (new Result())->setData(['filter' => $this->combineOwnerFilter(new ConditionTree())]);
		}

		try
		{
			$raw = $filter->getRaw();
			$translated = $raw instanceof ConditionTree
				? $this->translateConditionTree($raw)
				: $this->translateFilterArray($raw)
			;

			return (new Result())->setData(['filter' => $this->combineOwnerFilter($translated)]);
		}
		catch (\Throwable $exception)
		{
			return self::validationError($exception->getMessage());
		}
	}

	private function translateConditionTree(ConditionTree $filter): ConditionTree
	{
		$translated = clone $filter;
		foreach ($translated->getConditions() as $condition)
		{
			if ($condition instanceof ConditionTree)
			{
				$this->translateConditionTree($condition);

				continue;
			}
			if (!$condition instanceof Condition || !is_string($condition->getColumn()))
			{
				throw new \InvalidArgumentException('Unsupported company filter condition.');
			}

			$this->validateOperator($condition->getOperator(), $condition->getValue());
			$condition->setColumn($this->mapFilterField($condition->getColumn()));
		}

		return $translated;
	}

	/** @param array<string, mixed> $filter */
	private function translateFilterArray(array $filter): ConditionTree
	{
		$translated = new ConditionTree();
		foreach ($filter as $key => $value)
		{
			if (!is_string($key))
			{
				throw new \InvalidArgumentException('Unsupported company filter key.');
			}

			[$operator, $field] = $this->parseFilterKey($key, $value);
			$translated->addCondition(new Condition($this->mapFilterField($field), $operator, $value));
		}

		return $translated;
	}

	/** @return array{0: string, 1: string} */
	private function parseFilterKey(string $key, mixed $value): array
	{
		if (str_starts_with($key, '@'))
		{
			$operator = 'in';
			$field = substr($key, 1);
		}
		elseif (str_starts_with($key, '='))
		{
			$operator = '=';
			$field = ltrim($key, '=');
		}
		else
		{
			$operator = is_array($value) ? 'in' : '=';
			$field = $key;
		}

		$this->validateOperator($operator, $value);

		return [$operator, $field];
	}

	private function mapFilterField(string $field): string
	{
		if (isset(self::FILTER_FIELDS[$field]))
		{
			return self::FILTER_FIELDS[$field];
		}

		$field = strtolower($field);
		foreach (self::FILTER_FIELDS as $name => $ormField)
		{
			if ($field === strtolower($name) || $field === strtolower($ormField))
			{
				return $ormField;
			}
		}

		throw new \InvalidArgumentException('Unknown company filter field.');
	}

	private function validateOperator(string $operator, mixed $value): void
	{
		if (!in_array(strtolower($operator), ['=', '==', 'in'], true))
		{
			throw new \InvalidArgumentException('Unsupported company filter operator.');
		}
		if (strtolower($operator) === 'in' && !is_array($value) && !is_object($value))
		{
			throw new \InvalidArgumentException('The company `in` filter requires an array.');
		}
	}

	private function resolveOrder(?ItemSort $sort): Result
	{
		$raw = $sort?->getRaw() ?? [];
		if ($raw === [])
		{
			$raw = ['id' => 'ASC'];
		}

		$order = [];
		$lastDirection = 'ASC';
		foreach ($raw as $field => $direction)
		{
			if (!is_string($field) || !is_string($direction))
			{
				return self::validationError('Invalid company order.');
			}
			$ormField = self::ORDER_FIELDS[$field] ?? null;
			$direction = strtoupper($direction);
			if ($ormField === null || !in_array($direction, ['ASC', 'DESC'], true))
			{
				return self::validationError('Invalid company order.');
			}
			$order[$ormField] = $direction;
			$lastDirection = $direction;
		}
		if (!isset($order['ID']))
		{
			$order['ID'] = $lastDirection;
		}

		return (new Result())->setData(['order' => $order]);
	}

	private static function accessDenied(): Result
	{
		return (new Result())->addError(new Error(
			'The current user cannot read my companies.',
			'CRM_MY_COMPANY_ACCESS_DENIED',
		));
	}

	private static function notFound(int $id): Result
	{
		return (new Result())->addError(new Error(
			'Company not found.',
			'CRM_MY_COMPANY_NOT_FOUND',
			['id' => $id],
		));
	}

	private static function validationError(string $message): Result
	{
		return (new Result())->addError(new Error($message, 'CRM_MY_COMPANY_VALIDATION'));
	}

	private static function readError(\Throwable $exception): Result
	{
		return (new Result())->addError(new Error(
			$exception->getMessage(),
			'CRM_MY_COMPANY_READ_ERROR',
		));
	}
}
