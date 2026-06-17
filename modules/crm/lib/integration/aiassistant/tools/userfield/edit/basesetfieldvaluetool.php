<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\UserField\Edit;

use Bitrix\Crm\Integration\AiAssistant\Tools\UserField\BaseUserFieldTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Main\Validation\Rule\Length;

abstract class BaseSetFieldValueTool extends BaseUserFieldTool
{
	protected const MAX_FIELD_NAME_LENGTH = 255;
	protected const MAX_FIELD_VALUE_LENGTH = 255;

	#[Length(
		min: 1,
		max: self::MAX_FIELD_NAME_LENGTH,
		errorMessage: 'Field name should not exceed ' . self::MAX_FIELD_NAME_LENGTH . ' characters or be empty',
	)]
	protected string $fieldName;

	#[Length(
		min: 1,
		max: self::MAX_FIELD_VALUE_LENGTH,
		errorMessage: 'Field value should not exceed ' . self::MAX_FIELD_VALUE_LENGTH . ' characters or be empty',
	)]
	protected string $fieldValue;

	protected function parseInput(array $args): void
	{
		$this->fieldName = mb_trim((string)($args['fieldName'] ?? ''));
		$this->fieldValue = mb_trim((string)($args['fieldValue'] ?? ''));

		parent::parseInput($args);
	}

	protected function fillInUserField(
		int $userId,
		int $entityId,
		int $entityTypeId,
		string $fieldName,
		string $fieldValue,
	): string
	{
		$canEdit = Container::getInstance()
			->getUserPermissions($userId)
			->item()
			->canUpdate($entityTypeId, $entityId)
		;

		if (!$canEdit)
		{
			return 'User doesn\'t have permissions to edit this entity';
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return 'Entity type not supported';
		}

		$userFields = $this->getEditableUserFieldList($entityTypeId);
		$item = $factory->getItem($entityId, array_keys($userFields));
		if (!$item)
		{
			return 'Entity not found';
		}

		$userFieldCollection = $factory->getUserFieldsCollection();
		$fieldsToUpdate = [];
		foreach ($userFields as $id => $userField)
		{
			if (
				$fieldName !== $userField['EDIT_FORM_LABEL']
				&& $fieldName !== $userField['LIST_COLUMN_LABEL']
				&& $fieldName !== $userField['LIST_FILTER_LABEL']
				&& $fieldName !== $userField['FIELD_NAME']
			)
			{
				continue;
			}

			$isValueEmpty = $userFieldCollection
				->getField($id)
				?->isItemValueEmpty($item)
			;

			if ($isValueEmpty || $userField['MULTIPLE'] === 'Y')
			{
				if ($userField['MULTIPLE'] === 'Y')
				{
					$currentValue = $item->get($id);
					$currentValue = is_array($currentValue) ? $currentValue : [];

					$fieldsToUpdate[$id] = array_merge($currentValue, [$fieldValue]);
				}
				else
				{
					$fieldsToUpdate[$id] = $fieldValue;
				}
			}
		}

		if (empty($fieldsToUpdate))
		{
			return "User field with name {$fieldName} not found or already has value";
		}

		$item->setFromCompatibleData($fieldsToUpdate);
		$context = (new Context())
			->setUserId($userId)
			->setScope(Context::SCOPE_AI)
		;

		$result =
			$factory
				->getUpdateOperation($item, $context)
				->disableAllChecks()
				->launch()
		;

		return
			$result->isSuccess()
				? 'Successfully updated user field value'
				: 'Encountered errors while updating user field value: ' . implode(', ', $result->getErrorMessages())
		;
	}
}
