<?php

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

use Bitrix\Crm\Field;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\Types\EnumType;
use Bitrix\Main\UserField\Types\IntegerType;
use Bitrix\Main\UserField\Types\StringType;

final class UserFieldHelper
{
	private const EDITABLE_TYPES = [
		StringType::USER_TYPE_ID,
		IntegerType::USER_TYPE_ID,
		DoubleType::USER_TYPE_ID,
		EnumType::USER_TYPE_ID,
	];

	public function getEditableUserFieldList(int $entityTypeId, int $entityId): Result
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$item = $factory->getItem($entityId);
		if ($item === null)
		{
			return Result::failNotFound();
		}

		$userFieldCollection = $factory
			->getUserFieldsCollection()
			->filter(fn (Field $field) => $this->isEditableField($field, $item));

		$fields = [];

		foreach ($userFieldCollection as $userfield)
		{
			$field = [
				'name' => $userfield->getName(),
				'title' => $userfield->getTitle(),
				'type' => $userfield->getType(),
				'isMultiple' => $userfield->isMultiple(),
			];

			if ($userfield->getType() === EnumType::USER_TYPE_ID)
			{
				$enumResult = EnumType::getList($userfield->getUserField());
				while ($enum = $enumResult->Fetch())
				{
					$field['enumValues'][] = [
						'id' => $enum['ID'],
						'value' => $enum['VALUE'],
					];
				}
			}

			$fields[] = $field;
		}

		$commentField = $factory->getFieldsCollection()->getField(Item::FIELD_NAME_COMMENTS);
		if ($commentField !== null)
		{
			$fields[] = [
				'name' => $commentField->getName(),
				'title' => $commentField->getTitle(),
				'type' => $commentField->getType(),
				'isMultiple' => $commentField->isMultiple(),
			];
		}

		return Result::success(fields: $fields);
	}

	public function addUserFieldValue(
		int $entityTypeId,
		int $entityId,
		string $fieldId,
		string $fieldValue,
		int $userId,
	): Result
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$field = $this->findField($factory, $fieldId);
		if ($field === null)
		{
			return Result::fail("Field {$fieldId} not found");
		}

		$item = $factory->getItem($entityId);
		if ($item === null)
		{
			return Result::failNotFound();
		}

		if ($field->getName() === Item::FIELD_NAME_COMMENTS)
		{
			$this->appendValueToComment($field, $item, $fieldValue);
		}
		else
		{
			$result = $this->setUserFieldValue($field, $item, $fieldValue);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		$context = (new Context())
			->setUserId($userId)
			->setScope(Context::SCOPE_AI);

		$result = $factory
			->getUpdateOperation($item, $context)
			->disableAutomation()
			->disableCheckAccess()
			->launch();

		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		return Result::success(...$result->getData());
	}

	private function setUserFieldValue(Field $field, Item $item, string $fieldValue): Result
	{
		if (!$this->isEditableField($field, $item))
		{
			return Result::fail("Field {$field->getName()} not editable");
		}

		$normalizedValue = $fieldValue;
		if ($field->getType() === EnumType::USER_TYPE_ID)
		{
			$normalizedValue = $this->findEnumValueId($field, $fieldValue);
			if ($normalizedValue === null)
			{
				return Result::fail("Incorrect enum value for {$field->getName()}");
			}
		}

		$currentValue = $item->get($field->getName());
		if ($field->isMultiple())
		{
			if ($field->isValueEmpty($currentValue))
			{
				$newValue = [$normalizedValue];
			}
			elseif (is_array($currentValue))
			{
				$newValue = array_merge($currentValue, [$normalizedValue]);
				if ($field->getType() === EnumType::USER_TYPE_ID)
				{
					$newValue = array_map(intval(...), $newValue);
					$newValue = array_unique($newValue);
				}
			}
			else
			{
				return Result::fail('Cannot merge field value');
			}
		}
		else
		{
			$newValue = $normalizedValue;
		}

		$item->setFromCompatibleData([ $field->getName() => $newValue ]);

		return Result::success();
	}

	// todo: refactor duplicate code \Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription::appendComment
	private function appendValueToComment(Field $field, Item $item, string $fieldValue): void
	{
		$localization = Container::getInstance()->getLocalization();
		$localization->loadMessages();

		$copilotSuffix = '[p]' . AIManager::getCopilotName() . PHP_EOL . $fieldValue . '[/p]';

		$oldComment = $item->getComments();
		if (empty($oldComment))
		{
			$item->setComments($copilotSuffix);

			return;
		}

		if (
			str_ends_with($oldComment, "\n\n")
			|| str_ends_with($oldComment, "\r\n\r\n")
			|| str_ends_with($oldComment, "\r\n\n")
			|| str_ends_with($oldComment, "\n\r\n")
		)
		{
			$numberOfLineBreaksToAdd = 0;
		}
		elseif (str_ends_with($oldComment, "\n") || str_ends_with($oldComment, "\r\n"))
		{
			$numberOfLineBreaksToAdd = 1;
		}
		else
		{
			$numberOfLineBreaksToAdd = 2;
		}

		while ($numberOfLineBreaksToAdd > 0)
		{
			$oldComment .= PHP_EOL;
			$numberOfLineBreaksToAdd--;
		}

		$item->setComments($oldComment . $copilotSuffix);
	}

	private function isEditableField(Field $field, Item $item): bool
	{
		if (
			!$field->isValueCanBeChanged()
			|| !$field->isUserField()
			|| !in_array($field->getType(), self::EDITABLE_TYPES, true)
		)
		{
			return false;
		}

		return $field->isMultiple() || $field->isItemValueEmpty($item);
	}

	private function findEnumValueId(Field $field, string $value): ?string
	{
		$enumResult = EnumType::getList($field->getUserField());
		while ($enum = $enumResult->Fetch())
		{
			if ($value === (string)$enum['ID'] || $value === (string)$enum['VALUE'])
			{
				return $enum['ID'];
			}
		}

		return null;
	}

	private function findField(Factory $factory, string $fieldId): ?Field
	{
		foreach ($factory->getFieldsCollection() as $field)
		{
			$userField = $field->getUserField();
			if (
				$field->getName() === $fieldId
				|| ($userField['EDIT_FORM_LABEL'] ?? null) === $fieldId
				|| ($userField['LIST_COLUMN_LABEL'] ?? null) === $fieldId
				|| ($userField['LIST_FILTER_LABEL'] ?? null) === $fieldId
				|| ($userField['FIELD_NAME'] ?? null) === $fieldId
				|| $field->getTitle() === $fieldId
			)
			{
				return $field;
			}
		}

		return null;
	}
}
