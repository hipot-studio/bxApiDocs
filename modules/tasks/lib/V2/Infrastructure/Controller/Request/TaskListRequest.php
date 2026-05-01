<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\Internals\Attribute\Nullable;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList\Filter;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList\Order;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList\Pagination;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList\Select;

class TaskListRequest
{
	public function __construct(
		#[Nullable, Validatable]
		public readonly Select $select,
		#[Nullable, Validatable]
		public readonly Order $order,
		#[Nullable, Validatable]
		public readonly Filter $filter,
		public readonly Pagination $pagination,
	)
	{
	}

	public static function createFromRequest(HttpRequest $request): self
	{
		$jsonData = $request->getJsonList();

		$selectParameters = $jsonData->get('select');
		$orderParameters = $jsonData->get('order');
		$filterParameters = $jsonData->get('filter');
		$paginationParameters = $jsonData->get('pagination');

		return new self(
			Select::createFromArray(is_array($selectParameters) ? $selectParameters : []),
			Order::createFromArray(is_array($orderParameters) ? $orderParameters : []),
			Filter::createFromArray(is_array($filterParameters) ? $filterParameters : []),
			Pagination::createFromArray(is_array($paginationParameters) ? $paginationParameters : []),
		);
	}
}