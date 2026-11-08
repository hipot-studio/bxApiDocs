<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Note\Entity;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class TemplateNoteTable
 *
 * A discussion pinned to the scheme of a workflow template. The anchor says what the note is about and
 * is never rewritten: a node keeps its own id, a link keeps the normalized quadruple of its ends, and a
 * free point keeps neither. The place on the canvas is kept twice on purpose - the offset carries the
 * note along with a moved node, the absolute point leaves it where it was when the node is gone.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateNote_Query query()
 * @method static EO_TemplateNote_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateNote_Result getById($id)
 * @method static EO_TemplateNote_Result getList(array $parameters = [])
 * @method static EO_TemplateNote_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection wakeUpCollection($rows)
 */
final class TemplateNoteTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_note';
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
			(new IntegerField('AUTHOR_ID'))
				->configureRequired()
			,
			// The width of every part of the anchor is held by a validator and not by configureSize(): a size
			// cuts an overlong value off silently while it is being set, and an anchor that is never
			// rewritten must be refused instead of stored cut in half. The widths are the ones of the schema.
			// N - a node, C - a link between nodes, F - a free point of the canvas
			(new StringField('ANCHOR_TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 1))
			,
			(new StringField('ANCHOR_NODE_ID'))
				->configureNullable()
				->addValidator(new LengthValidator(0, 128))
			,
			(new StringField('ANCHOR_SOURCE_BLOCK_ID'))
				->configureNullable()
				->addValidator(new LengthValidator(0, 128))
			,
			(new StringField('ANCHOR_SOURCE_PORT_ID'))
				->configureNullable()
				->addValidator(new LengthValidator(0, 128))
			,
			(new StringField('ANCHOR_TARGET_BLOCK_ID'))
				->configureNullable()
				->addValidator(new LengthValidator(0, 128))
			,
			(new StringField('ANCHOR_TARGET_PORT_ID'))
				->configureNullable()
				->addValidator(new LengthValidator(0, 128))
			,
			(new IntegerField('OFFSET_X'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new IntegerField('OFFSET_Y'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new IntegerField('POSITION_X'))
				->configureRequired()
			,
			(new IntegerField('POSITION_Y'))
				->configureRequired()
			,
			(new DatetimeField('CREATED'))
				->configureRequired()
			,
			new Reference(
				'TEMPLATE',
				WorkflowTemplateTable::class,
				Join::on('this.TEMPLATE_ID', 'ref.ID')
			),
		];
	}
}
