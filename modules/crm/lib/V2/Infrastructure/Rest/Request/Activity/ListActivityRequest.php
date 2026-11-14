<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ActivityListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ActivityListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.activity.list`: which activities to answer, in which order, which page of them and which of their
 * fields.
 *
 * The filter is the one of the global list, with the optional top-level binding. The select stays the
 * standard structure of REST, the only shape that hands the relations over for REST to load.
 */
final class ListActivityRequest extends Request implements OffsetPaginatedListRequest
{
	private const OWNER_REFUSED = 'crm.activity.list takes no ownerId: narrow it by filter.binding.';

	public ?SelectStructure $select = null;

	public ?ActivityListFilterStructure $filter = null;

	public ?ItemListOrderStructure $order = null;

	public ?ActivityListPaginationStructure $pagination = null;

	/**
	 * The framework reads only the properties a request declares, so the owner of the contextual list
	 * would otherwise go through unread and answer the whole list instead of the list of that owner.
	 *
	 * @throws RequestValidationException when the body carries `ownerId`, whatever stands in it
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = parent::create($httpRequest, $dtoClass, $options);

		if (array_key_exists('ownerId', $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([new Error(self::OWNER_REFUSED, 'ownerId')]);
		}

		return $request;
	}
}
