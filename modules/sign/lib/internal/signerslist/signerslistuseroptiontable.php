<?php
namespace Bitrix\Sign\Internal\SignersList;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;

/**
 * Class SignersListUserOptionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SignersListUserOption_Query query()
 * @method static EO_SignersListUserOption_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SignersListUserOption_Result getById($id)
 * @method static EO_SignersListUserOption_Result getList(array $parameters = [])
 * @method static EO_SignersListUserOption_Entity getEntity()
 * @method static \Bitrix\Sign\Internal\SignersList\EO_SignersListUserOption createObject($setDefaultValues = true)
 * @method static \Bitrix\Sign\Internal\SignersList\EO_SignersListUserOption_Collection createCollection()
 * @method static \Bitrix\Sign\Internal\SignersList\EO_SignersListUserOption wakeUpObject($row)
 * @method static \Bitrix\Sign\Internal\SignersList\EO_SignersListUserOption_Collection wakeUpCollection($rows)
 */
class SignersListUserOptionTable extends DataManager
{
	use AddInsertIgnoreTrait;
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_sign_signers_list_user_option';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('LIST_ID'))
				->configurePrimary()
			,
			(new Fields\IntegerField('USER_ID'))
				->configurePrimary()
			,
			(new Fields\IntegerField('OPTION_CODE'))
				->configurePrimary()
			,
			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureNullable(false)
			,
		];
	}
}
