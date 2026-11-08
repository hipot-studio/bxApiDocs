<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite;

use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Structure\FieldsStructure;

final class AddRequest extends AbstractWriteRequest
{
	public FieldsStructure $fields;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		self::validateFieldsScope($request);

		return $request;
	}
}
