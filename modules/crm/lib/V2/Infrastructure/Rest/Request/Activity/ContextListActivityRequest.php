<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ActivityListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ContextActivityListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.<entity>.activity.list`: the parent whose activities to answer, which of them, in which order, which page
 * and which of their fields.
 *
 * The parent is half of the route and half of the filter: its type is the one of the route, which the body cannot
 * name, and its identifier is the `ownerId` the filter must carry. The filter is therefore required. The order,
 * the page and the select are the ones of `crm.activity.list`.
 */
final class ContextListActivityRequest extends Request implements OffsetPaginatedListRequest
{
	private const OWNER_REFUSED = 'ownerId is a condition of the filter: name it as ["ownerId", "=", N] there.';

	public ContextActivityListFilterStructure $filter;

	public ?SelectStructure $select = null;

	public ?ItemListOrderStructure $order = null;

	public ?ActivityListPaginationStructure $pagination = null;

	/**
	 * The framework reads only the properties a request declares, so an owner named past the filter would go
	 * through unread. Its refusal comes first whatever the rest of the request is: a request refused for
	 * another reason too is still answered by the owner. The body is looked at after the parent has read it,
	 * since it is the parent that decodes it.
	 *
	 * @throws RequestValidationException when the body carries `ownerId`, whatever stands in it
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		try
		{
			$request = parent::create($httpRequest, $dtoClass, $options);
		}
		catch (\Throwable $refusal)
		{
			self::refuseOwnerPastFilter($httpRequest);

			throw $refusal;
		}

		self::refuseOwnerPastFilter($httpRequest);

		return $request;
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function refuseOwnerPastFilter(HttpRequest $httpRequest): void
	{
		if (array_key_exists('ownerId', $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([new Error(self::OWNER_REFUSED, 'ownerId')]);
		}
	}
}
