<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Data\AddStrategy\Trait;

/**
 * Class AccessPermissionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AccessPermission_Query query()
 * @method static EO_AccessPermission_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AccessPermission_Result getById($id)
 * @method static EO_AccessPermission_Result getList(array $parameters = [])
 * @method static EO_AccessPermission_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AccessPermission createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AccessPermission_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AccessPermission wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AccessPermission_Collection wakeUpCollection($rows)
 */
class AccessPermissionTable extends DataManager
{
	use Trait\AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_rest_access_permission';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new StringField('ENTITY_TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 50)),
			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 100)),
			(new StringField('PERMISSION'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 50)),
		];
	}
}
