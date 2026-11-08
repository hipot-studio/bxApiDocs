<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

final class DeleteLayoutRequest extends Request
{
	public string $id;

	#[Min(0)]
	public int $categoryId;

	#[Min(1)]
	public ?int $userId;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		$request->userId ??= null;
		$body = $httpRequest->getJsonList()->getValues();
		foreach (array_keys($body) as $fieldName)
		{
			if (!in_array($fieldName, ['entityTypeId', 'id', 'categoryId', 'userId'], true))
			{
				throw new UnknownDtoPropertyException(Structure::getDto($dtoClass)->getShortName(), (string)$fieldName);
			}
		}

		RequestRawBody::requireIntegerId($httpRequest, 'categoryId');
		if (array_key_exists('userId', $body))
		{
			RequestRawBody::requireIntegerId($httpRequest, 'userId');
		}
		if (!is_string($body['id']))
		{
			throw new InvalidRequestFieldTypeException('id', 'string');
		}

		return $request;
	}
}
