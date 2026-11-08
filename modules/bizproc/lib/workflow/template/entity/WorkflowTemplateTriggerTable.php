<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Entity;

use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduledTriggerSyncService;
use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
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
	private const EMPTY_APPLY_RULES_JSON = '[]';

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_trigger';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configurePrimary()
				->configureRequired(),
			(new ORM\Fields\StringField('TRIGGER_NAME'))
				->configurePrimary()
				->configureRequired()
				->addValidator(new LengthValidator(1, 128)),
			(new ORM\Fields\StringField('TRIGGER_TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 128)),
			(new ORM\Fields\ArrayField('APPLY_RULES')),
			(new ORM\Fields\StringField('MODULE_ID'))
				->addValidator(new LengthValidator(1, 32)),
			(new ORM\Fields\StringField('ENTITY'))
				->addValidator(new LengthValidator(1, 64)),
			(new ORM\Fields\StringField('DOCUMENT_TYPE'))
				->addValidator(new LengthValidator(1, 128)),
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
		self::deleteUnused($id);

		WorkflowTemplateSectionTable::deleteByTemplate($id);

		ServiceLocator::getInstance()->get(ScheduledTriggerSyncService::class)
					  ->syncByTemplate($id)
		;
	}

	/**
	 * Tells a row whose rules were never built. Empty rules are stored as '[]' (the json encoding of an empty
	 * array), the null and '' checks cover historic rows. The resync selects the rows to repair by the same
	 * condition, so both the selection and the write share one definition of "no rules stored".
	 *
	 * @return array{0: string, 1: list<string>} sql condition and its parameters
	 */
	public static function getEmptyApplyRulesCondition(): array
	{
		return [
			'(APPLY_RULES IS NULL OR APPLY_RULES = ?s OR APPLY_RULES = ?s)',
			['', self::EMPTY_APPLY_RULES_JSON],
		];
	}

	/**
	 * Rebuilds the trigger rows of a stored template without touching its sections and schedules.
	 *
	 * APPLY_RULES is a derived column written only on template add and update, so a fixed activity class
	 * does not repair the rules already stored on a portal. onTemplateUpdate() cannot be reused for that:
	 * it also rewrites the sections and resyncs the schedules - an extra write and a risk for the schedule
	 * agents. Orphan rows are left in place on purpose: a template that fails to parse must not lose them.
	 *
	 * An inactive template is skipped: syncByTemplate() stores no trigger rows for such a template, so a write
	 * here would return its triggers to the start selections. The rows are not deleted either - that write
	 * belongs to the template update path, not to a repair of the rules.
	 *
	 * Every trigger node of the template is rebuilt, not only the rows a caller selected the template by: the
	 * rules always come from the whole template body.
	 */
	public static function resyncApplyRules(int $templateId): void
	{
		$templateRow = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(['TEMPLATE', 'ACTIVE'])
			->setLimit(1)
			->fetch()
		;

		// The template may be gone: a trigger row outlives a template deleted outside the sync path, and a
		// stepper selects its cursor long before it processes the step.
		if (!is_array($templateRow) || ($templateRow['ACTIVE'] ?? 'N') !== 'Y')
		{
			return;
		}

		$template = $templateRow['TEMPLATE'] ?? [];
		if (($template[0]['Type'] ?? null) !== NodesToTemplate::ROOT_NODE_TYPE)
		{
			return;
		}

		self::repairApplyRules($templateId, self::buildTriggersToResync($template[0]['Children'] ?? []));
	}

	/**
	 * Writes the rebuilt columns of the rows that still carry no rules, and only those.
	 *
	 * The repair runs in the background and races the regular sync of a template save: both write the same row
	 * by the same primary key, and neither path locks it. A merge would win that race with a snapshot read
	 * before the save, rolling the row back to the reaction mode and the target document the user has just
	 * replaced - and a row with rules of its own never comes back to the selection, so the next save is what
	 * fixes it. Hence the condition of the selection is repeated in the write itself.
	 *
	 * An UPDATE also keeps the repair a repair: a row deleted by a parallel template save is not resurrected,
	 * and a node the table knows nothing about gets no row - storing rows belongs to the sync path.
	 */
	private static function repairApplyRules(int $templateId, array $triggers): void
	{
		$connection = Application::getConnection();
		[$emptyRulesCondition, $emptyRulesParameters] = self::getEmptyApplyRulesCondition();

		foreach ($triggers as $trigger)
		{
			$columns = self::buildRowColumns($trigger);
			if ($columns === null)
			{
				continue;
			}

			$sql = (new SqlExpression(
				'UPDATE ?# SET TRIGGER_TYPE = ?s, APPLY_RULES = ?s, MODULE_ID = ?s, ENTITY = ?s,'
				. ' DOCUMENT_TYPE = ?s WHERE TEMPLATE_ID = ?i AND TRIGGER_NAME = ?s AND ' . $emptyRulesCondition,
				static::getTableName(),
				$columns['TRIGGER_TYPE'],
				$columns['APPLY_RULES'],
				$columns['MODULE_ID'],
				$columns['ENTITY'],
				$columns['DOCUMENT_TYPE'],
				$templateId,
				$trigger['TRIGGER_NAME'],
				...$emptyRulesParameters,
			))->compile();

			$connection->queryExecute($sql);
		}
	}

	/**
	 * A repair must not cost the template more than it fixes, so it differs from the regular build twice.
	 *
	 * The nodes are built one by one: a node whose activity class is gone or throws would otherwise take the
	 * whole template with it, including the very rows the repair is about.
	 *
	 * A trigger that builds no rules is dropped instead of being written: empty rules are read as "applies to
	 * any document", and an activity file older than this repair - the module owning it is updated on its own
	 * schedule - would replace the stored rules with exactly that.
	 */
	private static function buildTriggersToResync(array $nodes): array
	{
		$triggers = [];
		foreach ($nodes as $node)
		{
			try
			{
				foreach (self::filterTriggersByActivities([$node]) as $trigger)
				{
					if ($trigger['APPLY_RULES'])
					{
						$triggers[] = $trigger;
					}
				}
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			}
		}

		return $triggers;
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

	private static function syncByTemplate(int $templateId, array $template, bool $active = true): void
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

		ServiceLocator::getInstance()->get(ScheduledTriggerSyncService::class)
			->syncByTemplate($templateId, $triggers, $active)
		;
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
			static fn($activity) => \CBPRuntime::getRuntime()->isTriggerActivity((string)$activity['Type'])
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
			$columns = self::buildRowColumns($trigger);
			if ($columns === null)
			{
				continue;
			}

			$insert = [
				'TEMPLATE_ID' => $templateId,
				'TRIGGER_NAME' => $trigger['TRIGGER_NAME'],
				...$columns,
			];

			$queries = $sqlHelper->prepareMerge($tableName, $primary, $insert, $columns);

			foreach ($queries as $query)
			{
				$connection->queryExecute($query);
			}
		}
	}

	/**
	 * A row is not stored at all when the configurator gives no complete document type.
	 *
	 * @return array{
	 *     TRIGGER_TYPE: string,
	 *     APPLY_RULES: string,
	 *     MODULE_ID: string,
	 *     ENTITY: string,
	 *     DOCUMENT_TYPE: string
	 * }|null
	 */
	private static function buildRowColumns(array $trigger): ?array
	{
		/** @var $configuration Configurator */
		$configuration = $trigger['CONFIGURATION'];
		[$moduleId, $entity, $documentType] = $configuration->getDocumentComplexType()?->toArray();

		if (!$moduleId || !$entity || !$documentType)
		{
			return null;
		}

		return [
			'TRIGGER_TYPE' => $trigger['TRIGGER_TYPE'],
			'APPLY_RULES' => Json::encode($trigger['APPLY_RULES'], 0),
			'MODULE_ID' => $moduleId,
			'ENTITY' => $entity,
			'DOCUMENT_TYPE' => $documentType,
		];
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
				$sections[$section->id][$section->path ?? ''] ??= $section;
			}
		}

		self::provideSectionsToTemplate($sections, $templateId);
	}

	/**
	 * @param array<string, array<Section>> $sections sections grouped by section id
	 * @param int $templateId
	 *
	 * @return void
	 */
	public static function provideSectionsToTemplate(array $sections, int $templateId): void
	{
		WorkflowTemplateSectionTable::deleteByTemplate($templateId);
		foreach ($sections as $sectionsByPath)
		{
			foreach ($sectionsByPath as $section)
			{
				WorkflowTemplateSectionTable::upsert($templateId, $section->id, $section->path ?? null);
			}
		}
	}
}
