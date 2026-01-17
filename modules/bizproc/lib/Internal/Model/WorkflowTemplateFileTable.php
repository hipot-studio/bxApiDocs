<?php

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class WorkflowTemplateFileTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateFile_Query query()
 * @method static EO_WorkflowTemplateFile_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateFile_Result getById($id)
 * @method static EO_WorkflowTemplateFile_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateFile_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateFileTable extends DataManager
{
	use AddMergeTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_file';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configureAutocomplete()
				->configurePrimary()
			,
			(new IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new IntegerField('FILE_ID'))
				->configureRequired()
			,
		];
	}
}