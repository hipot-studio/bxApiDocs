<?php

namespace Bitrix\Intranet\Internal\Factory\User;

use Bitrix\Intranet\Exception\UserFieldTypeException;
use Bitrix\Intranet\Internal\Entity\UserField\UserField;
use Bitrix\Intranet\Internal\Entity\UserField\DateField;
use Bitrix\Intranet\Internal\Entity\UserField\SelectField;
use Bitrix\Intranet\Internal\Entity\UserField\StringField;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Date;

class UserFieldFactory
{
	/**
	 * @throws ArgumentException
	 * @throws UserFieldTypeException
	 */
	public function createUserFieldByArray(array $fieldInfo, mixed $value = null): UserField
	{
		if (
			empty($fieldInfo['type'])
			|| empty($fieldInfo['name'])
			|| empty($fieldInfo['title'])
		)
		{
			throw new ArgumentException("User field required type, name, title");
		}

		if ($fieldInfo['type'] === 'userField')
		{
			$userFieldInfo = $fieldInfo['data']['fieldInfo'];

			return $this->createUserFieldByArray(
				[
					'type' => $userFieldInfo['USER_TYPE_ID'],
					'name' => $userFieldInfo['FIELD'],
					'title' => $fieldInfo['name'],
					'editable' => $fieldInfo['editable'] ?? false,
					'showAlways' => $fieldInfo['showAlways'] ?? false,
				],
				$value['VALUE'] ?? null,
			);
		}

		$fieldClassName = $this->getUserFieldClassByType($fieldInfo['type']);

		if ($fieldClassName === SelectField::class)
		{
			return $this->createSelectField($fieldInfo, $value);
		}

		if ($fieldClassName === DateField::class)
		{
			return $this->createDateField($fieldInfo, $value);
		}

		return new $fieldClassName(
			id: $fieldInfo['name'],
			value: $value,
			title: $fieldInfo['title'],
			isEditable: $fieldInfo['editable'] ?? false,
			isShowAlways: $fieldInfo['showAlways'] ?? false,
		);
	}

	/**
	 * @return class-string<UserField>
	 * @throws UserFieldTypeException
	 */
	private function getUserFieldClassByType(string $type): string
	{
		return match($type)
		{
			'text', 'link', 'phone', 'string', 'string_formatted' => StringField::class,
			'date', 'datetime' => DateField::class,
			'list' => SelectField::class,
			default => throw new UserFieldTypeException("Wrong user field type: $type"),
		};
	}

	/**
	 * @throws ArgumentException
	 */
	private function createSelectField(array $fieldInfo, mixed $value): SelectField
	{
		if (empty($fieldInfo['data']['items']))
		{
			throw new ArgumentException("Selectable user field required items");
		}

		$items = [];

		foreach ($fieldInfo['data']['items'] as $item)
		{
			$items[$item['VALUE']] = $item['NAME'];
		}

		return new SelectField(
			id: $fieldInfo['name'],
			title: $fieldInfo['title'],
			isEditable: $fieldInfo['editable'] ?? false,
			isShowAlways: $fieldInfo['showAlways'] ?? false,
			items: $items,
			value: $value,
		);
	}

	private function createDateField(array $fieldInfo, mixed $value): DateField
	{
		if (is_string($value) && !empty($value))
		{
			try
			{
				$value = new Date($value);
			}
			catch (ObjectException)
			{
			}
		}

		return new DateField(
			id: $fieldInfo['name'],
			title: $fieldInfo['title'],
			isEditable: $fieldInfo['editable'] ?? false,
			isShowAlways: $fieldInfo['showAlways'] ?? false,
			value: $value,
		);
	}
}
