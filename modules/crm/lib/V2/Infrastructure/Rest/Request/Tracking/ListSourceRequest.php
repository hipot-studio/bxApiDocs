<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\SourceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\PlainFilterTrait;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.tracking.source.list`: the active sources, or the archived ones.
 *
 * The list is assembled from the records and the catalog of the built-in sources, so the only choice of the
 * client is the archive: the one equality of {@see SourceDto::$isActive}. The order is the order of the catalog
 * and is not a choice of the client either: no field of the source is sortable, so the order the framework
 * reads is refused. It is read only to be refused rather than ignored, so it is {@see Hidden} from the
 * description of the method and from its tool.
 */
final class ListSourceRequest extends Request implements OffsetPaginatedListRequest
{
	use PlainFilterTrait;

	public const IS_ACTIVE = 'isActive';

	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	#[Hidden]
	public ?OrderStructure $order = null;

	public ?PaginationStructure $pagination = null;

	/**
	 * Whether the archived records are listed instead of the active sources, read off the filter: none, or the
	 * one equality of `isActive`.
	 *
	 * Anything else - another condition, another operator, a negation or an alternative anywhere in the tree -
	 * is refused: the flat reading would drop it silently and answer a list the caller did not ask for.
	 *
	 * @throws InvalidFilterException
	 */
	public function isArchived(): bool
	{
		if ($this->filter === null || $this->filter->getConditions() === [])
		{
			return false;
		}

		$isActive = $this->filter->getSimpleFilterConditions()[self::IS_ACTIVE] ?? null;
		if (
			!is_bool($isActive)
			|| count($this->filter->getConditions()) !== 1
			|| !$this->isPlainFilterTree($this->filter)
		)
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		return !$isActive;
	}
}
