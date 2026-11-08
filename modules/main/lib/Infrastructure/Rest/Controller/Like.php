<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Dto\LikeItemDto;
use Bitrix\Main\Infrastructure\Rest\Request\LikeListRequest;
use Bitrix\Main\Infrastructure\Rest\Response\LikeListResponse;
use Bitrix\Main\Infrastructure\Rest\Service\LikeListService;
use Bitrix\Rest\RestException as LegacyRestException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(LikeItemDto::class)]
class Like extends AbstractController
{
	#[Scope('rating')]
	public function listAction(LikeListRequest $request, LikeListService $likeListService): LikeListResponse
	{
		$this->assertSupportedFilter($request->filter);

		$filter = $request->filter->getSimpleFilterConditions();
		$entityTypeId = (string)($filter['entityTypeId'] ?? '');
		$entityId = (int)($filter['entityId'] ?? 0);
		if ($entityTypeId === '' || $entityId <= 0)
		{
			throw new RequestValidationException([
				new Error('entityTypeId must be non-empty and entityId must be positive.', 'filter'),
			]);
		}

		$pathToUserProfile = (string)($filter['pathToUserProfile'] ?? '');
		$reaction = isset($filter['reaction']) ? (string)$filter['reaction'] : null;
		if ($reaction === '')
		{
			$reaction = null;
		}

		$limit = $request->pagination ? $request->pagination->getLimit() : PaginationStructure::DEFAULT_LIMIT;
		$offset = $request->pagination ? $request->pagination->getOffset() : 0;
		$skip = $offset % $limit;
		$page = (int)floor($offset / $limit) + 1;
		$currentUser = CurrentUser::get();
		$viewerUserId = $currentUser->getId() > 0 ? (int)$currentUser->getId() : null;

		$pageResult = $likeListService->getList(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			page: $page,
			limit: $limit,
			pathToUserProfile: $pathToUserProfile,
			reaction: $reaction,
			viewerUserId: $viewerUserId,
		);

		$items = $pageResult['items'];
		if ($skip > 0)
		{
			$nextPageResult = $likeListService->getList(
				entityTypeId: $entityTypeId,
				entityId: $entityId,
				page: $page + 1,
				limit: $limit,
				pathToUserProfile: $pathToUserProfile,
				reaction: $reaction,
				viewerUserId: null,
			);
			$items = array_slice(
				array_merge($pageResult['items'], $nextPageResult['items']),
				$skip,
				$limit,
			);
		}

		$rawItems = [];
		foreach ($items as $item)
		{
			$rawItems[] = [
				...$item,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
				'PATH_TO_USER_PROFILE' => $pathToUserProfile !== '' ? $pathToUserProfile : null,
				'REACTION' => $reaction,
			];
		}

		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new LikeListResponse(
			items: $this->getDtoMapper()->mapCollection($rawItems, $selectedFields),
			total: $pageResult['total'],
		);
	}

	#[Scope('rating')]
	public function reactionsAction(string $entityTypeId, int $entityId, LikeListService $likeListService): ArrayResponse
	{
		try
		{
			$result = $likeListService->getReactions($entityTypeId, $entityId);
		}
		catch (LegacyRestException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage())]);
		}

		return new ArrayResponse($result);
	}

	/**
	 * The action addresses the vote list through getSimpleFilterConditions(), which flattens the
	 * tree into one value per field: OR, negation and a repeated field would silently lose part of
	 * the request instead of narrowing it. Everything the flat map cannot express is rejected here.
	 */
	private function assertSupportedFilter(FilterStructure $filter): void
	{
		$this->assertPlainAndGroup($filter);

		$usedFields = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$this->assertPlainAndGroup($condition);

				$nested = $condition->getConditions();
				if (count($nested) !== 1 || !($nested[0] instanceof Condition))
				{
					throw new RequestValidationException([
						new Error('Nested filter groups are not supported for the like list.', 'filter'),
					]);
				}

				$condition = $nested[0];
			}

			$this->assertEqualityCondition($condition);
			$usedFields[] = $condition->getLeftOperand();
		}

		if (count($usedFields) !== count(array_unique($usedFields)))
		{
			throw new RequestValidationException([
				new Error('Repeating one field in the like list filter is not supported.', 'filter'),
			]);
		}
	}

	private function assertPlainAndGroup(FilterStructure $filter): void
	{
		if ($filter->isNegative())
		{
			throw new RequestValidationException([
				new Error('Negative filters are not supported for the like list.', 'filter'),
			]);
		}

		if ($filter->logic() !== Logic::And)
		{
			throw new RequestValidationException([
				new Error('OR filters are not supported for the like list.', 'filter'),
			]);
		}
	}

	private function assertEqualityCondition(Condition|FilterStructure $condition): void
	{
		if (!($condition instanceof Condition) || $condition->getOperator() !== Operator::Equal)
		{
			throw new RequestValidationException([
				new Error("Only '=' operation is supported for the like list filter.", 'filter'),
			]);
		}

		if (!is_string($condition->getLeftOperand()))
		{
			throw new RequestValidationException([
				new Error('Expressions are not supported for the like list filter.', 'filter'),
			]);
		}
	}
}
