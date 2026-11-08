<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Refuses a request that addresses a set by `filter` instead of naming one form by `id`.
 *
 * The framework reads only the properties a request declares, so an undeclared `filter` would
 * otherwise go through unread and the operation would land on the one form `id` names - an answer
 * the client did not ask for. Changing or removing a set is a contract of its own, not a hidden
 * ability of these ones.
 *
 * A request using this declares `FILTER_REFUSED`: the answer telling which method refused and why.
 */
trait RefusesFilterAddressing
{
	/**
	 * @throws RequestValidationException when the body carries `filter`, whatever stands in it
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = parent::create($httpRequest, $dtoClass, $options);

		if (array_key_exists('filter', $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([new Error(static::FILTER_REFUSED, 'filter')]);
		}

		return $request;
	}
}
