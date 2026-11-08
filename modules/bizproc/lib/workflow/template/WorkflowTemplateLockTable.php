<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Class WorkflowTemplateLockTable
 *
 * The editing lease of a template: at most one row per template, granted to a user until EXPIRES_AT.
 * The unique index on TEMPLATE_ID is what makes a second lease impossible — the exclusion is enforced by
 * the database, not by the calling code.
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TEMPLATE_ID int mandatory
 * <li> USER_ID int mandatory
 * <li> ACQUIRED_AT datetime mandatory
 * <li> EXPIRES_AT datetime mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateLock_Query query()
 * @method static EO_WorkflowTemplateLock_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateLock_Result getById($id)
 * @method static EO_WorkflowTemplateLock_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateLock_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateLockTable extends DataManager
{
	use DeleteByFilterTrait;
	// a lease that changed hands in between is never overwritten by whoever held it before
	use UpdateByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_lock';
	}

	/**
	 * Conditional release: the row is dropped only while the filter still matches. The count is what tells a
	 * release that happened from a release that had nothing to take.
	 *
	 * @return int number of rows actually deleted
	 */
	public static function deleteByFilterAndCount(array $filter): int
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();
		$where = Query::buildFilterSql($entity, $filter);

		if ($where === '')
		{
			return 0;
		}

		$connection->queryExecute('DELETE FROM ' . static::getTableName() . " WHERE {$where}");

		return $connection->getAffectedRowsCount();
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new DatetimeField('ACQUIRED_AT'))
				->configureRequired()
			,
			(new DatetimeField('EXPIRES_AT'))
				->configureRequired()
			,
		];
	}
}
