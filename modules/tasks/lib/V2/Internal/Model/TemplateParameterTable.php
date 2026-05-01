<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Tasks\Internals\Task\TemplateTable;

/**
 * Class TemplateParameterTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateParameter_Query query()
 * @method static EO_TemplateParameter_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateParameter_Result getById($id)
 * @method static EO_TemplateParameter_Result getList(array $parameters = [])
 * @method static EO_TemplateParameter_Entity getEntity()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TemplateParameter createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TemplateParameter_Collection createCollection()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TemplateParameter wakeUpObject($row)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TemplateParameter_Collection wakeUpCollection($rows)
 */
class TemplateParameterTable extends DataManager
{
	use DeleteByFilterTrait;
	use AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_template_parameter';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('TEMPLATE_ID'))
				->configureRequired(),

			(new IntegerField('CODE'))
				->configureRequired(),

			(new StringField('VALUE'))
				->configureNullable(),

			(new Reference(
				'TEMPLATE',
				TemplateTable::class,
				Join::on('this.TEMPLATE_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_LEFT),
		];
	}

	protected static function getInsertIgnoreStrategy(): AddStrategy\Contract\AddStrategy
	{
		return new AddStrategy\InsertIgnore(
			static::getEntity(),
			['TEMPLATE_ID', 'CODE', 'VALUE'],
		);
	}
}
