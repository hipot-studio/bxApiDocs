<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class ListFormRequest extends Request
{
	public ?SelectStructure $select = null;

	/** A page of forms is narrowed by the paths of sections too: {@see FormListFilterStructure}. */
	public ?FormListFilterStructure $filter = null;

	/** A page of forms is ordered by the paths of sections too: {@see FormListOrderStructure}. */
	public ?FormListOrderStructure $order = null;

	public ?FormListPaginationStructure $pagination = null;
}
