<?php

namespace Bitrix\Bizproc\Workflow\Template\Entity;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Access\PermissionCache;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Bizproc\Internal\Service\DayPlanBot\DayPlanBotSyncService;
use Bitrix\Bizproc\Internal\Service\Pilot\CommonRevisionWriter;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotCascadeHandler;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotPresence;
use Bitrix\Bizproc\Internal\Service\Pilot\RestrictedTemplateArea;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\ConstantsFileService;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable;
use Bitrix\Bizproc\Workflow\Template\Tpl;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable;
use Bitrix\Main;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\Web\Json;
use Bitrix\Bizproc\Api\Enum\Template\CreateSource;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;

/**
 * Class WorkflowTemplateTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplate_Query query()
 * @method static EO_WorkflowTemplate_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplate_Result getById($id)
 * @method static EO_WorkflowTemplate_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplate_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\Tpl createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\Tpl wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateTable extends Main\ORM\Data\DataManager
{
	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_bp_workflow_template';
	}

	public static function getObjectClass()
	{
		return Tpl::class;
	}

	/**
	 * Locks the row of one template until the transaction of the caller ends - the point every write that
	 * has to see the template the way it will leave it serializes on.
	 *
	 * Outside a transaction the lock is taken and given back at once, which buys the caller nothing.
	 *
	 * @return bool whether there is a row of that template to lock at all
	 */
	public static function lockRow(int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$row = Main\Application::getConnection()->query(
			'SELECT ID FROM ' . static::getTableName() . ' WHERE ID = ' . $templateId . ' FOR UPDATE',
		)->fetch();

		return $row !== false;
	}

	/**
	 * @return array
	 */
	public static function getMap()
	{
		$serializeCallback = [__CLASS__, 'toSerializedForm'];
		$unserializeCallback = [__CLASS__, 'getFromSerializedForm'];

		return [
			'ID' => [
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			],
			'MODULE_ID' => [
				'data_type' => 'string',
			],
			'ENTITY' => [
				'data_type' => 'string',
			],
			'DOCUMENT_TYPE' => [
				'data_type' => 'string',
			],
			'DOCUMENT_STATUS' => [
				'data_type' => 'string',
			],
			'AUTO_EXECUTE' => [
				'data_type' => 'integer',
			],
			'NAME' => [
				'data_type' => 'string',
			],
			'DESCRIPTION' => [
				'data_type' => 'string',
			],
			'TEMPLATE' => (
				(new Main\ORM\Fields\ArrayField('TEMPLATE'))
					->configureSerializeCallback($serializeCallback)
					->configureUnserializeCallback($unserializeCallback)
			),
			'PARAMETERS' => (
				(new Main\ORM\Fields\ArrayField('PARAMETERS'))
					->configureSerializeCallback($serializeCallback)
					->configureUnserializeCallback($unserializeCallback)
			),
			'VARIABLES' => (
				(new Main\ORM\Fields\ArrayField('VARIABLES'))
					->configureSerializeCallback($serializeCallback)
					->configureUnserializeCallback($unserializeCallback)
			),
			'CONSTANTS' => (
				(new Main\ORM\Fields\ArrayField('CONSTANTS'))
					->configureSerializeCallback($serializeCallback)
					->configureUnserializeCallback($unserializeCallback)
			),
			'MODIFIED' => [
				'data_type' => 'datetime',
			],
			'IS_MODIFIED' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
			],
			'USER_ID' => [
				'data_type' => 'integer',
			],
			'SYSTEM_CODE' => [
				'data_type' => 'string',
			],
			'ACTIVE' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
			],
			'ORIGINATOR_ID' => [
				'data_type' => 'string',
			],
			'ORIGIN_ID' => [
				'data_type' => 'string',
			],
			'USER' => [
				'data_type' => Main\UserTable::class,
				'reference' => [
					'=this.USER_ID' => 'ref.ID',
				],
				'join_type' => 'LEFT',
			],
			'IS_SYSTEM' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
			],
			'SORT' => [
				'data_type' => 'integer',
				'default_value' => 10,
			],
			'TYPE' => [
				'data_type' => 'enum',
				'values' => array_column(WorkflowTemplateType::cases(), 'value'),
				'default_value' => 'default',
			],
			new \Bitrix\Main\ORM\Fields\Relations\OneToMany(
				'TEMPLATE_SETTINGS',
				\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable::class,
				'TEMPLATE'
			),
			new \Bitrix\Main\ORM\Fields\Relations\OneToMany(
				'TEMPLATE_DRAFT',
				\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable::class,
				'TEMPLATE'
			),
			'CREATED_BY' => [
				'data_type' => 'integer',
			],
			'UPDATED_BY' => [
				'data_type' => 'integer',
			],
			'ACTIVATED_BY' => [
				'data_type' => 'integer',
			],
			'ACTIVATED_AT' => [
				'data_type' => 'datetime',
			],
			'CREATE_SOURCE' => [
				'data_type' => 'enum',
				'values' => array_column(CreateSource::cases(), 'value'),
			],
			// The moment the template first became a published process. Empty for the templates that
			// existed before the column: they are not treated as new.
			(new ORM\Fields\DatetimeField('FIRST_PUBLISHED_AT'))->configureNullable(),
			new ORM\Fields\Relations\Reference(
				'UPDATED_USER',
				Main\UserTable::class,
				ORM\Query\Join::on('this.UPDATED_BY', 'ref.ID'),
				['join_type' => 'LEFT'],
			),
			new ORM\Fields\Relations\Reference(
				'CREATED_USER',
				Main\UserTable::class,
				ORM\Query\Join::on('this.CREATED_BY', 'ref.ID'),
				['join_type' => 'LEFT'],
			),
		];
	}

	public static function getFromSerializedForm($value)
	{
		if (!empty($value))
		{
			if (self::shouldUseCompression())
			{
				$value1 = @gzuncompress($value);
				if ($value1 !== false)
				{
					$value = $value1;
				}
			}

			$value = unserialize($value, ['allowed_classes' => false]);
			if (!is_array($value))
			{
				$value = [];
			}
		}
		else
		{
			$value = [];
		}

		return $value;
	}

	public static function toSerializedForm($value)
	{
		if (empty($value))
		{
			return null;
		}

		$buffer = serialize($value);
		if (self::shouldUseCompression())
		{
			$buffer = gzcompress($buffer, 9);
		}

		return $buffer;
	}

	public static function getIdsByDocument(array $documentType): array
	{
		$documentType = \CBPHelper::ParseDocumentId($documentType);
		$rows = static::getList([
			'select' => ['ID'],
			'filter' => [
				'=MODULE_ID' => $documentType[0],
				'=ENTITY' => $documentType[1],
				'=DOCUMENT_TYPE' => $documentType[2],
			],
		])->fetchAll();

		return array_column($rows, 'ID');
	}

	private static function shouldUseCompression(): bool
	{
		static $useCompression;
		if ($useCompression === null)
		{
			$useCompression = \CBPWorkflowTemplateLoader::useGZipCompression();
		}

		return $useCompression;
	}

	public static function encodeJson($value)
	{
		if (empty($value))
		{
			return null;
		}

		return Json::encode($value, 0);
	}

	public static function decodeJson($value)
	{
		if (!empty($value))
		{
			return Json::decode($value);
		}

		return $value;
	}

	public static function onAfterAdd(Event $event): void
	{
		$id = $event->getParameter('primary')['ID'];
		$template = $event->getParameter('fields')['TEMPLATE'] ?? null;
		if (is_array($template))
		{
			$active = ($event->getParameter('fields')['ACTIVE'] ?? 'Y') === 'Y';
			WorkflowTemplateTriggerTable::onTemplateAdd($id, $template, $active);
			(new CommonRevisionWriter())->writeCommonRevision((int)$id, $template);
		}

		$constants = $event->getParameter('fields')['CONSTANTS'] ?? null;
		if (is_array($constants))
		{
			self::getConstantsFileService()->add($id, $constants);
		}
	}

	public static function onAfterUpdate(Event $event): void
	{
		$fields = $event->getParameter('fields');
		$template = $fields['TEMPLATE'] ?? null;
		$active = $fields['ACTIVE'] ?? null;
		$id = $event->getParameter('primary')['ID'];

		if (is_array($template))
		{
			WorkflowTemplateDraftTable::deleteByTemplateId($id);

			// the pilot is stopped only once the fingerprint of the new common scheme is really stored:
			// stopping it without one would leave the template marked as published to a pilot and with no
			// common version recorded, and the visibility rule hides such a template from everyone
			if ((new CommonRevisionWriter())->writeCommonRevisionForUpdate((int)$id, $template))
			{
				(new PilotCascadeHandler())->onCommonSchemeWritten(
					(int)$id,
					deferCacheInvalidation: true,
				);
			}
		}

		if (is_array($template) || $active !== null)
		{
			WorkflowTemplateTriggerTable::onTemplateUpdate($id);
		}

		$constants = $fields['CONSTANTS'] ?? null;
		if (is_array($constants))
		{
			self::getConstantsFileService()->update($id, $constants);
		}

		DayPlanBotSyncService::onTemplateUpdate($id, $fields ?? []);
	}

	public static function onAfterDelete(Event $event): void
	{
		$id = $event->getParameter('primary')['ID'];

		WorkflowTemplateUserDataTable::deleteByFilter(['=TEMPLATE_ID' => $id]);
		WorkflowTemplateChangeTable::deleteByFilter(['=TEMPLATE_ID' => $id]);
		WorkflowTemplateDraftTable::deleteByFilter(['=TEMPLATE_ID' => $id]);
		WorkflowTemplateLockTable::deleteByFilter(['=TEMPLATE_ID' => $id]);
		self::deleteNotesWithMessages((int)$id);

		WorkflowTemplateTriggerTable::onTemplateDelete($id);
		self::getConstantsFileService()->delete($id);

		// unconditional as the ACL cleanup below: a reused template ID must not inherit a foreign pilot
		if ((new PilotVersionRepository())->deleteByTemplateId((int)$id))
		{
			$presence = new PilotPresence();
			$presence->synchronize();
			$presence->invalidate();
		}

		// the settings of the template go with it, so the area of the visibility rule is answered anew -
		// but only where there is an area at all: a portal with nothing hidden pays nothing for a deletion
		$restrictedArea = new RestrictedTemplateArea();
		$restrictedArea->synchronize();
		$restrictedArea->invalidate();

		self::cleanOrphanedAccessPermissions((int)$id);
	}

	/**
	 * A message is addressed through its note, so the notes are listed while they are still there:
	 * deleting them first would leave the messages of the template with nothing to reach them by.
	 */
	private static function deleteNotesWithMessages(int $templateId): void
	{
		$noteIds = array_column(
			TemplateNoteTable::getList([
				'select' => ['ID'],
				'filter' => ['=TEMPLATE_ID' => $templateId],
			])->fetchAll(),
			'ID',
		);

		if (!$noteIds)
		{
			return;
		}

		TemplateNoteMessageTable::deleteByFilter(['@NOTE_ID' => $noteIds]);
		TemplateNoteTable::deleteByFilter(['@ID' => $noteIds]);
	}

	/**
	 * Drops ACL matrix rows that scoped this template ID so a later reuse of the ID cannot leak access to
	 * a foreign template, then invalidates the effective-permission cache globally. Sentinel scope values
	 * ({@see PermissionDictionary::isSentinelValue()}) are never template IDs, so they stay untouched.
	 *
	 * Only multivariables permissions store a template ID in VALUE; toggler permissions (e.g. CREATE) keep a
	 * yes/no flag there, so the cleanup is narrowed to multivariables PERMISSION_IDs to avoid wiping toggler
	 * grants whose VALUE happens to equal the deleted template ID.
	 */
	private static function cleanOrphanedAccessPermissions(int $templateId): void
	{
		if ($templateId <= 0)
		{
			return;
		}

		$permissionIds = self::getTemplateScopedPermissionIds();
		if (!$permissionIds)
		{
			return;
		}

		if (Container::getAccessRepository()->deleteOrphanPermissionsByTemplate($templateId, $permissionIds))
		{
			PermissionCache::getInstance()->clearGlobal();
		}
	}

	/**
	 * @return string[] PERMISSION_IDs of multivariables (template-scoped) permissions from the dictionary.
	 */
	private static function getTemplateScopedPermissionIds(): array
	{
		$permissionIds = [];
		foreach (array_keys(PermissionDictionary::getList()) as $permissionId)
		{
			$descriptor = PermissionDictionary::getPermission((string)$permissionId);
			if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES)
			{
				$permissionIds[] = (string)$permissionId;
			}
		}

		return $permissionIds;
	}

	private static function getConstantsFileService(): ConstantsFileService
	{
		return Main\DI\ServiceLocator::getInstance()->get(ConstantsFileService::class);
	}
}
