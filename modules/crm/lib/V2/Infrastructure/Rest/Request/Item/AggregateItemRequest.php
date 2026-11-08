<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class AggregateItemRequest extends Request
{
	public array $select;

	public ?ItemListFilterStructure $filter = null;

	public ?array $order = null;

	public ?array $pagination = null;
}
