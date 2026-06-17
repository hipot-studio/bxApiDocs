<?php

namespace Bitrix\Bizproc\Workflow\Template\Entity;

use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class WorkflowTemplateUserDataTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateUserData_Query query()
 * @method static EO_WorkflowTemplateUserData_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateUserData_Result getById($id)
 * @method static EO_WorkflowTemplateUserData_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateUserData_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateUserDataTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_user_data';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configureRequired(),
			(new ORM\Fields\StringField('ENTITY_ID'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 50)),
			(new ORM\Fields\StringField('TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 50)),
			(new ORM\Fields\StringField('NAME'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 100)),
			(new ORM\Fields\StringField('VALUE'))
				->addValidator(new LengthValidator(null, 255)),
		];
	}
}
