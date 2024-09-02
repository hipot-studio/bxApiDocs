<?php

namespace Bitrix\AI\Model;

use Bitrix\AI\Entity\Prompt;
use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\Relations\ManyToMany;

/**
 * Class PromptTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Prompt_Query query()
 * @method static EO_Prompt_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Prompt_Result getById($id)
 * @method static EO_Prompt_Result getList(array $parameters = [])
 * @method static EO_Prompt_Entity getEntity()
 * @method static \Bitrix\AI\Entity\Prompt createObject($setDefaultValues = true)
 * @method static \Bitrix\AI\Model\EO_Prompt_Collection createCollection()
 * @method static \Bitrix\AI\Entity\Prompt wakeUpObject($row)
 * @method static \Bitrix\AI\Model\EO_Prompt_Collection wakeUpCollection($rows)
 */
class PromptTable extends Entity\DataManager
{
	/**
	 * Returns DB table name for entity.
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_ai_prompt';
	}

	public static function getObjectClass()
	{
		return Prompt::class;
	}

	/**
	 * Returns entity map definition.
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			new Entity\IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new Entity\StringField('APP_CODE'),
			new Entity\IntegerField('PARENT_ID'),
			(new ArrayField('CATEGORY'))
				->configureSerializationJson(),
			(new ArrayField('CACHE_CATEGORY'))
				->configureSerializationJson(),
			new Entity\StringField('SECTION'),
			new Entity\IntegerField('SORT'),
			new Entity\StringField('CODE', [
				'required' => true,
			]),
			new Entity\EnumField('TYPE', [
				'required' => false,
				'values' => ['DEFAULT', 'SIMPLE_TEMPLATE'],
			]),
			new Entity\StringField('ICON'),
			new Entity\StringField('HASH', [
				'required' => true,
			]),
			new Entity\StringField('PROMPT'),
			(new ArrayField('TRANSLATE', [
				'default_value' => '',
			]))->configureSerializationJson(),
			(new ArrayField('TEXT_TRANSLATES', [
				'default_value' => '',
			]))->configureSerializationJson(),
			(new ManyToMany('ROLES', RoleTable::class))
				->configureTableName('b_ai_role_prompt'),
			(new ArrayField('SETTINGS'))
				->configureSerializationJson(),
			new Entity\StringField('WORK_WITH_RESULT'),
			(new BooleanField('IS_NEW'))
				->configureValues(0, 1)
				->configureDefaultValue(0),
			new Entity\StringField('IS_SYSTEM'),
			new Entity\DatetimeField('DATE_MODIFY'),
		];
	}

}
