<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Catalog;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\Catalog\EO_CatalogItem_Query query()
 */
final class CatalogItemTable extends DataManager
{
	public const MAX_URL_LENGTH = 512;

	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_catalog';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('ICON_FILE_ID')),

			(new StringField('TITLE'))
				->configureRequired()
				->configureSize(255)
				->addValidator(new LengthValidator(null, 255)),

			(new IntegerField('CHAT_ID')),

			(new StringField('EXTERNAL_ID'))
				->configureSize(255)
				->addValidator(new LengthValidator(null, 255)),

			(new TextField('DESCRIPTION')),

			(new EnumField('TYPE'))
				->configureValues(CatalogItemType::values())
				->configureRequired(),

			(new StringField('EDIT_URL'))
				->configureSize(self::MAX_URL_LENGTH)
				->addValidator(new LengthValidator(null, self::MAX_URL_LENGTH)),

			(new StringField('VIEW_URL'))
				->configureSize(self::MAX_URL_LENGTH)
				->addValidator(new LengthValidator(null, self::MAX_URL_LENGTH)),

			(new EnumField('ACCESS_TYPE'))
				->configureValues(CatalogItemAccessType::values())
				->configureRequired(),

			(new IntegerField('OWNER_ID'))
				->configureRequired(),

			(new StringField('COLOR'))
				->configureRequired()
				->configureSize(7)
				->addValidator(new LengthValidator(null, 7)),

			(new StringField('PAIRING_ISS'))
				->configureSize(255)
				->addValidator(new LengthValidator(null, 255)),

			(new DatetimeField('DEACTIVATED_AT')),

			(new DatetimeField('CREATED_AT'))
				->configureRequired(),

			(new DatetimeField('UPDATED_AT'))
				->configureRequired(),
		];
	}
}
