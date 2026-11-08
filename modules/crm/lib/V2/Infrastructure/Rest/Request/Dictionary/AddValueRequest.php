<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary;

use Bitrix\Main\HttpRequest;

/**
 * `crm.dictionary.<name>.add`: what to write into the new value of the dictionary.
 *
 * A request of its own rather than the standard {@see \Bitrix\Rest\V3\Interaction\Request\AddRequest},
 * which carries the same one property: the standard one writes whatever the body names, while a client
 * granted a part of the fields may write that part alone. {@see AbstractWriteValueRequest} tells why the
 * grant is checked here at all.
 *
 * Which fields a value of a dictionary keeps and which of them a creation may write is none of this
 * class's business: the editable groups of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\DictionaryValueDto} give that, and the rules
 * of their values are answered by the domain.
 */
final class AddValueRequest extends AbstractWriteValueRequest
{
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		self::validateFieldsScope($request);

		return $request;
	}
}
