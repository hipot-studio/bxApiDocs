<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Factory\User;

use Bitrix\Intranet\Internal\Entity\User\Field\AddressField;
use Bitrix\Intranet\Internal\Entity\User\Field\BooleanField;
use Bitrix\Intranet\Internal\Entity\User\Field\DateTimeField;
use Bitrix\Intranet\Internal\Entity\User\Field\EmailField;
use Bitrix\Intranet\Internal\Entity\User\Field\FileField;
use Bitrix\Intranet\Internal\Entity\User\Field\LinkField;
use Bitrix\Intranet\Internal\Entity\User\Field\MoneyField;
use Bitrix\Intranet\Internal\Entity\User\Field\NumberField;
use Bitrix\Intranet\Internal\Entity\User\Field\PhoneField;
use Bitrix\Intranet\Internal\Entity\User\Field\DateField;
use Bitrix\Intranet\Internal\Entity\User\Field\SelectField;
use Bitrix\Intranet\Internal\Entity\User\Field\StringField;
use Bitrix\Intranet\Internal\Entity\User\Field\UserField;

class UserFieldTypeMapper
{
	/**
	 * @return class-string|null
	 */
	public function getClassByFieldInfo(array $fieldInfo): ?string
	{
		return $this->getClassByName($fieldInfo['name'])
			?? $this->getClassByType($fieldInfo['type']);
	}

	/**
	 * @return class-string|null
	 */
	public function getClassByType(string $type): ?string
	{
		return match($type)
		{
			'text', 'string', 'string_formatted' => StringField::class,
			'phone' => PhoneField::class,
			'date' => DateField::class,
			'datetime' => DateTimeField::class,
			'list', 'enumeration' => SelectField::class,
			'boolean' => BooleanField::class,
			'double' => NumberField::class,
			'address' => AddressField::class,
			'url', 'link' => LinkField::class,
			'file' => FileField::class,
			'money' => MoneyField::class,
			'email' => EmailField::class,
			default => null,
		};
	}

	/**
	 * @return class-string|null
	 */
	public function getClassByName(string $name): ?string
	{
		return match ($name)
		{
			'EMAIL' => EmailField::class,
			'PERSONAL_PHONE', 'WORK_PHONE' => PhoneField::class,
			'DEPARTMENT_HEAD' => UserField::class,
			'PERSONAL_BIRTHDAY' => DateField::class,
			default => null,
		};
	}
}
