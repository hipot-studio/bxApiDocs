<?php

namespace Bitrix\Tasks\Internals\Task;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\Relations\OneToMany;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateCollection;
use Bitrix\Tasks\Internals\Task\Template\TemplateDependenceTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\Template\TemplateTagTable;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class TemplateTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Template_Query query()
 * @method static EO_Template_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Template_Result getById($id)
 * @method static EO_Template_Result getList(array $parameters = [])
 * @method static EO_Template_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateCollection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateObject wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateCollection wakeUpCollection($rows)
 */
class TemplateTable extends DataManager
{
	public static function getObjectClass(): string
	{
		return TemplateObject::class;
	}

	public static function getCollectionClass(): string
	{
		return TemplateCollection::class;
	}

	public static function getUfId(): string
	{
		return UserField::TEMPLATE;
	}

	public static function getTableName(): string
	{
		return 'b_tasks_template';
	}

	public static function getClass(): string
	{
		return static::class;
	}

	/**
	 * Returns entity map definition in object-style (fields + relations combined).
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('TITLE'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 255)),

			(new TextField('DESCRIPTION')),

			(new BooleanField('DESCRIPTION_IN_BBCODE'))
				->configureValues('N', 'Y')
				->configureDefaultValue('Y'),

			new EnumField('PRIORITY', [
				'values' => array_merge(
					array_values(Priority::getAll()),
					array_map('strval', array_values(Priority::getAll()))
				),
				'default_value' => (string)Priority::AVERAGE,
			]),

			(new StringField('STATUS'))
				->configureDefaultValue('1')
				->addValidator(new LengthValidator(null, 1)),

			(new IntegerField('RESPONSIBLE_ID'))
				->configureRequired(),

			(new IntegerField('TIME_ESTIMATE'))
				->configureDefaultValue('0'),

			(new BooleanField('REPLICATE'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new IntegerField('CREATED_BY'))
				->configureRequired(),

			(new StringField('XML_ID'))
				->addValidator(new LengthValidator(null, 50)),

			(new BooleanField('ALLOW_CHANGE_DEADLINE'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new BooleanField('ALLOW_TIME_TRACKING'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new BooleanField('TASK_CONTROL'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new BooleanField('ADD_IN_REPORT'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new BooleanField('MATCH_WORK_TIME'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new IntegerField('GROUP_ID')),

			(new IntegerField('PARENT_ID')),

			(new BooleanField('MULTITASK'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new StringField('SITE_ID'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 2)),

			(new TextField('REPLICATE_PARAMS')),

			(new TextField('TAGS')),

			(new TextField('ACCOMPLICES')),

			(new TextField('AUDITORS')),

			(new TextField('RESPONSIBLES')),

			(new TextField('DEPENDS_ON')),

			(new IntegerField('DEADLINE_AFTER')),

			(new IntegerField('START_DATE_PLAN_AFTER')),

			(new IntegerField('END_DATE_PLAN_AFTER')),

			(new IntegerField('TASK_ID')),

			(new IntegerField('TPARAM_TYPE')),

			(new IntegerField('TPARAM_REPLICATION_COUNT'))
				->configureDefaultValue(0),

			(new TextField('ZOMBIE'))
				->configureDefaultValue('N'),

			(new Reference(
				'CREATOR',
				UserTable::getEntity(),
				Join::on('this.CREATED_BY', 'ref.ID')
			))
				->configureJoinType(Join::TYPE_LEFT),

			(new Reference(
				'RESPONSIBLE',
				UserTable::getEntity(),
				Join::on('this.RESPONSIBLE_ID', 'ref.ID')
			))
				->configureJoinType(Join::TYPE_LEFT),

			(new OneToMany(
				'MEMBERS',
				TemplateMemberTable::class,
				'TEMPLATE'
			)),

			(new OneToMany(
				'TAG_LIST',
				TemplateTagTable::class,
				'TEMPLATE'
			)),

			(new OneToMany(
				'DEPENDENCIES',
				TemplateDependenceTable::class,
				'TEMPLATE'
			)),

			(new Reference(
				'SCENARIO',
				\Bitrix\Tasks\Internals\Task\Template\ScenarioTable::class,
				Join::on('this.ID', 'ref.TEMPLATE_ID')
			))->configureJoinType(Join::TYPE_LEFT),

			(new Reference(
				'CHECKLIST_DATA',
				\Bitrix\Tasks\Internals\Task\Template\CheckListTable::getEntity(),
				['this.ID' => 'ref.TEMPLATE_ID'],
			))->configureJoinType(Join::TYPE_LEFT),

			(new Reference(
				'PARENT',
				\Bitrix\Tasks\Internals\Task\Template\DependenceTable::class,
				Join::on('this.ID', 'ref.TEMPLATE_ID')
			))->configureJoinType(Join::TYPE_LEFT),

			// deprecated
			(new StringField('FILES')),
		];
	}
}
