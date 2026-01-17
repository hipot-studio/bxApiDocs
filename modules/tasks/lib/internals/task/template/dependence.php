<?php

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Tasks\Internals\DataBase\Tree;
use Bitrix\Tasks\Internals\Task\TemplateTable;

/**
 * Class DependenceTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Dependence_Query query()
 * @method static EO_Dependence_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Dependence_Result getById($id)
 * @method static EO_Dependence_Result getList(array $parameters = [])
 * @method static EO_Dependence_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_Dependence createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_Dependence_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_Dependence wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_Dependence_Collection wakeUpCollection($rows)
 */
class DependenceTable extends Tree
{
	public static function getTableName(): string
	{
		return 'b_tasks_template_dep';
	}

	public static function getIDColumnName(): string
	{
		return 'TEMPLATE_ID';
	}

	public static function getPARENTIDColumnName(): string
	{
		return 'PARENT_TEMPLATE_ID';
	}

	public static function getClass(): string
	{
		return static::class;
	}

	public static function getMap(): array
	{
		$map = [
			(new IntegerField('TEMPLATE_ID'))
				->configurePrimary(),

			(new IntegerField('PARENT_TEMPLATE_ID'))
				->configurePrimary(),

			(new Reference('TEMPLATE', TemplateTable::getEntity(), Join::on('this.TEMPLATE_ID', 'ref.ID')))
				->configureJoinType(Join::TYPE_INNER),

			(new Reference('PARENT_TEMPLATE', TemplateTable::getEntity(), Join::on('this.PARENT_TEMPLATE_ID', 'ref.ID')))
				->configureJoinType(Join::TYPE_INNER),
		];

		$parentMap = parent::getMap(self::class);

		return array_merge($map, $parentMap);
	}
}
