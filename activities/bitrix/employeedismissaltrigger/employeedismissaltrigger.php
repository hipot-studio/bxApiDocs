<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\Trigger\ContextFields\EmployeeDismissalTrigger;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Result;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeBinding;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeFilter;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeFilterVerdict;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPEmployeeDismissalTrigger extends BaseTrigger
{
	private const PARAM_DEPARTMENTS = 'DEPARTMENTS';

	/**
	 * The returned properties are named after the fields of the event they carry over, so the names live in
	 * the contract of the event and never as a literal here.
	 */
	private const RETURN_PARAM_USER_ID = EmployeeDismissalTrigger::FIELD_USER_ID;
	private const RETURN_PARAM_FIRED_AT = EmployeeDismissalTrigger::FIELD_FIRED_AT;
	private const RETURN_PARAM_NAME = EmployeeDismissalTrigger::FIELD_NAME;
	private const RETURN_PARAM_LAST_NAME = EmployeeDismissalTrigger::FIELD_LAST_NAME;

	/**
	 * The org structure is not a dependency of this module: the trigger ships with `bizproc` and works on a
	 * portal without it, only with no binding to offer. Every reach for the structure is guarded, and a
	 * binding already saved on a portal that lost the module is refused rather than ignored.
	 */
	private const STRUCTURE_MODULE_ID = 'humanresources';

	/**
	 * Property of the map, and the field of the form, carrying the part of the binding no identifier of the
	 * selector can carry. It is a property of its own and hidden, because the settings panel of the designer
	 * builds the form out of the map alone: a field written into the template of the dialog and nowhere else
	 * would never reach {@see self::preparePropertiesDialogValues()}, which is where the part comes back into
	 * the value. Nothing of it is stored: the very same method drops the key before the properties are saved.
	 */
	public const CARRIED_CODES_FIELD_NAME = self::PARAM_DEPARTMENTS . '_CARRIED';

	/**
	 * Refusal codes of the filter, taken from the contract of the event: the handler of the dismissal
	 * classifies them by their common prefix, so the codes are declared once there and never restated here.
	 */
	private const ERROR_NO_EMPLOYEE = EmployeeDismissalTrigger::REFUSAL_NO_EMPLOYEE;
	private const ERROR_UNRESOLVED_FILTER = EmployeeDismissalTrigger::REFUSAL_UNRESOLVED_FILTER;
	private const ERROR_STALE_USER_CODES = EmployeeDismissalTrigger::REFUSAL_STALE_USER_CODES;
	private const ERROR_NO_STRUCTURE_CODES = EmployeeDismissalTrigger::REFUSAL_NO_STRUCTURE_CODES;
	private const ERROR_NO_MEMBER_NODES = EmployeeDismissalTrigger::REFUSAL_NO_MEMBER_NODES;
	private const ERROR_OUTSIDE_SELECTION = EmployeeDismissalTrigger::REFUSAL_OUTSIDE_SELECTION;
	private const ERROR_NO_HUMANRESOURCES = EmployeeDismissalTrigger::REFUSAL_NO_HUMANRESOURCES;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_DEPARTMENTS => null,
			self::RETURN_PARAM_USER_ID => null,
			self::RETURN_PARAM_FIRED_AT => null,
			self::RETURN_PARAM_NAME => '',
			self::RETURN_PARAM_LAST_NAME => '',
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_USER_ID => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_FIRED_AT => [
				'Type' => FieldType::DATETIME,
			],
			self::RETURN_PARAM_NAME => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_LAST_NAME => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	public function execute(): int
	{
		$context = $this->getEventData();

		$userId = (int)($context[EmployeeDismissalTrigger::FIELD_USER_ID] ?? 0);
		if ($userId > 0)
		{
			$this->arProperties[self::RETURN_PARAM_USER_ID] = "user_{$userId}";
		}

		$firedAt = $context[EmployeeDismissalTrigger::FIELD_FIRED_AT] ?? null;
		$firedAtTimestamp = filter_var($firedAt, FILTER_VALIDATE_INT);
		if ($firedAtTimestamp !== false && $firedAtTimestamp > 0)
		{
			$this->arProperties[self::RETURN_PARAM_FIRED_AT] = DateTime::createFromTimestamp($firedAtTimestamp);
		}
		elseif (is_string($firedAt) && DateTime::isCorrect($firedAt))
		{
			$this->arProperties[self::RETURN_PARAM_FIRED_AT] = new DateTime($firedAt);
		}

		$name = $context[EmployeeDismissalTrigger::FIELD_NAME] ?? '';
		$lastName = $context[EmployeeDismissalTrigger::FIELD_LAST_NAME] ?? '';

		$this->arProperties[self::RETURN_PARAM_NAME] = (string)$name;
		$this->arProperties[self::RETURN_PARAM_LAST_NAME] = (string)$lastName;

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * The binding names structure nodes and nothing else, so the property carries the node selector of the
	 * org structure. The rule of the empty binding lives in the name of the field alone: `Description` is
	 * read by three places of the designer at once - the placeholder of the field of expressions, the hint
	 * under the field and the hint icon next to its name - and a name repeating what one of them says would
	 * show the very same sentence twice.
	 *
	 * A portal without the org structure has no nodes to bind to, so the map is empty there and the settings
	 * panel shows the trigger with no settings at all, as it did before the binding existed.
	 */
	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		if (!self::includeStructureModule())
		{
			return [];
		}

		return [
			self::PARAM_DEPARTMENTS => [
				'Name' => (string)Loc::getMessage('BIZPROC_EMPLOYEE_DISMISSAL_TRIGGER_PROPERTY_DEPARTMENTS'),
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
		if (!self::includeStructureModule())
		{
			return '';
		}

		$template = $dialog->getWorkflowTemplate();
		$activity = \CBPWorkflowTemplateLoader::findActivityByName($template, $dialog->getActivityName());

		return StructureNodeBinding::carriedValue(
			is_array($activity) ? ($activity['Properties'][self::PARAM_DEPARTMENTS] ?? null) : null,
		);
	}

	/**
	 * Without the org structure the map is empty, so the form carried no field of the binding and the base
	 * class would write the properties it builds out of that map - none - over the saved ones. The template
	 * is left untouched instead: a binding saved while the module was still on the portal survives a save of
	 * the form, and the closed refusal of {@see self::checkApplyRules()} keeps meaning what it says.
	 */
	protected static function applyPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		if (!self::includeStructureModule())
		{
			$errors = [];

			return true;
		}

		return parent::applyPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		);
	}

	/**
	 * The binding the dialog sends back. What is written into the property is the value of the field, what
	 * was typed into the field of expressions by hand and the part no selector can show, which comes back
	 * from the carrier and is what keeps a binding that is not empty from being saved empty. Reading the
	 * three into one value is the same on both triggers and belongs to neither
	 * ({@see StructureNodeBinding::toStoredValue()}); what belongs here is the name of the carrier, and the
	 * carrier itself is dropped: it is a field of the form and no setting.
	 *
	 * The guard on the module is what keeps a call made past {@see self::applyPropertiesDialogValues()}
	 * from reaching a class of a module the portal does not have.
	 */
	protected static function preparePropertiesDialogValues(
		array $documentType,
		array $properties,
		array $currentValues,
	): array
	{
		if (!self::includeStructureModule())
		{
			return $properties;
		}

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
	 * (DTO-02).
	 *
	 * The membership is read whatever its activity, unlike the trigger of the new employee: the command of
	 * the dismissal turns the membership off before it raises the event, so an active-only lookup would find
	 * the employee just dismissed in no node at all and the binding would never take anyone.
	 */
	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$binding = $this->getRawProperty(self::PARAM_DEPARTMENTS);

		if (!self::includeStructureModule())
		{
			// Fails closed: a portal that cannot read the binding it was given must not answer as if no
			// binding was given at all, which would start the scenario for every dismissal of the portal.
			return self::isBindingEmpty($binding)
				? Result::createOk()
				: self::rejected(self::ERROR_NO_HUMANRESOURCES, 'the org structure is not on the portal')
			;
		}

		$verdict = $this->filter()->check(
			(int)$parameters->get(EmployeeDismissalTrigger::FIELD_USER_ID),
			$binding,
			$this->{self::PARAM_DEPARTMENTS},
			withInactiveMembership: true,
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

	protected function getSection(): ?Section
	{
		return null;
	}

	protected static function getModuleId(): ?string
	{
		return 'bizproc';
	}

	/**
	 * The binding of the node against the org structure. A method and not a `new` inside the check: the
	 * lookups of the filter reach final repositories of another module, so the suite of the rules of this
	 * node has nowhere else to answer them from.
	 */
	protected function filter(): StructureNodeFilter
	{
		return new StructureNodeFilter();
	}

	private static function includeStructureModule(): bool
	{
		return Loader::includeModule(self::STRUCTURE_MODULE_ID);
	}

	/**
	 * A binding that was never set takes any employee. Judged the very way {@see StructureNodeFilter} judges
	 * it, and judged here because the class that does it lives in the module that may be missing.
	 */
	private static function isBindingEmpty(mixed $binding): bool
	{
		return \CBPHelper::isEmptyValue(\CBPHelper::flatten($binding));
	}

	private static function rejected(string $code, string $message): Result
	{
		return Result::createError(new Error($message, $code));
	}
}
