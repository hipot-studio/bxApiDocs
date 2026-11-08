<?php

declare(strict_types=1);

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Service\Container;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('ManualStartTrigger'))
{
	return;
}

/**
 * Start node of a signing process launched from the KEDO section. Its section makes the scheme available
 * there, and one launch of the node serves one employee: the selection is expanded outside the scheme.
 *
 * The node is a public contract of every scheme built on it - neither its identifier nor the names of the
 * values it publishes can be renamed once the feature is rolled out.
 */
class CBPSignB2EManualStartTrigger extends \CBPManualStartTrigger
{
	private const SECTION_CODE = 'b2e';

	private const EMPLOYEE_PROPERTY = 'EmployeeId';
	private const INITIATOR_PROPERTY = 'InitiatorId';
	private const STARTED_DATE_PROPERTY = 'StartedDate';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties[self::EMPLOYEE_PROPERTY] = null;
		$this->arProperties[self::INITIATOR_PROPERTY] = null;
		$this->arProperties[self::STARTED_DATE_PROPERTY] = null;

		$this->setPropertiesTypes(static::getReturnMap());
	}

	/**
	 * The employee, the initiator and the moment of the launch come from the data of the start event, not from
	 * the start parameters of the template: the launch of the section runs a process per employee and tells
	 * each one apart by them.
	 */
	public function execute(): int
	{
		$eventData = $this->getEventData();

		$this->setProperties([
			self::EMPLOYEE_PROPERTY => self::toUserFieldValue($eventData[self::EMPLOYEE_PROPERTY] ?? null),
			self::INITIATOR_PROPERTY => self::toUserFieldValue($eventData[self::INITIATOR_PROPERTY] ?? null),
			self::STARTED_DATE_PROPERTY => self::toDateTimeFieldValue($eventData[self::STARTED_DATE_PROPERTY] ?? null),
		]);

		return CBPActivityExecutionStatus::Closed;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		$result = parent::getPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		);

		if (!$result)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties']['Return'] = static::getReturnMap();

		return true;
	}

	/**
	 * Section of the KEDO processes. The engine writes it down itself while the scheme is saved
	 * ({@see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable::updateSectionsByTriggers()}),
	 * so removing the node from a scheme takes it out of the section as well.
	 */
	public function getSection(): ?Section
	{
		return new Section(static::getModuleId() . '|' . self::SECTION_CODE);
	}

	protected static function getModuleId(): string
	{
		return 'sign';
	}

	/**
	 * Document type of the node - the smart process the personnel processes of the portal run on. The type is
	 * created together with the feature, so an absent one is an ordinary state and leaves the node without a
	 * document type instead of raising.
	 */
	protected function getDocumentComplexType(): array
	{
		if (!Loader::includeModule('sign') || !Loader::includeModule('crm'))
		{
			return [];
		}

		$entityTypeId = Container::instance()->getCrmProcessTypeService()->getEntityTypeId();
		if ($entityTypeId === null)
		{
			return [];
		}

		return CCrmBizProcHelper::ResolveDocumentType($entityTypeId) ?? [];
	}

	/**
	 * Values the node publishes into the scheme: the single employee of the launch, its initiator and the
	 * moment of the launch.
	 */
	private static function getReturnMap(): array
	{
		return [
			self::EMPLOYEE_PROPERTY => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_B2E_MANUAL_START_TRIGGER_RETURN_EMPLOYEE') ?? '',
				'Type' => FieldType::USER,
				'Default' => null,
			],
			self::INITIATOR_PROPERTY => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_B2E_MANUAL_START_TRIGGER_RETURN_INITIATOR') ?? '',
				'Type' => FieldType::USER,
				'Default' => null,
			],
			self::STARTED_DATE_PROPERTY => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_B2E_MANUAL_START_TRIGGER_RETURN_STARTED_DATE') ?? '',
				'Type' => FieldType::DATETIME,
				'Default' => null,
			],
		];
	}

	private static function toUserFieldValue(mixed $userId): ?string
	{
		$userId = is_numeric($userId) ? (int)$userId : 0;

		return $userId > 0 ? 'user_' . $userId : null;
	}

	/**
	 * The moment the launch was queued, as the queue of the section hands it over: the node runs on a step of
	 * the queue - later than the press of the button, sometimes by minutes - so a moment of its own would name
	 * the start of the process instead of the launch. A start that carries no event data of the queue - a
	 * manual start from the section of business processes - has no such moment, and then the moment of the
	 * execution is the only meaningful one.
	 */
	private static function toDateTimeFieldValue(mixed $startedDate): string
	{
		if ($startedDate instanceof DateTime)
		{
			return $startedDate->format(DateTime::getFormat());
		}

		$startedDate = is_string($startedDate) ? trim($startedDate) : '';

		return $startedDate === '' ? (new DateTime())->format(DateTime::getFormat()) : $startedDate;
	}
}
