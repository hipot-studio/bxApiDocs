<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Provider\Params\Pager;

final class Pagination
{
	public function __construct(
		public readonly ?int $limit = null,
		public readonly ?int $offset = null,
		public readonly ?int $page = null,
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		return new self(
			limit: ScalarCaster::toPositiveInt($parameters['limit'] ?? null),
			offset: ScalarCaster::toNonNegativeInt($parameters['offset'] ?? null),
			page: ScalarCaster::toPositiveInt($parameters['page'] ?? null),
		);
	}

	/**
	 * @throws ArgumentException
	 */
	public function prepare(): Pager
	{
		$pager = new Pager();

		if ($this->limit !== null)
		{
			$pager->setLimit($this->limit);
		}

		if ($this->offset !== null)
		{
			$pager->setOffset($this->offset);
		}
		elseif ($this->page !== null)
		{
			$pager->setPage($this->page);
		}

		return $pager;
	}
}
