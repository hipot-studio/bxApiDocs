<?php

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Tasks\Internals\TaskDataManager;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Main\UserTable;

/**
 * Class TemplateTagTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateTag_Query query()
 * @method static EO_TemplateTag_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateTag_Result getById($id)
 * @method static EO_TemplateTag_Result getList(array $parameters = [])
 * @method static EO_TemplateTag_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateTag createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateTag_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateTag wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\Template\EO_TemplateTag_Collection wakeUpCollection($rows)
 */
class TemplateTagTable extends TaskDataManager
{
	use DeleteByFilterTrait;
	use AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_template_tag';
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

			(new IntegerField('USER_ID')),

			(new StringField('NAME'))
				->addValidator(new LengthValidator(null, 255)),

			(new Reference('TEMPLATE', TemplateTable::getEntity(), Join::on('this.TEMPLATE_ID', 'ref.ID'))),

			(new Reference('USER', UserTable::getEntity(), Join::on('this.USER_ID', 'ref.ID'))),

			(new ExpressionField(
				'MAX_ID',
				'MAX(%s)', ['ID']
			)),
		];
	}
}
