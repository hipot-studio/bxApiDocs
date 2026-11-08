<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\HumanResources\Integration\Bizproc\NewEmployeeTrigger;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeBinding;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeFilter;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeFilterVerdict;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPNewEmployeeTrigger extends BaseTrigger
{
	private const PARAM_DEPARTMENTS = 'DEPARTMENTS';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_EVENT_DATE_TIME = 'EventDateTime';

	/**
	 * Property of the map, and the field of the form, carrying the part of the binding no identifier of the
	 * selector can carry. It is a property of its own and hidden, because the settings panel of the designer
	 * builds the form out of the map alone: a field written into the template of the dialog and nowhere else
	 * would never reach {@see self::preparePropertiesDialogValues()}, which is where the part comes back into
	 * the value. Nothing of it is stored: the very same method drops the key before the properties are saved.
	 */
	public const CARRIED_CODES_FIELD_NAME = self::PARAM_DEPARTMENTS . '_CARRIED';

	/**
	 * Refusal codes of the filter, taken from the contract of the event: the dispatcher classifies them by
	 * their common prefix, so the codes are declared once there and never restated here.
	 */
	private const ERROR_UNRESOLVED_FILTER = NewEmployeeTrigger::REFUSAL_UNRESOLVED_FILTER;
	private const ERROR_STALE_USER_CODES = NewEmployeeTrigger::REFUSAL_STALE_USER_CODES;
	private const ERROR_NO_STRUCTURE_CODES = NewEmployeeTrigger::REFUSAL_NO_STRUCTURE_CODES;
	private const ERROR_NO_MEMBER_NODES = NewEmployeeTrigger::REFUSAL_NO_MEMBER_NODES;
	private const ERROR_OUTSIDE_SELECTION = NewEmployeeTrigger::REFUSAL_OUTSIDE_SELECTION;
	private const ERROR_NO_EMPLOYEE = NewEmployeeTrigger::REFUSAL_NO_EMPLOYEE;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_DEPARTMENTS => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_EVENT_DATE_TIME => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => ['Type' => FieldType::USER],
			self::RETURN_PARAM_EVENT_DATE_TIME => ['Type' => FieldType::DATETIME],
		]);
	}

	public function execute(): int
	{
		$context = $this->getEventData();

		$userId = (int)($context[NewEmployeeTrigger::FIELD_USER_ID] ?? 0);
		if ($userId > 0)
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		// Stays the string the dispatcher sent: a delayed start carries the payload through storage.
		$eventDateTime = $context[NewEmployeeTrigger::FIELD_EVENT_DATE_TIME] ?? null;
		if (is_string($eventDateTime) && $eventDateTime !== '')
		{
			$this->{self::RETURN_PARAM_EVENT_DATE_TIME} = $eventDateTime;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * The binding names structure nodes and nothing else, so the property carries the node selector of the
	 * org structure. The rule of the empty binding lives in the name of the field alone: `Description` is
	 * read by three places of the designer at once - the placeholder of the field of expressions, the hint
	 * under the field and the hint icon next to its name - and a name repeating what one of them says would
	 * show the very same sentence twice.
	 */
	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_DEPARTMENTS => [
				'Name' => (string)Loc::getMessage('HUMAN_RESOURCES_NEW_EMPLOYEE_TRIGGER_PROPERTY_DEPARTMENTS_MSGVER_1'),
				'FieldName' => self::PARAM_DEPARTMENTS,
				'Type' => FieldType::ENTITYSELECTOR,
				'Required' => false,
				'Multiple' => true,
				'AllowSelection' => true,
				'Settings' => [
					'entity' => StructureNodeBinding::selectorEntity(),
					// Tags of the saved nodes are built here and not by the preselect of the selector: the
					// preselect builds an entity of its own, without these options, and applies the right to
					// view the node on top of it, which turns a team and a node closed from the author of the
					// scenario into "Hidden element" and loses the binding on the next save.
					'items' => StructureNodeBinding::selectorItems(
						$context['Properties'][self::PARAM_DEPARTMENTS] ?? null,
					),
				],
				'Getter' => static fn(
					PropertiesDialog $dialog,
					array $property,
					array $activity,
					bool $compatible,
				) => StructureNodeBinding::toDialogValue($activity['Properties'][self::PARAM_DEPARTMENTS] ?? null),
			],
			self::CARRIED_CODES_FIELD_NAME => [
				// Technical, never shown: the field is hidden, and its own value is machine-written.
				'Name' => self::CARRIED_CODES_FIELD_NAME,
				'FieldName' => self::CARRIED_CODES_FIELD_NAME,
				'Type' => FieldType::STRING,
				'Required' => false,
				'Multiple' => false,
				'AllowSelection' => false,
				'Hidden' => true,
				'Getter' => static fn(
					PropertiesDialog $dialog,
					array $property,
					array $activity,
					bool $compatible,
				) => StructureNodeBinding::carriedValue($activity['Properties'][self::PARAM_DEPARTMENTS] ?? null),
			],
		];
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	): PropertiesDialog
	{
		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$savedActivity = \CBPWorkflowTemplateLoader::findActivityByName($arWorkflowTemplate, $activityName);
		$dialog->setMap(static::getPropertiesMap($documentType, is_array($savedActivity) ? $savedActivity : []));

		return $dialog;
	}

	/**
	 * Value of the carried-codes field for the template of the dialog, which renders that field itself.
	 * The template cannot take it from {@see PropertiesDialog::getCurrentValue()}: the dialog runs the
	 * `Getter` of a property only when the saved properties hold a key of that name, and this one is never
	 * saved. The packing is the one of both sides, and it belongs to neither
	 * ({@see StructureNodeBinding::carriedValue()}).
	 */
	public static function getCarriedCodesFieldValue(PropertiesDialog $dialog): string
	{
		$template = $dialog->getWorkflowTemplate();
		$activity = \CBPWorkflowTemplateLoader::findActivityByName($template, $dialog->getActivityName());

		return StructureNodeBinding::carriedValue(
			is_array($activity) ? ($activity['Properties'][self::PARAM_DEPARTMENTS] ?? null) : null,
		);
	}

	/**
	 * The binding the dialog sends back. What is written into the property is the value of the field, what
	 * was typed into the field of expressions by hand and the part no selector can show, which comes back
	 * from the carrier and is what keeps a binding that is not empty from being saved empty. Reading the
	 * three into one value is the same on both triggers and belongs to neither
	 * ({@see StructureNodeBinding::toStoredValue()}); what belongs here is the name of the carrier, and the
	 * carrier itself is dropped: it is a field of the form and no setting.
	 */
	protected static function preparePropertiesDialogValues(
		array $documentType,
		array $properties,
		array $currentValues,
	): array
	{
		$carried = $properties[self::CARRIED_CODES_FIELD_NAME] ?? null;
		unset($properties[self::CARRIED_CODES_FIELD_NAME]);

		$properties[self::PARAM_DEPARTMENTS] = StructureNodeBinding::toStoredValue(
			$properties[self::PARAM_DEPARTMENTS] ?? null,
			$currentValues[self::PARAM_DEPARTMENTS . '_text'] ?? null,
			$carried,
		);

		return $properties;
	}

	/**
	 * The binding of the node against the employee the event carries: the shared filter answers whether the
	 * employee belongs to the picked nodes, and the node turns its verdict into a refusal code of its own
	 * (DTO-02). The membership is read as it is read today - active alone.
	 */
	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$verdict = $this->filter()->check(
			(int)$parameters->get(NewEmployeeTrigger::FIELD_USER_ID),
			$this->getRawProperty(self::PARAM_DEPARTMENTS),
			$this->{self::PARAM_DEPARTMENTS},
		);

		return match ($verdict)
		{
			StructureNodeFilterVerdict::Applicable => Result::createOk(),
			StructureNodeFilterVerdict::NoEmployee =>
				self::rejected(self::ERROR_NO_EMPLOYEE, 'the event carries no employee'),
			StructureNodeFilterVerdict::UnresolvedFilter =>
				self::rejected(self::ERROR_UNRESOLVED_FILTER, 'the filter resolves into no access code'),
			StructureNodeFilterVerdict::StaleUserCodes =>
				self::rejected(self::ERROR_STALE_USER_CODES, 'the filter names an employee by hand'),
			StructureNodeFilterVerdict::NoStructureCodes =>
				self::rejected(self::ERROR_NO_STRUCTURE_CODES, 'the filter names no structure node'),
			StructureNodeFilterVerdict::NoMemberNodes =>
				self::rejected(self::ERROR_NO_MEMBER_NODES, 'the employee belongs to no structure node'),
			StructureNodeFilterVerdict::OutsideSelection =>
				self::rejected(self::ERROR_OUTSIDE_SELECTION, 'the employee is outside the filtered nodes'),
		};
	}

	protected static function getModuleId(): ?string
	{
		return 'humanresources';
	}

	/**
	 * Resets memoized answers of the org structure. Intended for use in tests.
	 */
	public static function resetLookupCache(): void
	{
		StructureNodeFilter::resetLookupCache();
	}

	/**
	 * The binding of the node against the org structure. A method and not a `new` inside the check: the
	 * lookups of the filter reach final repositories of the module, so the suite of the rules of this node
	 * has nowhere else to answer them from.
	 */
	protected function filter(): StructureNodeFilter
	{
		return new StructureNodeFilter();
	}

	private static function rejected(string $code, string $message): Result
	{
		return Result::createError(new Error($message, $code));
	}
}
