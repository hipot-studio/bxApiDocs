<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression\FieldDto;
use CBPRuntime;

trait ConditionValueResolver
{
	/**
	 * @param array $documentType Document type of the edited template.
	 * @param array|null $conditionDocumentType Document type the `Document` object of the condition addresses
	 *   while that is not the one of the template ({@see self::resolveConditionDocumentType()}). Only the
	 *   fields of that object take it: every other source of a value - a constant, a variable, the output of
	 *   another block - belongs to the template and is normalised by its document type.
	 */
	protected function resolveConditionValue(
		FieldDto $field,
		mixed $value,
		array $documentType,
		?array $conditionDocumentType = null,
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

		// 'Document' is the object of the document itself, the way \CBPMixedCondition reads a condition entry.
		$valueDocumentType = $conditionDocumentType !== null && $field->object === 'Document'
			? $conditionDocumentType
			: $documentType
		;

		return CBPRuntime::getRuntime()
			->getDocumentService()
			->getFieldInputValue($valueDocumentType, $fieldProperty, $fieldName, $value, $errors)
			?? ''
		;
	}

	/**
	 * Document type the `Document` object of the condition of a node addresses, or null while that is the
	 * document type of the edited template.
	 *
	 * A trigger is the one node whose condition is decided before the process starts, on a stub workflow bound
	 * to the document its own settings name, so `Document` there is answered by the channel of the condition
	 * document ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getConditionDocumentTypeForNode()}):
	 * the type a node declares through it, and the published type for a node that declares none. The condition
	 * of every other node is decided on the workflow itself ({@see \CBPWorkflow::isNodeConditionMet()}), where
	 * `Document` is the document of the template.
	 *
	 * The trigger guard itself lives in that resolver and not here: this is a delegate, so that all three
	 * writers of a condition - the settings panel, the conversion of rules and the path of an AI draft - are
	 * served by one answer.
	 */
	public function resolveConditionDocumentType(string $activityType, array $activityProperties): ?array
	{
		return BizprocContainer::instance()
			->getComplexActivityService()
			->getConditionDocumentTypeForNode($activityType, $activityProperties)
		;
	}
}
