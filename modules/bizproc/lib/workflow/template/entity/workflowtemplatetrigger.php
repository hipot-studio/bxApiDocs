<?php

namespace Bitrix\Bizproc\Workflow\Template\Entity;

use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Web\Json;

/**
 * Class WorkflowTemplateTriggerTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateTrigger_Query query()
 * @method static EO_WorkflowTemplateTrigger_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateTrigger_Result getById($id)
 * @method static EO_WorkflowTemplateTrigger_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateTrigger_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateTriggerTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_trigger';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configurePrimary()
				->configureRequired()
			,
			(new ORM\Fields\StringField('TRIGGER_NAME'))
				->configurePrimary()
				->configureRequired()
				->addValidator(new LengthValidator(1, 128))
			,
			(new ORM\Fields\StringField('TRIGGER_TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 128))
			,
			(new ORM\Fields\ArrayField('APPLY_RULES')),
			(new ORM\Fields\StringField('MODULE_ID'))
				->addValidator(new LengthValidator(1, 32))
			,
			(new ORM\Fields\StringField('ENTITY'))
				->addValidator(new LengthValidator(1, 64))
			,
			(new ORM\Fields\StringField('DOCUMENT_TYPE'))
				->addValidator(new LengthValidator(1, 128))
			,
			new ORM\Fields\Relations\Reference(
				'TEMPLATE',
				WorkflowTemplateTable::class,
				ORM\Query\Join::on('this.TEMPLATE_ID', 'ref.ID'),
				['join_type' => 'INNER'],
			),
		];
	}

	public static function onTemplateAdd(int $id, array $template, bool $active = true): void
	{
		self::syncByTemplate($id, $template, $active);
	}

	public static function onTemplateUpdate(int $id): void
	{
		$templateRow = WorkflowTemplateTable::query()
			->where('ID', $id)
			->setSelect(['TEMPLATE', 'ACTIVE'])
			->setLimit(1)
			->fetch()
		;
		$template = $templateRow['TEMPLATE'] ?? [];
		$active = ($templateRow['ACTIVE'] ?? 'Y') === 'Y';
		self::syncByTemplate($id, $template, $active);
	}

	public static function onTemplateDelete(int $id): void
	{
		self::deleteUnused($id, []);

		WorkflowTemplateSectionTable::deleteByTemplate($id, []);
	}

	private static function fillRowFromActivity(\CBPActivity|\IBPTriggerActivity $activity): array
	{
		return [
			'TRIGGER_NAME' => $activity->getName(),
			'TRIGGER_TYPE' => $activity->getType(),
			'APPLY_RULES' => $activity->createApplyRules(),
			'CONFIGURATION' => $activity->getConfigurator(),
		];
	}

	private static function syncByTemplate(int $templateId,	array $template, bool $active = true): void
	{
		if ($template[0]['Type'] !== NodesToTemplate::ROOT_NODE_TYPE)
		{
			return;
		}

		$triggers = self::filterTriggersByActivities($template[0]['Children']);
		if ($active)
		{
			self::deleteUnused($templateId, $triggers);
			static::upsert($templateId, $triggers);
		}
		else
		{
			self::deleteUnused($templateId);
		}
		self::updateSectionsByTriggers($templateId, $triggers);
	}

	/**
	 * @param array $activities
	 *
	 * @return array{
	 *     TRIGGER_NAME: string,
	 *     TRIGGER_TYPE: string,
	 *     APPLY_RULES: array,
	 *     CONFIGURATION: Configurator
	 * }
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public static function filterTriggersByActivities(array $activities): array
	{
		$result = [];
		$triggers = array_filter(
			$activities,
			static fn($activity) => str_ends_with($activity['Type'], 'Trigger')
		);

		if (!$triggers)
		{
			return $result;
		}

		foreach ($triggers as $trigger)
		{
			$isActivated = $trigger['Activated'] ?? 'Y';
			if ($isActivated !== 'Y')
			{
				continue;
			}

			\CBPRuntime::getRuntime()->includeActivityFile($trigger['Type']);
			$triggerInstance = \CBPActivity::createInstance($trigger['Type'], $trigger['Name']);
			if (!($triggerInstance instanceof \IBPTriggerActivity))
			{
				continue; // todo: trigger error?
			}
			$triggerInstance->initializeFromArray($trigger['Properties']);

			$result[] = self::fillRowFromActivity($triggerInstance);
		}

		return $result;
	}

	private static function deleteUnused(int $templateId, array $triggers = []): void
	{
		$query = static::query()
			->setSelect(['TEMPLATE_ID', 'TRIGGER_NAME'])
			->where('TEMPLATE_ID', $templateId)
		;

		$ids = array_column($triggers, 'TRIGGER_NAME');
		if ($ids)
		{
			$query->whereNotIn('TRIGGER_NAME', $ids);
		}
		$iterator = $query->exec();

		while ($row = $iterator->fetch())
		{
			static::delete($row);
		}
	}

	private static function upsert(int $templateId, array $triggers): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = static::getTableName();
		$primary = ['TEMPLATE_ID', 'TRIGGER_NAME'];

		foreach ($triggers as $trigger)
		{
			/** @var $configuration Configurator */
			$configuration = $trigger['CONFIGURATION'];
			[$moduleId, $entity, $documentType] = $configuration->getDocumentComplexType()?->toArray();

			if (!$moduleId || !$entity || !$documentType)
			{
				continue;
			}

			$triggerType = $trigger['TRIGGER_TYPE'];
			$rules = Json::encode($trigger['APPLY_RULES'], 0);
			$insert = [
				'TEMPLATE_ID' => $templateId,
				'TRIGGER_NAME' => $trigger['TRIGGER_NAME'],
				'TRIGGER_TYPE' => $triggerType,
				'APPLY_RULES' => $rules,
				'MODULE_ID' => $moduleId,
				'ENTITY' => $entity,
				'DOCUMENT_TYPE' => $documentType,
			];
			$update = [
				'TRIGGER_TYPE' => $triggerType,
				'APPLY_RULES' => $rules,
				'MODULE_ID' => $moduleId,
				'ENTITY' => $entity,
				'DOCUMENT_TYPE' => $documentType,
			];

			$queries = $sqlHelper->prepareMerge($tableName, $primary, $insert, $update);

			foreach ($queries as $query)
			{
				$connection->queryExecute($query);
			}
		}
	}

	public static function updateSectionsByTriggers(int $templateId, array $triggers): void
	{
		$sections = [];
		foreach ($triggers as $trigger)
		{
			/** @var $configuration Configurator */
			$configuration = $trigger['CONFIGURATION'];
			$section = $configuration->getSection();
			if ($section?->id)
			{
				$sections[$section->id][] = $section;
			}
		}

		self::provideSectionsToTemplate($sections, $templateId);
	}

	/**
	 * @param array<Section> $sections
	 * @param int $templateId
	 *
	 * @return void
	 */
	public static function provideSectionsToTemplate(array $sections, int $templateId): void
	{
		WorkflowTemplateSectionTable::deleteByTemplate($templateId);
		foreach ($sections as $sectionPath)
		{
			foreach ($sectionPath as $section)
			{
				WorkflowTemplateSectionTable::upsert($templateId, $section->id, $section->path ?? null);
			}
		}
	}
}
