<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Tasks\Internals\Attribute\Nullable;
use Bitrix\Tasks\Internals\Attribute\PositiveNumber;

class Pagination
{
	protected function __construct(
		#[PositiveNumber, Nullable]
		public readonly ?int $limit,
		#[PositiveNumber, Nullable]
		public readonly ?int $offset,
		#[PositiveNumber, Nullable]
		public readonly ?int $page,
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		return new static(
			$parameters['limit'] ?? null,
			$parameters['offset'] ?? null,
			$parameters['page'] ?? null,
		);
	}

	/**
	 * @throws ArgumentException
	 */
	public function prepare(): PagerInterface
	{
		$pager = new Pager();

		if ($this->limit !== null )
		{
			$pager->setLimit($this->limit);
		}

		if ($this->offset !== null )
		{
			$pager->setOffset($this->offset);
		}
		elseif ($this->page !== null )
		{
			$pager->setPage($this->page);
		}

		return $pager;
	}
}
