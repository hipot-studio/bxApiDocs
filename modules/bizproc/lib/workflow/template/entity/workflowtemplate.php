<?php

namespace Bitrix\Bizproc\Workflow\Template\Entity;

use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\ConstantsFileService;
use Bitrix\Bizproc\Workflow\Template\Tpl;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Main;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\Web\Json;
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
		}

		$constants = $event->getParameter('fields')['CONSTANTS'] ?? null;
		if (is_array($constants))
		{
			self::getConstantsFileService()->add($id, $constants);
		}
	}

	public static function onAfterUpdate(Event $event): void
	{
		$template = $event->getParameter('fields')['TEMPLATE'] ?? null;
		$active = $event->getParameter('fields')['ACTIVE'] ?? null;
		$id = $event->getParameter('primary')['ID'];

		if (is_array($template))
		{
			WorkflowTemplateDraftTable::deleteByTemplateId($id);
		}

		if (is_array($template) || $active !== null)
		{
			WorkflowTemplateTriggerTable::onTemplateUpdate($id);
		}

		$constants = $event->getParameter('fields')['CONSTANTS'] ?? null;
		if (is_array($constants))
		{
			self::getConstantsFileService()->update($id, $constants);
		}
	}

	public static function onAfterDelete(Event $event): void
	{
		$id = $event->getParameter('primary')['ID'];
		WorkflowTemplateTriggerTable::onTemplateDelete($id);

		self::getConstantsFileService()->delete($id);
	}

	private static function getConstantsFileService(): ConstantsFileService
	{
		return Main\DI\ServiceLocator::getInstance()->get(ConstantsFileService::class);
	}
}
