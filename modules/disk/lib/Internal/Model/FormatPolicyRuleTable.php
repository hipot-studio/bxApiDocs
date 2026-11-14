<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\SystemException;

/**
 * Class FormatPolicyRuleTable
 *
 * One row per addressee of a private rule of the file format policy. The addressee is unique by
 * its type and id whatever the scope is; ACCESS_CODE is derived from the addressee and is what
 * the rule is matched against the access codes of a user by.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_FormatPolicyRule_Query query()
 * @method static EO_FormatPolicyRule_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_FormatPolicyRule_Result getById($id)
 * @method static EO_FormatPolicyRule_Result getList(array $parameters = [])
 * @method static EO_FormatPolicyRule_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_FormatPolicyRule createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_FormatPolicyRule_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_FormatPolicyRule wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_FormatPolicyRule_Collection wakeUpCollection($rows)
 */
class FormatPolicyRuleTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_format_policy_rule';
	}

	/**
	 * @return array
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('ENTITY_TYPE'))
				->configureRequired()
				->configureSize(16)
			,
			(new IntegerField('ENTITY_ID'))
				->configureRequired()
			,
			(new BooleanField('INCLUDE_NESTED'))
				->configureStorageValues('N', 'Y')
				->configureDefaultValue(false)
			,
			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->configureSize(32)
			,
			(new StringField('MODE'))
				->configureRequired()
				->configureSize(8)
			,
			(new TextField('EXTENSIONS'))
				->configureRequired()
			,
			(new TextField('PRESETS'))
				->configureRequired()
			,
			(new BooleanField('BLOCK_NO_EXTENSION'))
				->configureStorageValues('N', 'Y')
				->configureDefaultValue(false)
			,
			(new IntegerField('SAVED_AT'))
				->configureRequired()
			,
			(new IntegerField('UPDATED_BY'))
				->configureRequired()
			,
		];
	}
}
