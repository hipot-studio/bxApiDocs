<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\ExpensesPackDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\PlainFilterTrait;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.tracking.expenses.list`: the manual packs of one source, the latest first.
 *
 * The packs are read through their source, so the source is a required filter of exactly one equality of
 * {@see ExpensesPackDto::$sourceId}. The order is not a choice of the client: no field of a pack is sortable,
 * so the order the framework reads is refused. It is read only to be refused rather than ignored, so it is
 * {@see Hidden} from the description of the method and from its tool.
 */
final class ListExpensesRequest extends Request implements OffsetPaginatedListRequest
{
	use PlainFilterTrait;

	public const SOURCE_ID = 'sourceId';

	public ?SelectStructure $select = null;

	#[FilterRequired(fields: [self::SOURCE_ID])]
	public FilterStructure $filter;

	#[Hidden]
	public ?OrderStructure $order = null;

	public ?PaginationStructure $pagination = null;

	/**
	 * The source of the page, read off the one equality the filter must consist of.
	 *
	 * Anything else - another condition, another operator, a negation or an alternative anywhere in the tree -
	 * is refused: the flat reading would drop it silently and answer about a source the caller did not ask
	 * about.
	 *
	 * @throws InvalidFilterException
	 */
	public function getSourceId(): int
	{
		$sourceId = $this->filter->getSimpleFilterConditions()[self::SOURCE_ID] ?? null;
		if (
			!is_int($sourceId)
			|| count($this->filter->getConditions()) !== 1
			|| !$this->isPlainFilterTree($this->filter)
		)
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		return $sourceId;
	}
}
