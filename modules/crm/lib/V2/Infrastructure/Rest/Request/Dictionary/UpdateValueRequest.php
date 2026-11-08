<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.dictionary.<name>.update`: which value to change and what to write into it.
 *
 * A request of its own rather than the standard {@see \Bitrix\Rest\V3\Interaction\Request\UpdateRequest},
 * which also addresses a set by `filter`: a typical method of CRM is forbidden to do that, so the
 * property is absent here rather than empty. Absent is not the whole of the refusal, though - an unknown
 * key of the body is ignored in silence ({@see Request::create()}), so a client sending `id` and `filter`
 * together would be answered with one value changed instead of a refusal. The refusal itself is given by
 * the controller of the group.
 *
 * Empty `fields` is a valid request rather than an error: it asks for no write, and answering it still
 * takes checking that the value exists and may be changed.
 *
 * `fields.id` is refused rather than dropped in silence: the identifier of a value never changes. That
 * refusal needs nothing here - the editable groups of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\DictionaryValueDto} give it.
 *
 * A field outside the grant of the caller is refused too, and {@see AbstractWriteValueRequest} tells why
 * that is the work of a request rather than of the domain.
 */
final class UpdateValueRequest extends AbstractWriteValueRequest
{
	#[NotEmpty]
	public string $id;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		self::validateFieldsScope($request);

		return $request;
	}
}
