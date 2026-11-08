<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\UserField\Enum;
use Bitrix\Main\UserField\Types;

final class UserField extends Dto
{
	public const SUPPORTED_TYPES = [
		Types\DoubleType::USER_TYPE_ID,
		Types\IntegerType::USER_TYPE_ID,
		Types\StringType::USER_TYPE_ID,
		Types\BooleanType::USER_TYPE_ID,
		Types\DateType::USER_TYPE_ID,
		Types\DateTimeType::USER_TYPE_ID,
		Types\EnumType::USER_TYPE_ID,
		Types\FileType::USER_TYPE_ID,
	];

	public ?string $title = null;
	public ?string $type = null;
	public ?bool $multiple = null;
	/** @var array<Enum>|null  */
	public ?array $enumList = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'enumList' => new Caster\CollectionCaster(new Caster\ObjectCaster(Enum::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'title'),
			new StringField($this, 'title'),
			new NotEmptyField($this, 'title'),

			new RequiredField($this, 'type'),
			new EnumField($this, 'type', self::SUPPORTED_TYPES),

			new RequiredField($this, 'multiple'),
			new EnumField($this, 'multiple', [true, false]),
		];

		$type = $fields['type'] ?? null;
		if ($type === Types\EnumType::USER_TYPE_ID)
		{
			$validators[] = new RequiredField($this, 'enumList');
		}

		return $validators;
	}
}
