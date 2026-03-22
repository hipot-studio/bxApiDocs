<?php

namespace Bitrix\Crm\RepeatSale\Service\Entity;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\Field;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\RangeValidator;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class RepeatSaleAiScreeningTable extends DataManager
{
	use AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_crm_repeat_sale_ai_screening';
	}

	public static function getObjectClass(): string
	{
		return RepeatSaleAiScreening::class;
	}

	public static function getMap(): array
	{
		Container::getInstance()->getLocalization()->loadMessages();

		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			$fieldRepository->getId(),
			(new IntegerField('OWNER_TYPE_ID'))
				->configureDefaultValue(CCrmOwnerType::Deal)
				->addValidator([self::class, 'validateSupportedTypeId'])
			,
			(new IntegerField('OWNER_ID'))
				->addValidator(new RangeValidator(1))
			,
			(new IntegerField('SEGMENT_ID'))
				->addValidator(new RangeValidator(1))
			,
			(new IntegerField('AI_OPINION'))
				->configureNullable()
			,
			(new StringField('CATEGORY'))
				->configureNullable()
				->configureSize(255)
			,
			(new DatetimeField('DESIRED_CREATION_DATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime())
			,
			(new ArrayField('PARAMS'))
				->configureSerializationJson()
			,
			(new IntegerField('RESULT_ENTITY_TYPE_ID'))
				->configureNullable()
				->addValidator([self::class, 'validateSupportedTypeId'])
			,
			(new IntegerField('RESULT_ENTITY_ID'))
				->configureNullable()
				->addValidator(new RangeValidator(1))
			,
			$fieldRepository->getCreatedTime('CREATED_AT'),
			$fieldRepository->getUpdatedTime('UPDATED_AT'),
		];
	}

	public static function validateSupportedTypeId($value, $primary, array $row, Field $field): bool|string
	{
		$value = (int)$value;
		if (in_array($value, self::getSupportedOwnerTypeId(), true))
		{
			return true;
		}

		return 'Entity owner type is not supported';
	}

	private static function getSupportedOwnerTypeId(): array
	{
		return [
			CCrmOwnerType::Deal,
		];
	}
}
