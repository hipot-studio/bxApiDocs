<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Entity;

use Bitrix\Main\Application;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Class WorkflowTemplateSectionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateSection_Query query()
 * @method static EO_WorkflowTemplateSection_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateSection_Result getById($id)
 * @method static EO_WorkflowTemplateSection_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateSection_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateSectionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_section';
	}

	public static function getObjectClass(): string
	{
		return TemplateSection::class;
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configureRequired()
				->configurePrimary()
			,
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('SECTION_ID'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 255))
			,
			(new ORM\Fields\StringField('PATH'))
			,
			(new ORM\Fields\DatetimeField('DATE_MODIFY'))
				->configureDefaultValue(static fn() => new DateTime())
			,
			new ORM\Fields\Relations\Reference(
				'TEMPLATE',
				WorkflowTemplateTable::class,
				ORM\Query\Join::on('this.TEMPLATE_ID', 'ref.ID'),
				['join_type' => 'INNER'],
			),
		];
	}

	public static function upsert(int $templateId, string $sectionId, ?string $path = null): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$table = static::getTableName();

		$now = new DateTime();
		$insert = [
			'TEMPLATE_ID' => $templateId,
			'SECTION_ID' => $sectionId,
			'PATH' => $path,
			'DATE_MODIFY' => $now,
		];

		$update = [
			'DATE_MODIFY' => $now,
		];
		[$sql] = $sqlHelper->prepareMerge($table, ['TEMPLATE_ID', 'SECTION_ID', 'PATH'], $insert, $update);
		$connection->queryExecute($sql);
	}

	public static function deleteByTemplate(int $templateId): void
	{
		$query = static::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
		;

		while ($row = $query->fetch())
		{
			static::delete(
				[
					'ID' => $row['ID'],
				],
			);
		}
	}
}
