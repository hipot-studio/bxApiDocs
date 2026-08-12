<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression\FieldDto;
use CBPRuntime;

trait ConditionValueResolver
{
	protected function resolveConditionValue(
		FieldDto $field,
		mixed $value,
		array $documentType,
	): mixed
	{
		if (!is_array($value) || array_is_list($value))
		{
			return $value;
		}

		$fieldName = null;
		foreach (array_keys($value) as $key)
		{
			if (!str_ends_with($key, '_text'))
			{
				$fieldName = $key;
				break;
			}
		}

		if ($fieldName === null)
		{
			return $value;
		}

		$fieldProperty = [
			'Type' => $field->type,
			'Multiple' => $field->multiple,
			'Options' => $field->options,
			'Settings' => $field->settings,
			'Id' => $fieldName,
		];

		$errors = [];

		return CBPRuntime::getRuntime()
			->getDocumentService()
			->getFieldInputValue($documentType, $fieldProperty, $fieldName, $value, $errors)
			?? ''
		;
	}
}
