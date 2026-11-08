<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.tracking.trace.delete`: the one trace to remove, named by its identifier.
 *
 * The standard delete of REST V3 may address a set by `filter`; removing a set is not a hidden ability
 * of a typical method of CRM, so a `filter` the client did send is refused rather than ignored.
 */
final class DeleteTraceRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	/**
	 * @throws RequestValidationException when the body carries `filter`, whatever stands in it
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		if (array_key_exists('filter', $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([
				new Error(new LocalizableMessage('CRM_V2_REST_TRACKING_TRACE_DELETE_FILTER_REFUSED'), 'filter'),
			]);
		}

		return parent::create($httpRequest, $dtoClass, $options);
	}
}
