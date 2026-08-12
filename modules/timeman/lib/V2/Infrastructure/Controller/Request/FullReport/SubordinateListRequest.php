<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\FullReport;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\Order;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\Pagination;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\RequestData;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\Select;

final class SubordinateListRequest
{
	public function __construct(
		#[Validatable]
		public readonly SubordinateFilter $filter,
		#[Validatable]
		public readonly Pagination $pagination,
		public readonly Select $select,
		public readonly Order $order,
		public readonly array $extra = [],
	)
	{
	}

	public static function createFromRequest(HttpRequest $request): self
	{
		$data = RequestData::fromHttpRequest($request);

		return new self(
			filter: SubordinateFilter::createFromArray(is_array($data['filter'] ?? null) ? $data['filter'] : []),
			pagination: Pagination::createFromArray(is_array($data['pagination'] ?? null) ? $data['pagination'] : []),
			select: Select::createFromArray(is_array($data['select'] ?? null) ? $data['select'] : []),
			order: Order::createFromArray(is_array($data['order'] ?? null) ? $data['order'] : []),
			extra: is_array($data['extra'] ?? null) ? $data['extra'] : [],
		);
	}

	public function getUserLimit(int $default = 5): int
	{
		return $this->pagination->limit ?? $default;
	}

	public function getUserOffset(int $defaultLimit = 5): int
	{
		if ($this->pagination->offset !== null)
		{
			return $this->pagination->offset;
		}

		$limit = $this->getUserLimit($defaultLimit);
		if ($this->pagination->page !== null)
		{
			return ($this->pagination->page - 1) * $limit;
		}

		return 0;
	}
}
