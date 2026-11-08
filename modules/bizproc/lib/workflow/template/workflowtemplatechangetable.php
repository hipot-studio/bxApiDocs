<?php

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeAuthorType;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class WorkflowTemplateChangeTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateChange_Query query()
 * @method static EO_WorkflowTemplateChange_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateChange_Result getById($id)
 * @method static EO_WorkflowTemplateChange_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateChange_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateChangeTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_change';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('EVENT_TYPE'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('USER_ID'))
				->configureNullable()
			,
			(new ORM\Fields\DatetimeField('CREATED'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('VERSION_NUMBER'))
				->configureNullable()
			,
			(new ORM\Fields\IntegerField('PUBLICATION_TYPE'))
				->configureNullable()
			,
			(new ORM\Fields\ArrayField('SNAPSHOT'))
				->configureNullable()
				->configureSerializeCallback([Entity\WorkflowTemplateTable::class, 'toSerializedForm'])
				->configureUnserializeCallback([Entity\WorkflowTemplateTable::class, 'getFromSerializedForm'])
			,
			// Scalar mirror of SNAPSHOT: a blob is out of reach of an index, and the cleanup asks for the
			// events without a body on every pass.
			(new ORM\Fields\BooleanField('HAS_SNAPSHOT'))
				->configureValues('N', 'Y')
				->configureDefaultValue(false)
			,
			(new ORM\Fields\DatetimeField('TEMPLATE_MODIFIED'))
				->configureNullable()
			,
			(new ORM\Fields\IntegerField('AUTHOR_TYPE'))
				->configureRequired()
				->configureDefaultValue(TemplateChangeAuthorType::Human->value)
			,
			(new ORM\Fields\StringField('AI_TOOL'))
				->configureNullable()
				->configureSize(50)
			,
			(new ORM\Fields\IntegerField('INITIATOR_USER_ID'))
				->configureNullable()
			,
			(new ORM\Fields\StringField('REQUEST_SOURCE'))
				->configureNullable()
				->configureSize(20)
			,
			(new ORM\Fields\ArrayField('CHANGES'))
				->configureNullable()
				->configureSerializeCallback([Entity\WorkflowTemplateTable::class, 'toSerializedForm'])
				->configureUnserializeCallback([Entity\WorkflowTemplateTable::class, 'getFromSerializedForm'])
			,
			(new ORM\Fields\ArrayField('CHANGES_SUMMARY'))
				->configureNullable()
				->configureSerializeCallback([Entity\WorkflowTemplateTable::class, 'toSerializedForm'])
				->configureUnserializeCallback([Entity\WorkflowTemplateTable::class, 'getFromSerializedForm'])
			,
			new ORM\Fields\Relations\Reference(
				'TEMPLATE',
				Entity\WorkflowTemplateTable::class,
				ORM\Query\Join::on('this.TEMPLATE_ID', 'ref.ID')
			),
		];
	}
}
