<?php

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Tasks\Internals\TaskDataManager;
use Bitrix\Tasks\Internals\Task\TemplateTable;

/**
 * Class TemplateDependenceTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateDependence_Query query()
 * @method static EO_TemplateDependence_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateDependence_Result getById($id)
 * @method static EO_TemplateDependence_Result getList(array $parameters = [])
 * @method static EO_TemplateDependence_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateDependence createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateDependence_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateDependence wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateDependence_Collection wakeUpCollection($rows)
 */
class TemplateDependenceTable extends TaskDataManager
{
	use DeleteByFilterTrait;
	use AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_template_dependence';
	}

	public static function getClass(): string
	{
		return static::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(),

			(new IntegerField('TEMPLATE_ID')),

			(new IntegerField('DEPENDS_ON_ID')),

			(new Reference('TEMPLATE', TemplateTable::getEntity(), Join::on('this.TEMPLATE_ID', 'ref.ID'))),

			(new Reference('DEPENDS_ON', TemplateTable::getEntity(), Join::on('this.DEPENDS_ON_ID', 'ref.ID'))),
		];
	}
}
