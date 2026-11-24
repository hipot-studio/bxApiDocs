<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Text\Emoji;

/**
 * Class RowRuleTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RowRule_Query query()
 * @method static EO_RowRule_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RowRule_Result getById($id)
 * @method static EO_RowRule_Result getList(array $parameters = [])
 * @method static EO_RowRule_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_RowRule createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_RowRule_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_RowRule wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_RowRule_Collection wakeUpCollection($rows)
 */
final class RowRuleTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_biconnector_rls_rule';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new StringField('TABLE_CODE'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 250)),
			(new StringField('FIELD_NAME'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 512)),
			(new TextField('VALUE_DATA'))
				->configureRequired()
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),
			(new TextField('DESCRIPTION'))
				->configureNullable()
				->addSaveDataModifier([self::class, 'encodeNullableEmoji'])
				->addFetchDataModifier([self::class, 'decodeNullableEmoji']),
			(new IntegerField('CREATED_BY_ID'))
				->configureNullable(),
			(new IntegerField('UPDATED_BY_ID'))
				->configureNullable(),
			(new DatetimeField('DATE_CREATE'))
				->configureRequired(),
			(new DatetimeField('DATE_MODIFY'))
				->configureRequired(),
		];
	}

	/**
	 * Emoji::encode() of a null gives an empty string; an absent description stays null.
	 */
	public static function encodeNullableEmoji(?string $value): ?string
	{
		return $value === null ? null : Emoji::encode($value);
	}

	public static function decodeNullableEmoji(?string $value): ?string
	{
		return $value === null ? null : Emoji::decode($value);
	}
}
