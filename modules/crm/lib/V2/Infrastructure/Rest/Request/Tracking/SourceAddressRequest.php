<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceAddress;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.tracking.source.archive|unarchive`: the one source to act on, named by exactly one of `id` - the
 * record of the source - or `code` - a built-in source, which also names a built-in source that has no record
 * yet. The answer carries no source, so there is nothing to select: {@see GetSourceRequest} adds `select`.
 *
 * The address is read by {@see self::getAddress()} rather than checked when the request is built, so that the
 * rights are asked before the input is examined.
 */
class SourceAddressRequest extends Request
{
	public ?int $id = null;

	public ?string $code = null;

	/**
	 * @throws RequestValidationException none or both of `id` and `code`, or an `id` that is not positive
	 */
	public function getAddress(): SourceAddress
	{
		if (($this->id === null) === ($this->code === null))
		{
			throw new RequestValidationException([
				new Error(new LocalizableMessage('CRM_V2_REST_TRACKING_SOURCE_ADDRESS_ONE_OF')),
			]);
		}

		if ($this->code !== null)
		{
			return SourceAddress::byCode($this->code);
		}

		if ($this->id <= 0)
		{
			throw new RequestValidationException([
				new Error(new LocalizableMessage('CRM_V2_REST_TRACKING_SOURCE_ADDRESS_ID_INVALID'), 'id'),
			]);
		}

		return SourceAddress::byId($this->id);
	}
}
