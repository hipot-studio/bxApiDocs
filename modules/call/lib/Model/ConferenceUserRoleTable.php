<?php
namespace Bitrix\Call\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class ConferenceUserRoleTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ConferenceUserRole_Query query()
 * @method static EO_ConferenceUserRole_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ConferenceUserRole_Result getById($id)
 * @method static EO_ConferenceUserRole_Result getList(array $parameters = [])
 * @method static EO_ConferenceUserRole_Entity getEntity()
 * @method static \Bitrix\Call\Model\EO_ConferenceUserRole createObject($setDefaultValues = true)
 * @method static \Bitrix\Call\Model\EO_ConferenceUserRole_Collection createCollection()
 * @method static \Bitrix\Call\Model\EO_ConferenceUserRole wakeUpObject($row)
 * @method static \Bitrix\Call\Model\EO_ConferenceUserRole_Collection wakeUpCollection($rows)
 */
class ConferenceUserRoleTable extends DataManager
{
	public static function getTableName(): string
	{
		//todo remove migration option check
		if (\Bitrix\Main\Config\Option::get('call', 'call_db_migrated', 0) >= 5)
		{
			return 'b_call_conference_user_role';
		}
		return 'b_im_conference_user_role';
	}

	public static function getMap(): array
	{
		return array(
			new IntegerField('CONFERENCE_ID', [
				'primary' => true
			]),
			new IntegerField('USER_ID', [
				'primary' => true
			]),
			new StringField('ROLE'),
		);
	}
}