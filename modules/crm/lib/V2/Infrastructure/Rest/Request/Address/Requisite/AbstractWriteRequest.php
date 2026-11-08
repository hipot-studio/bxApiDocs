<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite;

use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

abstract class AbstractWriteRequest extends Request
{
	protected static function validateFieldsScope(Request $request): void
	{
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];
		if ($availableFields === [])
		{
			return;
		}

		$dto = Structure::getDto($request->getDtoClass());
		if ($dto === null)
		{
			return;
		}

		$fields = array_merge(
			$request->fields->getItems(),
			$request->fields->getUserFields(),
		);
		foreach ($fields as $fieldName => $_)
		{
			if (!in_array($fieldName, $availableFields, true))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName);
			}
		}
	}
}
