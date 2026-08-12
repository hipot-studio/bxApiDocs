<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\User;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\User\EO_UserAttributes_Query query()
 */
final class UserAttributesTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_user_attributes';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new DatetimeField('CREATED_AT'))
				->configureRequired(),

			(new DatetimeField('CATALOG_FIRST_OPENED_AT')),
		];
	}
}
