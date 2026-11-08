<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Note\Entity;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class TemplateNoteMessageTable
 *
 * One message of a note discussion. MESSAGE holds the text already reduced to the allowed markup - the
 * store keeps nothing that has not been through it. REVISION grows with every edit and is what an edit
 * is matched against, so a message changed meanwhile is refused instead of being silently overwritten.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateNoteMessage_Query query()
 * @method static EO_TemplateNoteMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateNoteMessage_Result getById($id)
 * @method static EO_TemplateNoteMessage_Result getList(array $parameters = [])
 * @method static EO_TemplateNoteMessage_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection wakeUpCollection($rows)
 */
final class TemplateNoteMessageTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_note_message';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('NOTE_ID'))
				->configureRequired()
			,
			(new IntegerField('AUTHOR_ID'))
				->configureRequired()
			,
			(new TextField('MESSAGE'))
				->configureRequired()
			,
			(new IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(1)
			,
			(new DatetimeField('CREATED'))
				->configureRequired()
			,
			(new DatetimeField('MODIFIED'))
				->configureNullable()
			,
			new Reference(
				'NOTE',
				TemplateNoteTable::class,
				Join::on('this.NOTE_ID', 'ref.ID')
			),
		];
	}
}
