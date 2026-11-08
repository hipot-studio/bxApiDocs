<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment\Entity;

use Bitrix\Bizproc\Workflow\Template\Fragment\Limits;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserTable;

/**
 * Class TemplateFragmentTable
 *
 * A reusable assembly of scheme nodes saved on the portal. The fragment itself is one envelope in
 * FRAGMENT: the document type of its source and the format number live inside it, because no selection
 * asks about them. NODE_COUNT is the exception - it is the only thing the list tells about the content
 * of a fragment, and it saves unpacking the envelope per row.
 *
 * A fragment names no template: it outlives the scheme it was cut from, and deleting that template
 * leaves it untouched.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateFragment_Query query()
 * @method static EO_TemplateFragment_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateFragment_Result getById($id)
 * @method static EO_TemplateFragment_Result getList(array $parameters = [])
 * @method static EO_TemplateFragment_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection wakeUpCollection($rows)
 */
final class TemplateFragmentTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_fragment';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			// The width of the name is held by a validator and not by configureSize(): a size cuts an
			// overlong value off silently while it is being set, and an author who named a fragment must be
			// refused instead of finding the name stored cut in half. The width is the one of the schema.
			(new StringField('NAME'))
				->configureRequired()
				->addValidator(new LengthValidator(1, Limits::MAX_NAME_LENGTH))
			,
			(new IntegerField('AUTHOR_ID'))
				->configureRequired()
			,
			// Y - available to everyone, N - to the author only. Opening the personal fragment of an
			// inactive author is decided on reading and never rewrites this field.
			(new StringField('IS_SHARED'))
				->configureRequired()
				->configureDefaultValue('Y')
				->addValidator(new LengthValidator(1, 1))
			,
			(new IntegerField('NODE_COUNT'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new TextField('FRAGMENT'))
				->configureRequired()
			,
			(new IntegerField('UPDATED_BY'))
				->configureNullable()
			,
			(new DatetimeField('CREATED'))
				->configureRequired()
			,
			(new DatetimeField('MODIFIED'))
				->configureNullable()
			,
			// Whether the author is still active is a condition of the visibility predicate, so it is
			// joined together with the row instead of being asked per row of the answer. A missing user is
			// read as inactive, hence the left join.
			new Reference(
				'AUTHOR',
				UserTable::class,
				Join::on('this.AUTHOR_ID', 'ref.ID'),
				['join_type' => 'LEFT'],
			),
		];
	}
}
