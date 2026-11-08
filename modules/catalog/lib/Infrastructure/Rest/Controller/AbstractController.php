<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller;

use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Internal\Service\Rest\RestErrorCode;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityAlreadyExistsException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

abstract class AbstractController extends RestController
{
	/**
	 * Map ORM EntityObject collection to DTO collection (#[OrmEntity] path).
	 * Prefer over getDtoMapper() — MappedBy is disabled when OrmEntity is present.
	 */
	protected function mapOrmCollection(string $dtoClass, Collection $collection): DtoCollection
	{
		return (new CatalogOrmRepository($dtoClass))->mapCollection($collection);
	}

	/**
	 * Map a single ORM EntityObject to DTO (#[OrmEntity] path).
	 */
	protected function mapOrmEntity(string $dtoClass, EntityObject $object): Dto
	{
		return (new CatalogOrmRepository($dtoClass))->mapEntity($object);
	}

	/**
	 * Unwrap Internal Rest service Result for V3 transport.
	 *
	 * @return array<string, mixed>
	 */
	protected function requireResultData(Result $result): array
	{
		if (!$result->isSuccess())
		{
			$this->throwFromServiceResult($result);
		}

		return $result->getData();
	}

	/**
	 * Domain codes without a dedicated transport exception (validation, catalog of offers)
	 * are reported as the whole error collection.
	 */
	protected function throwFromServiceResult(Result $result): never
	{
		$errors = $result->getErrors();
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			if ($code === RestErrorCode::ACCESS_DENIED)
			{
				throw new AccessDeniedException();
			}
			if ($code === RestErrorCode::NOT_FOUND)
			{
				throw new EntityNotFoundException((int)$this->extractErrorId($error));
			}
			if ($code === RestErrorCode::ALREADY_EXISTS)
			{
				throw new EntityAlreadyExistsException($this->extractErrorId($error));
			}
		}

		throw new RequestValidationException($errors);
	}

	private function extractErrorId(Error $error): string
	{
		$customData = $error->getCustomData();

		return is_array($customData) ? (string)($customData['id'] ?? '') : '';
	}

	/**
	 * Static enumerations are returned as a whole: there is nothing to filter, sort or
	 * paginate, so any of those structures is a request the action cannot honour.
	 */
	protected function rejectListOptions(ListRequest $request): void
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException($request->filter->getList());
		}

		if ($request->order !== null)
		{
			throw new InvalidOrderException($request->order->getList());
		}

		if ($request->pagination !== null)
		{
			throw new InvalidPaginationException(['limit' => $request->pagination->getLimit()]);
		}
	}

	protected function requirePositiveId(int|string|null $id, string $field = 'id'): int
	{
		$value = (int)$id;
		if ($value <= 0)
		{
			throw new RequiredFieldInRequestException($field);
		}

		return $value;
	}

	/**
	 * @param list<int|string>|null $ids
	 * @return list<int>
	 */
	protected function requireIdList(?array $ids, string $field = 'documentIds'): array
	{
		if ($ids === null || $ids === [])
		{
			throw new RequiredFieldInRequestException($field);
		}

		$result = [];
		foreach ($ids as $id)
		{
			$value = (int)$id;
			if ($value > 0)
			{
				$result[] = $value;
			}
		}

		if ($result === [])
		{
			throw new RequiredFieldInRequestException($field);
		}

		return $result;
	}

	/**
	 * Resolve DeleteRequest to positive ids: either `id` or filter limited to field `id`
	 * with operators `=` / `in`.
	 *
	 * @return list<int>
	 */
	protected function resolveDeleteIds(DeleteRequest $request): array
	{
		if ($request->id !== null)
		{
			return [$this->requirePositiveId($request->id)];
		}

		if ($request->filter === null)
		{
			throw new RequiredFieldInRequestException('id');
		}

		$fields = $request->filter->getFields();
		if ($fields === [] || array_diff($fields, ['id']) !== [])
		{
			throw new InvalidFilterException('filter');
		}

		$ids = $this->extractIdsFromIdOnlyFilter($request->filter);
		if ($ids === [])
		{
			throw new RequiredFieldInRequestException('id');
		}

		return $ids;
	}

	/**
	 * Only a single positive `id` (=) or `id` (in) condition is accepted.
	 * Nested groups / NOT / multiple conditions are rejected (would otherwise union ids).
	 *
	 * @return list<int>
	 */
	private function extractIdsFromIdOnlyFilter(FilterStructure $filter): array
	{
		if ($filter->isNegative())
		{
			throw new InvalidFilterException('filter');
		}

		$conditions = $filter->getConditions();
		if (count($conditions) !== 1)
		{
			throw new InvalidFilterException('filter');
		}

		$condition = $conditions[0];
		if (!($condition instanceof Condition) || $condition->getLeftOperand() !== 'id')
		{
			throw new InvalidFilterException('filter');
		}

		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();

		if ($operator === Operator::Equal)
		{
			return [$this->requirePositiveId($value)];
		}

		if ($operator === Operator::In)
		{
			if (!is_array($value) || $value === [])
			{
				throw new InvalidFilterException('filter');
			}

			$ids = [];
			foreach ($value as $id)
			{
				$ids[] = $this->requirePositiveId($id);
			}

			return array_values(array_unique($ids));
		}

		throw new InvalidFilterException('filter');
	}
}
