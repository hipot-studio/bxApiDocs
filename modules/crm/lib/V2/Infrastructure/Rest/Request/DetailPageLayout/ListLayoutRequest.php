<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\DetailPageLayoutListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\DetailPageLayoutPaginationStructure;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

final class ListLayoutRequest extends Request
{
	public DetailPageLayoutListFilterStructure $filter;

	public ?DetailPageLayoutPaginationStructure $pagination = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		foreach (array_keys($httpRequest->getJsonList()->getValues()) as $fieldName)
		{
			if (!in_array($fieldName, ['entityTypeId', 'filter', 'pagination'], true))
			{
				throw new UnknownDtoPropertyException(Structure::getDto($dtoClass)->getShortName(), (string)$fieldName);
			}
		}

		$request->pagination ??= DetailPageLayoutPaginationStructure::create([], $dtoClass, $request);

		return $request;
	}
}
