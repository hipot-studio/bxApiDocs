<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Controller;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Sale\Infrastructure\Rest\Service\RestErrorCode;
use Bitrix\Sale\Infrastructure\Rest\Support\IdentifierSupport;

abstract class AbstractController extends RestController
{
	/**
	 * Unwrap a service Result for V3 transport.
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
				$customData = $error->getCustomData();
				$id = is_array($customData) ? (int)($customData['id'] ?? 0) : 0;

				throw new EntityNotFoundException($id);
			}
		}

		throw new RequestValidationException($errors);
	}

	/**
	 * The value is taken as it arrived: a filter operand is not typed at all, and the identifier of
	 * a request is a string. {@see IdentifierSupport} refuses what only a cast would turn into an
	 * entity number, and the refusal stays a client one — the same exception as a missing field.
	 */
	protected function requirePositiveId(mixed $id, string $field = 'id'): int
	{
		$value = IdentifierSupport::readPositive($id);
		if ($value === null)
		{
			throw new RequiredFieldInRequestException($field);
		}

		return $value;
	}

	/**
	 * The identifier of the request, read from the input exactly as it arrived. The request object
	 * holds it as a string assigned without strict types, where `true` and `1.0` have already become
	 * "1" and name the first order; the input is the same one the request object was built from.
	 */
	protected function requireRequestId(): int
	{
		$request = $this->getRequest();
		$id = $request instanceof HttpRequest ? $request->getJsonList()->get('id') : null;

		return $this->requirePositiveId($id);
	}

	/**
	 * Values that name no identifier are dropped, and an empty outcome is refused: the list decides
	 * what to touch, so a value nobody can read must never widen it to a neighbouring entity.
	 *
	 * @param list<mixed>|null $ids
	 * @return list<int>
	 */
	protected function requireIdList(?array $ids, string $field = 'ids'): array
	{
		if ($ids === null || $ids === [])
		{
			throw new RequiredFieldInRequestException($field);
		}

		$result = [];
		foreach ($ids as $id)
		{
			$value = IdentifierSupport::readPositive($id);
			if ($value !== null)
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
	 * Resolve DeleteRequest to positive ids: either `id` or a filter limited to field `id`
	 * with operators `=` / `in`.
	 *
	 * @return list<int>
	 */
	protected function resolveDeleteIds(DeleteRequest $request): array
	{
		if ($request->id !== null)
		{
			return [$this->requireRequestId()];
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
