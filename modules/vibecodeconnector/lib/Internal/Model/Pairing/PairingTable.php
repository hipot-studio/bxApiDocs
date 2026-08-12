<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Pairing;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\Pairing\EO_Pairing_Query query()
 */
final class PairingTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_pairing';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('ISS'))
				->configureRequired()
				->configureSize(255)
				->addValidator(new LengthValidator(null, 255)),

			(new TextField('PUBLIC_KEY'))
				->configureRequired(),

			(new StringField('PORTAL_ID'))
				->configureRequired()
				->configureSize(255)
				->addValidator(new LengthValidator(null, 255)),

			(new StringField('ENDPOINT_URL'))
				->configureRequired()
				->configureSize(512)
				->addValidator(new LengthValidator(null, 512)),

			(new DatetimeField('FETCHED_AT'))
				->configureRequired(),

			(new DatetimeField('EXPIRES_AT'))
				->configureRequired(),

			(new IntegerField('PUBLIC_KEY_TTL'))
				->configureDefaultValue(0),

			(new StringField('KEY_SOURCE'))
				->configureRequired()
				->configureSize(20)
				->addValidator(new LengthValidator(null, 20)),
		];
	}
}
