<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Service\Container;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Tasks;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class TaskStatusTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	/**
	 * @param int $entityTypeId Target entity id
	 * @return bool
	 */
	public static function isSupported($entityTypeId)
	{
		if (in_array(
			$entityTypeId,
			[\CCrmOwnerType::Lead, \CCrmOwnerType::Deal, \CCrmOwnerType::SmartInvoice, \CCrmOwnerType::Quote],
			true)
		)
		{
			return true;
		}

		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);

			return (
				static::areDynamicTypesSupported()
				&& !is_null($factory)
				&& $factory->isAutomationEnabled()
				&& $factory->isStagesEnabled()
			);
		}

		return false;
	}

	public static function isEnabled()
	{
		return ModuleManager::isModuleInstalled('tasks');
	}

	public static function getCode()
	{
		return 'TASK_STATUS';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_NAME_1');
	}

	public function setInputData($data)
	{
		$taskId = (int)($data['TASK']['ID'] ?? 0);
		$taskStatus = (int)($data['TASK']['REAL_STATUS'] ?? 0);
		if ($taskId > 0 && is_callable([$this, 'setReturnValues']))
		{
			$returnValues = [
				'TaskDocument' => Tasks\Integration\Bizproc\Document\Task::resolveDocumentId($taskId),
				'TaskId' => $taskId,
			];

			if ($taskStatus > 0)
			{
				$returnValues['TaskStatus'] = $taskStatus;
			}

			$this->setReturnValues($returnValues);
		}

		return parent::setInputData($data);
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_EVENT_DATE_TIME') ?? ''
				),
			],
			[
				[
					'Id' => 'TaskDocument',
					'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_RETURN_TASK'),
					'Type' => FieldType::DOCUMENT,
					'Default' => ['tasks', Tasks\Integration\Bizproc\Document\Task::class, 'TASK'],
				],
				[
					'Id' => 'TaskId',
					'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_RETURN_TASK_ID'),
					'Type' => FieldType::INT,
					'Default' => null,
				],
				[
					'Id' => 'TaskStatus',
					'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_RETURN_TASK_STATUS'),
					'Type' => FieldType::INT,
					'Default' => null,
				],
			]
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public function checkApplyRules(array $trigger)
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (
			is_array($trigger['APPLY_RULES'])
			&& !empty($trigger['APPLY_RULES']['taskCondition'])
		)
		{
			$task = $this->getInputData('TASK');

			if (
				!empty($trigger['APPLY_RULES']['taskStatus'])
				&& (int)$trigger['APPLY_RULES']['taskStatus'] !== (int)$task['REAL_STATUS']
			)
			{
				return false;
			}

			$conditionGroup = new ConditionGroup($trigger['APPLY_RULES']['taskCondition']);
			$documentType = ['tasks', Tasks\Integration\Bizproc\Document\Task::class, 'TASK'];
			$documentId = Tasks\Integration\Bizproc\Document\Task::resolveDocumentId($task['ID']);
			$this->resolveCrmUserConditions($conditionGroup, $documentType);

			return $conditionGroup->evaluateByDocument(
				$documentType,
				$documentId
			);
		}
		return true;
	}

	private function resolveCrmUserConditions(ConditionGroup $conditionGroup, array $documentType): void
	{
		$documentService = \CBPRuntime::getRuntime(true)->getDocumentService();
		$documentFields = $documentService->getDocumentFields($documentType);
		$target = $this->getTarget();
		$crmDocumentId = $target->getDocumentType();
		$crmDocumentId[2] = $target->getDocumentId();

		foreach ($conditionGroup->getItems() as [$condition])
		{
			$property = $documentFields[$condition->getField()] ?? null;
			if (
				!$property
				|| $documentService->getFieldTypeObject($documentType, $property)?->getBaseType() !== FieldType::USER
				|| in_array($condition->getOperator(), ['empty', '!empty'], true)
			)
			{
				continue;
			}

			$values = [];
			$resolved = false;
			foreach ((array)$condition->getValue() as $value)
			{
				$expression = \CBPActivity::parseExpression($value);
				if (($expression['object'] ?? null) === 'Document')
				{
					foreach (\CBPHelper::extractUsers($value, $crmDocumentId) as $userId)
					{
						$values[] = 'user_' . $userId;
					}
					$resolved = true;
				}
				else
				{
					$values[] = $value;
				}
			}
			if ($resolved)
			{
				$condition->setValue($values);
			}
		}
	}

	protected static function getPropertiesMap(): array
	{
		$taskFields = \Bitrix\Bizproc\Automation\Helper::getDocumentFields(
			['tasks', Tasks\Integration\Bizproc\Document\Task::class, 'TASK']
		);

		$statusList = [];
		foreach($taskFields['STATUS']['Options'] as $id => $status)
		{
			$statusList[] = ['value' => $id, 'name' => $status];
		}
		unset($taskFields['STATUS']);

		return [
			[
				'Id' => 'taskStatus',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_PROPERTY_STATUS'),
				'Type' => 'select',
				'EmptyValueText' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_DEFAULT_STATUS'),
				'Options' => $statusList,
			],
			[
				'Id' => 'taskCondition',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_CONDITION'),
				'ShowFieldLabel' => false,
				'Type' => '@condition-group-selector',
				'Settings' => [
					'Fields' => array_values($taskFields),
				],
			],
		];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['employeeControl', 'taskManagement'];
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_TASK_STATUS_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::COMPLETE_TASK_LIST->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::TASK_MANAGEMENT->value, ActivityGroup::TASK_DISTRIBUTION->value];
	}
}
