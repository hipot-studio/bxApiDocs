<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\StartStat;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class WorkflowStartStatTable
 *
 * Daily buckets of successfully completed manual starts: one row per template and portal day, CNT
 * holding the number of completions. The pair TEMPLATE_ID + STAT_DATE is the primary key, so a
 * completion increments an existing bucket instead of adding a row per run.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowStartStat_Query query()
 * @method static EO_WorkflowStartStat_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowStartStat_Result getById($id)
 * @method static EO_WorkflowStartStat_Result getList(array $parameters = [])
 * @method static EO_WorkflowStartStat_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection wakeUpCollection($rows)
 */
final class WorkflowStartStatTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_start_stat';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('TEMPLATE_ID'))
				->configurePrimary()
			,
			(new DateField('STAT_DATE'))
				->configurePrimary()
			,
			(new IntegerField('CNT'))
				->configureDefaultValue(0)
			,
		];
	}
}
