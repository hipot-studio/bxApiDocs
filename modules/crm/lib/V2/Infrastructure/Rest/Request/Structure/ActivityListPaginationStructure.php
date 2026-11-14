<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The paging of the list of activities: the one of {@see ItemListPaginationStructure} with the largest page of the
 * method. A larger size is cut to it before the page is counted, so `page` and `limit` of one request agree on
 * the skip.
 */
final class ActivityListPaginationStructure extends Structure
{
	/** The most activities one page of the method holds: the largest page of the provider. */
	public const MAX_LIMIT = ActivityProvider::MAX_LIMIT;

	private function __construct(
		private readonly ItemListPaginationStructure $structure,
	)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (
			is_array($value)
			&& isset($value['limit'])
			&& is_numeric($value['limit'])
			&& $value['limit'] > self::MAX_LIMIT
		)
		{
			$value['limit'] = self::MAX_LIMIT;
		}

		return new self(ItemListPaginationStructure::create($value, $dtoClass, $request));
	}

	public function getLimit(): int
	{
		return min($this->structure->getLimit(), self::MAX_LIMIT);
	}

	public function getOffset(): int
	{
		return $this->structure->getOffset();
	}
}
