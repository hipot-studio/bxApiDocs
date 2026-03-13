<?php
namespace Bitrix\Call\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\ORM\Fields\Relations\Reference;

/**
 * Class ConferenceTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Conference_Query query()
 * @method static EO_Conference_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Conference_Result getById($id)
 * @method static EO_Conference_Result getList(array $parameters = [])
 * @method static EO_Conference_Entity getEntity()
 * @method static \Bitrix\Call\Model\EO_Conference createObject($setDefaultValues = true)
 * @method static \Bitrix\Call\Model\EO_Conference_Collection createCollection()
 * @method static \Bitrix\Call\Model\EO_Conference wakeUpObject($row)
 * @method static \Bitrix\Call\Model\EO_Conference_Collection wakeUpCollection($rows)
 */
class ConferenceTable extends DataManager
{
	public static function getTableName(): string
	{
		//todo remove migration option check
		if (\Bitrix\Main\Config\Option::get('call', 'call_db_migrated', 0) >= 5)
		{
			return 'b_call_conference';
		}
		return 'b_im_conference';
	}

	public static function getMap(): array
	{
		return array(
			new IntegerField('ID', array(
				'primary' => true,
				'autocomplete' => true
			)),
			new IntegerField('ALIAS_ID', array(
				'required' => true
			)),
			new CryptoField('PASSWORD', array(
				'crypto_enabled' => static::cryptoEnabled("PASSWORD"),
			)),
			new TextField('INVITATION'),
			new DatetimeField('CONFERENCE_START'),
			new DatetimeField('CONFERENCE_END'),
			new StringField('IS_BROADCAST', array(
				'default_value' => 'N'
			)),
			new Reference(
				'ALIAS',
				'Bitrix\Im\Model\AliasTable',
				array('=this.ALIAS_ID' => 'ref.ID')
			)
		);
	}
}