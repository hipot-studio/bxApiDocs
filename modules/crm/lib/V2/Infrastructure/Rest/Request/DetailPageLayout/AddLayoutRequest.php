<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\LayoutFieldsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\DetailPageLayoutFieldsStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

final class AddLayoutRequest extends Request
{
	#[Min(0)]
	public int $categoryId;

	public DetailPageLayoutFieldsStructure $fields;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, LayoutFieldsDto::class, $options);
		$body = $httpRequest->getJsonList()->getValues();
		foreach (array_keys($body) as $fieldName)
		{
			if (!in_array($fieldName, ['entityTypeId', 'categoryId', 'fields'], true))
			{
				throw new UnknownDtoPropertyException(
					Structure::getDto(LayoutFieldsDto::class)->getShortName(),
					(string)$fieldName,
				);
			}
		}

		RequestRawBody::requireIntegerId($httpRequest, 'categoryId');

		if (!is_array($body['fields']))
		{
			throw new InvalidRequestFieldTypeException('fields', 'object');
		}
		if ($body['fields'] === [])
		{
			throw new RequiredFieldInRequestException('fields');
		}
		if (array_key_exists('name', $body['fields']) && !is_string($body['fields']['name']))
		{
			throw new InvalidRequestFieldTypeException('fields.name', 'string');
		}
		foreach (['configuration', 'audience'] as $fieldName)
		{
			if (
				array_key_exists($fieldName, $body['fields'])
				&& (!is_array($body['fields'][$fieldName]) || !array_is_list($body['fields'][$fieldName]))
			)
			{
				throw new InvalidRequestFieldTypeException('fields.' . $fieldName, 'array');
			}
		}
		if (array_key_exists('audience', $body['fields']) && $body['fields']['audience'] === [])
		{
			throw new RequiredFieldInRequestException('fields.audience');
		}

		$request->fields->convertToDto('add');

		return $request;
	}
}
