<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class IntranetUserTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_IntranetUser_Query query()
 * @method static EO_IntranetUser_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_IntranetUser_Result getById($id)
 * @method static EO_IntranetUser_Result getList(array $parameters = [])
 * @method static EO_IntranetUser_Entity getEntity()
 * @method static \Bitrix\Intranet\Internal\Model\EO_IntranetUser createObject($setDefaultValues = true)
 * @method static \Bitrix\Intranet\Internal\Model\EO_IntranetUser_Collection createCollection()
 * @method static \Bitrix\Intranet\Internal\Model\EO_IntranetUser wakeUpObject($row)
 * @method static \Bitrix\Intranet\Internal\Model\EO_IntranetUser_Collection wakeUpCollection($rows)
 */
final class IntranetUserTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_intranet_user';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_ID'))
				->configureUnique()
				->configureRequired()
			,
			(new BooleanField('INITIALIZED'))
				->configureRequired()
				->configureDefaultValue('N')
				->configureValues('N', 'Y')
			,
		];
	}
}
