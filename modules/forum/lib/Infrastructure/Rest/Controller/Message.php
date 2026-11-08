<?php

namespace Bitrix\Forum\Infrastructure\Rest\Controller;

use Bitrix\Forum\Infrastructure\Rest\Dto\MessageDto;
use Bitrix\Forum\Infrastructure\Rest\Service\MessageDeleteService;
use Bitrix\Forum\MessageTable;
use Bitrix\Main\DB\Order;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\OrmActionTrait;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\TailRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\TailResponse;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(MessageDto::class)]
class Message extends RestController
{
	use OrmActionTrait;

	#[Hidden]
	public function listAction(ListRequest $request): ListResponse
	{
		$filter = $this->applyCurrentUserFilter($request->filter);

		$collection = $this->getOrmRepositoryByRequest($request)->getAll(
			$request->select,
			$filter,
			$request->order,
			$request->pagination,
		);

		return new ListResponse($collection);
	}

	#[Hidden]
	public function tailAction(TailRequest $request): TailResponse
	{
		$filter = $this->applyCurrentUserFilter($request->filter);

		$cursor = $request->cursor;
		if ($cursor !== null && $cursor->getField() !== 'id')
		{
			throw new InvalidFilterException('Cursor field must be id.');
		}

		if (in_array('id', $filter->getFields(), true))
		{
			throw new InvalidFilterException('Cursor field id cannot be used at filter.');
		}

		$order = $cursor?->getOrder()->value ?? Order::Asc->value;
		$limit = $cursor?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;

		if ($cursor !== null)
		{
			$operator = $order === Order::Asc->value ? Operator::Greater : Operator::Less;
			$filter->addCondition(new Condition('id', $operator, $cursor->getValue()));
		}

		$collection = $this->getOrmRepositoryByRequest($request)->getAll(
			$request->select,
			$filter,
			OrderStructure::create(['id' => $order], $request->getDtoClass(), $request),
			PaginationStructure::create(['limit' => $limit + 1]),
		);

		$hasMore = $collection->count() > $limit;
		if ($hasMore)
		{
			$collection->removeLast();
		}

		$last = $collection->last();

		return new TailResponse(
			items: $collection,
			hasMore: $hasMore,
			cursorField: 'id',
			cursorValue: $last !== null ? (string)$last->id : null,
		);
	}

	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$messageId = (int)$request->id;
		if ($messageId <= 0)
		{
			throw new RequiredFieldInRequestException('id');
		}

		$message = MessageTable::getByPrimary($messageId, [
			'select' => ['ID', 'AUTHOR_ID'],
		])->fetch();
		if ($message === false)
		{
			return new DeleteResponse(true);
		}

		$currentUserId = (int)($this->getCurrentUser()?->getId() ?? 0);
		$isAuthor = (int)$message['AUTHOR_ID'] === $currentUserId;
		if (
			!$isAuthor
			&& (
				!Loader::includeModule('socialnetwork')
				|| !\CSocNetUser::isUserModuleAdmin($currentUserId, SITE_ID)
			)
		)
		{
			throw new AccessDeniedException();
		}

		return new DeleteResponse((new MessageDeleteService())->delete($messageId));
	}

	/**
	 * Always scopes list/tail to the current user. authorId is not client-filterable.
	 */
	private function applyCurrentUserFilter(?FilterStructure $filter): FilterStructure
	{
		$currentUserId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if ($currentUserId <= 0)
		{
			throw new AccessDeniedException();
		}

		$result = (new FilterStructure())->where('authorId', $currentUserId);
		if ($filter !== null)
		{
			$result->addCondition($filter);
		}

		return $result;
	}
}
