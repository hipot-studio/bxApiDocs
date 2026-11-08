<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\PlainFilterTrait;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.tracking.trace.list`: the traces of one CRM item, the latest binding first.
 *
 * A trace has no list of its own: it is read through the item it is bound to, so the item is a
 * required filter of exactly two equalities. The order is the order of attribution and is not a
 * choice of the client; the trace has no sortable field, so the order the framework reads is refused.
 */
final class ListTraceRequest extends Request implements OffsetPaginatedListRequest
{
	use PlainFilterTrait;

	public const ENTITY_TYPE_ID = 'entityTypeId';
	public const ENTITY_ID = 'entityId';

	public ?SelectStructure $select = null;

	#[FilterRequired(fields: [self::ENTITY_TYPE_ID, self::ENTITY_ID])]
	public FilterStructure $filter;

	public ?OrderStructure $order = null;

	public ?PaginationStructure $pagination = null;

	/**
	 * The item of the page, read off the two equalities the filter must consist of.
	 *
	 * Anything else - another condition, another operator, a negation or an alternative anywhere in
	 * the tree - is refused: the flat reading would drop it silently and answer about an item the
	 * caller did not ask about.
	 *
	 * @return array{0: int, 1: int} entity type id, entity id
	 * @throws InvalidFilterException
	 */
	public function getItem(): array
	{
		$conditions = $this->filter->getSimpleFilterConditions();
		$entityTypeId = $conditions[self::ENTITY_TYPE_ID] ?? null;
		$entityId = $conditions[self::ENTITY_ID] ?? null;

		if (
			!is_int($entityTypeId)
			|| !is_int($entityId)
			|| count($this->filter->getConditions()) !== 2
			|| !$this->isPlainFilterTree($this->filter)
		)
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		return [$entityTypeId, $entityId];
	}
}
