<?php

use Bitrix\Bizproc\BaseType\ValueRule;
use Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Bizproc\Internal\Service\Activity\ChildValidationContext;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotPresence;
use Bitrix\Bizproc\Internal\Service\Pilot\RestrictedTemplateArea;
use Bitrix\Bizproc\Internal\Service\Pilot\SettingsFreezeGate;
use Bitrix\Bizproc\Internal\Service\ProcessData\ProcessDataPublicationValidator;
use Bitrix\Bizproc\Internal\Service\Template\FirstPublicationWriter;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\AutoExecuteFilter;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Bizproc\Public\Provider\PilotVisibilityProvider;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Repository\Exception\PersistenceException;

define("BP_EI_DIRECTION_EXPORT", 0);
define("BP_EI_DIRECTION_IMPORT", 1);

/**
* Workflow templates service.
*/
class CBPWorkflowTemplateLoader
{
	protected $useGZipCompression = false;
	protected static $workflowConstants = array();
	const CONSTANTS_CACHE_TAG_PREFIX = 'b_bp_wf_constants_';
	protected static $typesStates = array();

	private static $instance;

	private const SETUP_TEMPLATE_ACTIVITY_TYPE = 'SetupTemplateActivity';

	public const MAX_PARAMETERS_LENGTH = 65535;
	public const MAX_VARIABLES_LENGTH = 65535;
	public const MAX_CONSTANTS_LENGTH = 16777215;

	private function __construct()
	{
		$this->useGZipCompression = static::useGZipCompression();
	}

	public function __clone()
	{
		trigger_error('Clone in not allowed.', E_USER_ERROR);
	}

	/**
	 * Static method returns loader object. Singleton pattern.
	 *
	 * @return CBPWorkflowTemplateLoader
	 */
	public static function getLoader()
	{
		if (!isset(self::$instance))
		{
			$c = __CLASS__;
			self::$instance = new $c;
		}

		return self::$instance;
	}

	public static function getList($arOrder = array("ID" => "DESC"), $arFilter = array(), $arGroupBy = false, $arNavStartParams = false, $arSelectFields = array())
	{
		$loader = CBPWorkflowTemplateLoader::GetLoader();
		return $loader->GetTemplatesList($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelectFields);
	}

	public static function checkTemplateActivities(array $template)
	{
		foreach ($template as $activity)
		{
			if (!CBPActivity::IncludeActivityFile($activity['Type']))
				return false;
			if (!empty($activity['Children']))
			{
				$childResult = static::checkTemplateActivities($activity['Children']);
				if (!$childResult)
					return false;
			}
		}

		return true;
	}

	/**
	 * @param array|null $documentType Document type of the template being validated
	 *     ([moduleId, entity, documentType]). The composition of the children of a node is checked against the
	 *     catalog of its actions, and that catalog depends on the document context: an action locked in this
	 *     context is out of it ({@see CBPActivity::validateChild()}). Null keeps the context-free catalog, so a
	 *     caller that does not know the type of the template restricts nothing it did not restrict before.
	 */
	public function validateTemplate(
		$arActivity,
		$user,
		&$activityNames = null,
		$isBranchActive = true,
		?array $documentType = null,
	)
	{
		if ($activityNames === null)
		{
			$activityNames = [];
		}

		$errors = [];
		if (isset($activityNames[$arActivity['Name']]))
		{
			$errors[] = [
				'code' => 'DuplicateActivityName',
				'message' => Loc::getMessage('BPCGWTL_DUPLICATE_ACTIVITY_NAME', ['#NAME#' => $arActivity['Name']]),
				'activityName' => $arActivity['Name'],
			];
		}
		else
		{
			$activityNames[$arActivity['Name']] = true;
		}

		if ($isBranchActive)
		{
			$errors = array_merge($errors, CBPActivity::callStaticMethod(
				$arActivity['Type'],
				'ValidateProperties',
				[$arActivity['Properties'], $user]
			));
		}

		$pref = '';
		if (isset($arActivity['Properties']['Title']))
		{
			$pref =
				Loc::getMessage('BPWTL_ERROR_MESSAGE_PREFIX', ['#TITLE#' => $arActivity['Properties']['Title']])
				. ' '
			;
		}

		foreach ($errors as $i => $e)
		{
			$errors[$i]['message'] = $pref . $e['message'];
			$errors[$i]['activityName'] = $arActivity['Name'];
		}

		if (array_key_exists('Children', $arActivity) && count($arActivity['Children']) > 0)
		{
			$bFirst = true;

			$activityDocumentType = $this->resolveActivityDocumentType($arActivity, $documentType);

			$childrenErrors = [];
			foreach ($arActivity['Children'] as $arChildActivity)
			{
				if (!isset($arChildActivity['Activated']) || $arChildActivity['Activated'] !== 'N')
				{
					// The document context of the node reaches the verdict published, not passed: the signature
					// of ValidateChild is a legacy contract and cannot take a parameter
					// ({@see \Bitrix\Bizproc\Internal\Service\Activity\ChildValidationContext}).
					$childErrors = ChildValidationContext::withDocumentType(
						$activityDocumentType,
						static fn() => CBPActivity::callStaticMethod(
							$arActivity['Type'],
							'ValidateChild',
							[$arChildActivity['Type'], $bFirst, $arChildActivity]
						),
					);

					foreach ($childErrors as $i => $e)
					{
						$childErrors[$i]['message'] = $pref . $e['message'];
						$childErrors[$i]['activityName'] = $arActivity['Name'];
					}

					if ($childErrors)
					{
						$childrenErrors[] = $childErrors;
					}

					$bFirst = false;
				}

				$validateErrors = $this->validateTemplate(
					$arChildActivity,
					$user,
					$activityNames,
					($isBranchActive && (!isset($arChildActivity['Activated']) || $arChildActivity['Activated'] !== 'N')),
					$documentType,
				);
				if ($validateErrors)
				{
					$childrenErrors[] = $validateErrors;
				}
			}

			$errors = array_merge($errors, ...$childrenErrors);
		}

		return $errors;
	}

	/**
	 * Document type the template being saved belongs to: the fields carry it whenever they change it, and an
	 * update that carries only the template itself keeps the type already stored. Without it the validation of
	 * the children of a node would ask the context-free action catalog while the editor offered the contextual
	 * one, and an action locked in this very context would pass ({@see self::validateTemplate()}).
	 *
	 * @param array $arFields Fields being saved, with the document type already parsed into its three parts.
	 * @param int $id Id of the template on an update, 0 while it is being added.
	 */
	private function resolveTemplateDocumentType(array $arFields, int $id): ?array
	{
		if (isset($arFields['MODULE_ID'], $arFields['ENTITY'], $arFields['DOCUMENT_TYPE']))
		{
			return [$arFields['MODULE_ID'], $arFields['ENTITY'], $arFields['DOCUMENT_TYPE']];
		}

		if ($id <= 0)
		{
			return null;
		}

		$row = self::getList([], ['ID' => $id], false, false, ['DOCUMENT_TYPE'])->fetch();
		$storedType = is_array($row) ? ($row['DOCUMENT_TYPE'] ?? null) : null;

		return is_array($storedType) && count($storedType) === 3 ? array_values($storedType) : null;
	}

	/**
	 * Document context one activity is validated in: the type the activity itself works on - the document of
	 * the event of a trigger, the type a node class fixes - and the type of the template when it publishes
	 * none. The very resolution the settings panel and its save-time validation use, so a child the panel
	 * accepted is not rejected here
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getPublishedDocumentTypeForNode()}).
	 *
	 * Asked only about an activity served by the unified panel - the only verdict reading the context - so no
	 * other activity pays for the class-declared and per-instance lookups behind it.
	 */
	private function resolveActivityDocumentType(array $arActivity, ?array $documentType): ?array
	{
		$activityType = (string)($arActivity['Type'] ?? '');
		if ($activityType === '')
		{
			return $documentType;
		}

		$container = BizprocContainer::instance();
		$description = $container->getActivitySearcherService()->searchByCode($activityType);
		if ($description === null || !$description->isServedByUnifiedPanel())
		{
			return $documentType;
		}

		$activityProperties = is_array($arActivity['Properties'] ?? null) ? $arActivity['Properties'] : [];

		return $container
			->getComplexActivityService()
			->getPublishedDocumentTypeForNode($activityType, $activityProperties)
			?? $documentType
		;
	}

	protected function parseFields(&$arFields, $id = 0, $systemImport = false, $validationRequired = true)
	{
		$id = intval($id);
		$updateMode = ($id > 0 ? true : false);
		$addMode = !$updateMode;

		if ($addMode && !isset($arFields["DOCUMENT_TYPE"]))
			throw new CBPArgumentNullException("DOCUMENT_TYPE");

		if (isset($arFields["DOCUMENT_TYPE"]))
		{
			$arDocumentType = CBPHelper::ParseDocumentId($arFields["DOCUMENT_TYPE"]);

			$arFields["MODULE_ID"] = $arDocumentType[0];
			$arFields["ENTITY"] = $arDocumentType[1];
			$arFields["DOCUMENT_TYPE"] = $arDocumentType[2];
		}
		else
		{
			unset($arFields["MODULE_ID"]);
			unset($arFields["ENTITY"]);
			unset($arFields["DOCUMENT_TYPE"]);
		}

		if (isset($arFields["NAME"]) || $addMode)
		{
			$arFields["NAME"] = trim($arFields["NAME"]);
			if ($arFields["NAME"] == '')
				throw new CBPArgumentNullException("NAME");
		}

		if ($addMode && !isset($arFields['TEMPLATE']))
		{
			throw new CBPArgumentNullException('TEMPLATE');
		}

		if (array_key_exists('TEMPLATE', $arFields))
		{
			if (!is_array($arFields["TEMPLATE"]))
			{
				throw new CBPArgumentTypeException("TEMPLATE", "array");
			}
			else
			{
				$userTmp = null;

				if (!$systemImport)
				{
					if (array_key_exists("MODIFIER_USER", $arFields))
					{
						if (is_object($arFields["MODIFIER_USER"]) && is_a($arFields["MODIFIER_USER"], "CBPWorkflowTemplateUser"))
							$userTmp = $arFields["MODIFIER_USER"];
						else
							$userTmp = new CBPWorkflowTemplateUser($arFields["MODIFIER_USER"]);
					}
					else
					{
						$userTmp = new CBPWorkflowTemplateUser();
					}

					$errors = array();
					if ($validationRequired)
					{
						$activityNames = [];
						$documentType = $this->resolveTemplateDocumentType($arFields, $id);
						foreach ($arFields['TEMPLATE'] as $rawTemplate)
						{
							array_push(
								$errors,
								...$this->ValidateTemplate(
									$rawTemplate,
									$userTmp,
									$activityNames,
									true,
									$documentType,
								),
							);
						}

						array_push($errors, ...$this->validateConstantValueRules($arFields, $id, $documentType));
						array_push($errors, ...(new ProcessDataPublicationValidator())->collectErrors($arFields, $id));
					}

					$this->throwOnValidationErrors($errors);
				}
			}
		}
		// The legacy dialogs of the parameters, the variables and the constants write one block and carry no
		// scheme, so the check of the data elements stands on its own here: without it a rename made by such a
		// dialog leaves the references of the template dangling.
		elseif (!$systemImport && $validationRequired)
		{
			$this->throwOnValidationErrors((new ProcessDataPublicationValidator())->collectErrors($arFields, $id));
		}

		$enumValues = array_column(WorkflowTemplateType::cases(), 'value');
		if (isset($arFields['TYPE']) && !in_array($arFields['TYPE'], $enumValues, true))
		{
			$arFields['TYPE'] = WorkflowTemplateType::Default->value;
		}

		if (isset($arFields["ACTIVE"]) && $arFields["ACTIVE"] !== 'N')
			$arFields["ACTIVE"] = 'Y';

		if (isset($arFields["IS_SYSTEM"]) && $arFields["IS_SYSTEM"] !== 'Y')
			$arFields["IS_SYSTEM"] = 'N';

		if (isset($arFields["IS_MODIFIED"]) && $arFields["IS_MODIFIED"] !== 'N')
			$arFields["IS_MODIFIED"] = 'Y';

		unset($arFields["MODIFIED"]);
	}

	/**
	 * Refuses a value rule of a setup constant that cannot be honoured, before anything is written.
	 *
	 * @param int $id Id of the template on an update, 0 while it is being added.
	 * @return list<array> Errors in the shape the validation of the tree collects.
	 */
	private function validateConstantValueRules(array $arFields, int $id, ?array $documentType): array
	{
		if (!is_array($arFields['TEMPLATE'] ?? null))
		{
			return [];
		}

		$setupActivities = [];
		self::collectSetupTemplateActivities($arFields['TEMPLATE'], $setupActivities);
		$newConstants = is_array($arFields['CONSTANTS'] ?? null) ? $arFields['CONSTANTS'] : null;
		$constantsCarryRule = self::constantsCarryValueRule($newConstants ?? []);
		if (!$setupActivities && !$constantsCarryRule)
		{
			return [];
		}

		// the contract of the rule and the messages of the refusals both live in the activity
		if (!CBPActivity::includeActivityFile(self::SETUP_TEMPLATE_ACTIVITY_TYPE))
		{
			return [];
		}

		$declaredRules = [];
		$ruleCarryingScreens = [];
		$rulesUndecided = false;
		foreach ($setupActivities as $setupActivity)
		{
			$rawBlocks = $setupActivity['Properties']['blocks'] ?? null;
			if (!is_string($rawBlocks) && !is_array($rawBlocks))
			{
				continue;
			}

			$valueRules = \CBPSetupTemplateActivity::collectConstantValueRules($rawBlocks);
			if ($valueRules === null)
			{
				// blocks the validation of the tree has already refused: this screen states no rule either way
				$rulesUndecided = true;

				continue;
			}

			if (!$valueRules)
			{
				continue;
			}

			$declaredRules += $valueRules;
			$ruleCarryingScreens[] = [
				'rawBlocks' => $rawBlocks,
				'valueRules' => $valueRules,
				'activityName' => (string)($setupActivity['Name'] ?? ''),
			];
		}

		if (!$declaredRules && !$constantsCarryRule)
		{
			return [];
		}

		$storedConstants = $id > 0 ? self::getTemplateConstants($id) : [];
		$constantNames = self::collectConstantNames($newConstants ?? [], $storedConstants);

		$errors = [];
		foreach ($ruleCarryingScreens as $screen)
		{
			if ($newConstants !== null)
			{
				array_push(
					$errors,
					...$this->collectValueRuleCarrierErrors(
						$screen['valueRules'],
						$newConstants,
						$constantNames,
						$screen['activityName'],
					),
				);
			}

			if ($documentType !== null)
			{
				array_push(
					$errors,
					...$this->collectValueRuleViolationErrors(
						$screen['rawBlocks'],
						$screen['valueRules'],
						$newConstants,
						$storedConstants,
						$documentType,
						$constantNames,
						$screen['activityName'],
					),
				);
			}
		}

		if ($newConstants !== null)
		{
			array_push(
				$errors,
				...$this->collectConstantsValueRuleErrors(
					$newConstants,
					$declaredRules,
					$constantNames,
					(bool)$setupActivities && !$rulesUndecided,
				),
			);
		}

		return $errors;
	}

	/**
	 * @param list<array> $errors
	 * @throws CBPWorkflowTemplateValidationException
	 */
	private function throwOnValidationErrors(array $errors): void
	{
		if (!$errors)
		{
			return;
		}

		$messages = [];
		foreach ($errors as $error)
		{
			$messages[] = trim($error['message']);
		}

		throw new CBPWorkflowTemplateValidationException(implode('.', $messages), $errors);
	}

	/**
	 * The rule as the constants of the template carry it, checked on its own contract and against the rules
	 * the tree declares. The copy of a rule the tree does declare is compared with its source by
	 * collectValueRuleCarrierErrors().
	 *
	 * @param array<string, array> $declaredRules Rules the start screens of the tree declare, keyed by code.
	 * @param array<string, string> $constantNames
	 * @param bool $declaredRulesAreKnown False while the rules of at least one start screen stay unknown, so a
	 *     rule of the copy cannot be told from a rule declared by a screen the tree validation refused.
	 * @return list<array>
	 */
	private function collectConstantsValueRuleErrors(
		array $newConstants,
		array $declaredRules,
		array $constantNames,
		bool $declaredRulesAreKnown,
	): array
	{
		$errors = [];
		foreach ($newConstants as $code => $constant)
		{
			$code = (string)$code;
			if (!is_array($constant))
			{
				continue;
			}

			$rule = $constant['Settings'][ValueRule::SETTINGS_KEY] ?? null;
			if (self::normalizeConstantValueRule($rule) === null)
			{
				continue;
			}

			$constantType = (string)($constant['Type'] ?? '');
			if (!\CBPSetupTemplateActivity::isValidConstantValueRule($constantType, $rule))
			{
				$errors[] = [
					'code' => 'ValueRuleInvalid',
					'message' => Loc::getMessage(
						'BIZPROC_CONSTANT_VALUE_RULE_INVALID',
						['#name#' => $constantNames[$code] ?? $code],
					),
				];

				continue;
			}

			if ($declaredRulesAreKnown && !isset($declaredRules[$code]))
			{
				$errors[] = [
					'code' => 'ValueRuleCarriersDiffer',
					'message' => Loc::getMessage(
						'BIZPROC_CONSTANT_VALUE_RULE_CARRIERS_DIFFER',
						['#name#' => $constantNames[$code] ?? $code],
					),
				];
			}
		}

		return $errors;
	}

	/**
	 * The rule in the copy has to state what its source states. A constant the wizard declares and the set of
	 * constants does not carry at all is passed over: there is nothing to diverge.
	 *
	 * @param array<string, array> $valueRules Rules the tree declares, keyed by constant code.
	 * @param array<string, string> $constantNames
	 * @return list<array>
	 */
	private function collectValueRuleCarrierErrors(
		array $valueRules,
		array $newConstants,
		array $constantNames,
		string $activityName,
	): array
	{
		$errors = [];
		foreach ($valueRules as $code => $rule)
		{
			$copy = $newConstants[$code] ?? null;
			if (!is_array($copy))
			{
				continue;
			}

			$copiedRule = $copy['Settings'][ValueRule::SETTINGS_KEY] ?? null;
			if (self::normalizeConstantValueRule($copiedRule) === self::normalizeConstantValueRule($rule))
			{
				continue;
			}

			$errors[] = [
				'code' => 'ValueRuleCarriersDiffer',
				'message' => Loc::getMessage(
					'BIZPROC_CONSTANT_VALUE_RULE_CARRIERS_DIFFER',
					['#name#' => $constantNames[$code] ?? $code],
				),
				'activityName' => $activityName,
			];
		}

		return $errors;
	}

	/**
	 * A rule the value of its constant breaks. The value measured is the one this save writes; the value saved
	 * before stands only for a constant the save does not carry, where it stays in force (Q-1: a value written
	 * past the base type has never met any rule, whether the save changes the rule or not).
	 *
	 * @param array<string, array> $valueRules Rules the tree declares, keyed by constant code.
	 * @param array|null $newConstants Constants of the template being saved, null when the save carries none.
	 * @param array<string, string> $constantNames
	 * @return list<array>
	 */
	private function collectValueRuleViolationErrors(
		string|array $rawBlocks,
		array $valueRules,
		?array $newConstants,
		array $storedConstants,
		array $documentType,
		array $constantNames,
		string $activityName,
	): array
	{
		$values = [];
		foreach (array_keys($valueRules) as $code)
		{
			$constant = $newConstants[$code] ?? $storedConstants[$code] ?? null;
			if (!is_array($constant) || CBPHelper::isEmptyValue($constant['Default'] ?? null))
			{
				continue;
			}

			$values[$code] = $constant['Default'];
		}

		if (!$values)
		{
			return [];
		}

		$blocks = \CBPSetupTemplateActivity::filterBlocksByConstantCodes($rawBlocks, array_keys($values));
		if (!$blocks)
		{
			return [];
		}

		$errors = [];
		$invalidCodes = \CBPSetupTemplateActivity::collectInvalidConstantCodes($documentType, $blocks, $values);
		foreach ($invalidCodes as $code)
		{
			$errors[] = [
				'code' => 'ValueRuleBrokenByStoredValue',
				'message' => Loc::getMessage(
					'BIZPROC_CONSTANT_VALUE_RULE_BROKEN_BY_STORED_VALUE',
					['#name#' => $constantNames[$code] ?? $code],
				),
				'activityName' => $activityName,
			];
		}

		return $errors;
	}

	/**
	 * Every SetupTemplateActivity of a template tree: a template may carry more than one start screen.
	 *
	 * @param array $activities Activities of one level of the tree.
	 * @param list<array> $setupActivities Accumulator, appended in place.
	 */
	private static function collectSetupTemplateActivities(array $activities, array &$setupActivities): void
	{
		$searcher = BizprocContainer::instance()->getActivitySearcherService();
		$setupActivityCode = $searcher->normalizeActivityCode(self::SETUP_TEMPLATE_ACTIVITY_TYPE);

		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			// the type is resolved to a class the same normalized way, so the case of the string means nothing
			$type = $activity['Type'] ?? null;
			if (is_string($type) && $searcher->normalizeActivityCode($type) === $setupActivityCode)
			{
				$setupActivities[] = $activity;
			}

			if (is_array($activity['Children'] ?? null))
			{
				self::collectSetupTemplateActivities($activity['Children'], $setupActivities);
			}
		}
	}

	/**
	 * @return array<string, string> names of the constants by code, the set being saved winning
	 */
	private static function collectConstantNames(array $newConstants, array $storedConstants): array
	{
		$names = [];
		foreach ([$storedConstants, $newConstants] as $constants)
		{
			foreach ($constants as $code => $constant)
			{
				$name = is_array($constant) ? ($constant['Name'] ?? null) : null;
				if (is_string($name) && $name !== '')
				{
					$names[(string)$code] = $name;
				}
			}
		}

		return $names;
	}

	/**
	 * Does any constant of the set carry a rule at all: the same test the checks of the copy apply, made
	 * without the tree and without the constants saved before.
	 */
	private static function constantsCarryValueRule(array $constants): bool
	{
		foreach ($constants as $constant)
		{
			$rule = is_array($constant) ? ($constant['Settings'][ValueRule::SETTINGS_KEY] ?? null) : null;
			if (self::normalizeConstantValueRule($rule) !== null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Comparable form of a value rule, null for no rule at all: a bound means the same whether it arrives as
	 * a number or as its decimal string after a round trip through a stored template.
	 */
	private static function normalizeConstantValueRule(mixed $rule): ?array
	{
		if (!is_array($rule) || !$rule)
		{
			return null;
		}

		$normalized = [];
		foreach ($rule as $key => $value)
		{
			if (is_numeric($value))
			{
				$normalized[(string)$key] = (string)(int)$value;
			}
			else
			{
				$normalized[(string)$key] = is_scalar($value) ? (string)$value : $value;
			}
		}

		ksort($normalized);

		return $normalized;
	}

	/**
	 * $actorId is the user the caller already authorized, and it wins over the global one: authorship decides
	 * the "own templates" scope, so it must come from the same user the ACL was checked for. A caller that
	 * checks rights for one user while the global holds another would otherwise hand the row to the wrong
	 * owner. Callers with no request user of their own (install, import, conversion) pass nothing and keep
	 * the global.
	 *
	 * $markFirstPublication says whether this creation is an act of publishing a process; null leaves the
	 * answer to $systemImport. See {@see FirstPublicationWriter}.
	 */
	public static function add(
		$fields,
		$systemImport = false,
		?int $actorId = null,
		?bool $markFirstPublication = null,
	)
	{
		$loader = CBPWorkflowTemplateLoader::GetLoader();
		$templateType = $loader->getTemplateType($fields);
		$loader->setShowInTimelineBeforeAdd($fields);
		$loader->setTemplateType($fields, $templateType);

		$fields = (new FirstPublicationWriter())
			->prepareForInsert($fields, (bool)$systemImport, $markFirstPublication)
		;

		$authorId = $loader->resolveActorId($actorId);
		$fields['CREATED_BY'] = $authorId;
		$fields['UPDATED_BY'] = $authorId;

		$id = $loader->AddTemplate($fields, $systemImport);
		$loader->addTemplateSettings($id, $fields);

		return $id;
	}

	public function addTemplateSettings(int $templateId, array $templateFields): void
	{
		if (
			isset($templateFields['DOCUMENT_TYPE'])
			&& !empty($templateFields['TEMPLATE_SETTINGS'])
		)
		{
			$rows = [];
			foreach ($templateFields['TEMPLATE_SETTINGS'] as $option => $value)
			{
				$rows[] = [
					'TEMPLATE_ID' => $templateId,
					'NAME' => $option,
					'VALUE' => $value,
				];
			}

			WorkflowTemplateSettingsTable::addMultiSettings($rows);
		}
	}

	private function deleteTemplateSettings(int $templateId)
	{
		WorkflowTemplateSettingsTable::deleteSettingsByFilter(['=TEMPLATE_ID' => $templateId]);
	}

	/**
	 * A non-positive $actorId is not a caller decision but the absence of one - an anonymous or system context
	 * where CBPWorkflowTemplateUser resolves to 0 - so it falls back to the global instead of writing 0 into
	 * authorship and making the row nobody's.
	 */
	private function resolveActorId(?int $actorId): int
	{
		return $actorId > 0 ? $actorId : (int)CurrentUser::get()->getId();
	}

	/** $actorId - see {@see self::add()}: the already authorized user wins over the global one. */
	public static function update(
		$id,
		$fields,
		$systemImport = false,
		$validationRequired = true,
		bool $deferCacheInvalidation = false,
		bool $publishesCommonVersion = false,
		?int $actorId = null,
	)
	{
		$loader = CBPWorkflowTemplateLoader::GetLoader();
		$templateType = $loader->getTemplateType($fields, $id);
		$loader->setShowInTimelineBeforeUpdate($fields, $templateType, (int)$id);
		$loader->setTemplateType($fields, $templateType);

		if (isset($fields['TEMPLATE']) && !$systemImport)
		{
			$fields['IS_MODIFIED'] = 'Y';
		}

		$fields['UPDATED_BY'] = $loader->resolveActorId($actorId);

		$templateId = (int)$id;
		if ($templateId <= 0)
		{
			throw new CBPArgumentNullException('id');
		}

		$connection = \Bitrix\Main\Application::getConnection();
		$connection->startTransaction();
		try
		{
			if (!(new PilotVersionRepository())->lockTemplateForUpdate($templateId))
			{
				throw new PersistenceException("Workflow template {$templateId} was not found");
			}

			self::guardFrozenSettings($templateId, $fields, $publishesCommonVersion);

			$fields = (new FirstPublicationWriter())
				->prepareForUpdate($templateId, $fields, $publishesCommonVersion)
			;

			$returnId = $loader->UpdateTemplate($templateId, $fields, $systemImport, $validationRequired);
			$loader->updateTemplateSettings($templateId, $fields);

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollbackTransaction($connection);

			throw $exception;
		}

		if (!$deferCacheInvalidation)
		{
			self::invalidateCachesAfterUpdate($returnId, $fields);
		}

		return $returnId;
	}

	private static function guardFrozenSettings(
		int $templateId,
		array $fields,
		bool $publishesCommonVersion,
	): void
	{
		if (!array_key_exists('CONSTANTS', $fields))
		{
			return;
		}
		if ($publishesCommonVersion && array_key_exists('TEMPLATE', $fields))
		{
			return;
		}

		$refusal = (new SettingsFreezeGate())->findRefusal($templateId);
		if ($refusal === null)
		{
			return;
		}

		throw new CBPWorkflowTemplateValidationException(
			$refusal->getMessage(),
			[[
				'message' => $refusal->getMessage(),
				'code' => $refusal->getCode(),
			]],
		);
	}

	public static function invalidateCachesAfterUpdate(int $templateId, array $fields): void
	{
		self::cleanTemplateCache($templateId);

		if (array_key_exists('TEMPLATE', $fields))
		{
			$presence = new PilotPresence();
			$presence->synchronize();
			$presence->invalidate();

			$restrictedArea = new RestrictedTemplateArea();
			$restrictedArea->synchronize();
			$restrictedArea->invalidate();
		}
	}

	private static function rollbackTransaction(\Bitrix\Main\DB\Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Bitrix\Main\DB\TransactionException)
		{
		}
	}

	public function setTemplateType(array &$fields, ?string $templateType = null)
	{
		if (!empty($templateType))
		{
			$fields['TYPE'] = $templateType;
		}
	}

	private function updateTemplateSettings(int $templateId, array $templateFields)
	{
		if (
			isset($templateFields['DOCUMENT_TYPE'])
			&& !empty($templateFields['TEMPLATE_SETTINGS'])
		)
		{
			$filter = [];
			$filter['LOGIC'] = 'OR';
			foreach ($templateFields['TEMPLATE_SETTINGS'] as $option => $value)
			{
				$filter[] =  ['=NAME' => $option, '=TEMPLATE_ID' => $templateId];
			}

			$existSettings = [];
			$result = WorkflowTemplateSettingsTable::getList([
				'filter' => $filter,
				'select' => ['ID', 'NAME']
			]);
			while ($row = $result->fetch())
			{
				$existSettings[$row['NAME']] = $row['ID'];
			}

			foreach ($templateFields['TEMPLATE_SETTINGS'] as $option => $value)
			{
				if (isset($existSettings[$option]))
				{
					WorkflowTemplateSettingsTable::update($existSettings[$option], ['VALUE' => $value]);
				}
				else
				{
					WorkflowTemplateSettingsTable::add([
						'TEMPLATE_ID' => $templateId,
						'NAME' => $option,
						'VALUE' => $value,
					]);
				}
			}
		}
	}

	public function getTemplateType(array $fields, int $id = 0): ?string
	{
		if (
			empty($fields['DOCUMENT_TYPE'])
			|| empty($fields['TEMPLATE'])
			|| !array_key_exists('AUTO_EXECUTE', $fields)
		)
		{
			if ($id > 0)
			{
				return null;
			}

			return WorkflowTemplateType::Default->value;  // Not enough data to determine type
		}

		$templateType = WorkflowTemplateType::Default->value;
		if ($this->isNodes($fields['TEMPLATE']))
		{
			$templateType = WorkflowTemplateType::Nodes->value;
		}
		elseif ($this->isRobot((int)$fields['AUTO_EXECUTE']))
		{
			$templateType = WorkflowTemplateType::Robots->value;
			if ($this->isExternalModified($fields))
			{
				$templateType = WorkflowTemplateType::CustomRobots->value;
			}
		}

		return $templateType;
	}

	public function setShowInTimelineBeforeUpdate(array &$fields, ?string $templateType, int $id): void
	{
		if ($templateType !== WorkflowTemplateType::Robots->value)
		{
			return;
		}

		$isCrm = array_key_exists('DOCUMENT_TYPE', $fields) && $fields['DOCUMENT_TYPE'][0] === 'crm';
		if (!$isCrm)
		{
			return;
		}

		// The previous type is the stored one, never the requested one: a client save path does not send
		// TYPE at all, and a requested value would let the client trigger or suppress this reset.
		$previousType = $this->getStoredTemplateType($id);

		if ($previousType !== null && $previousType !== WorkflowTemplateType::Robots->value)
		{
			$fields['TEMPLATE_SETTINGS']['SHOW_IN_TIMELINE'] = 'N';
		}
	}

	private function getStoredTemplateType(int $id): ?string
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::getRow([
			'select' => ['TYPE'],
			'filter' => ['=ID' => $id],
		]);

		return $row['TYPE'] ?? null;
	}

	private function isRobot(int $autoExecute): bool
	{
		return $autoExecute === \CBPDocumentEventType::Automation || $autoExecute === \CBPDocumentEventType::Script;
	}

	private function isNodes(array $template): bool
	{
		return ($template[0]['Type'] ?? '') === NodesToTemplate::ROOT_NODE_TYPE;
	}

	public function setShowInTimelineBeforeAdd(array &$fields): void
	{
		$isCrm = array_key_exists('DOCUMENT_TYPE', $fields) && $fields['DOCUMENT_TYPE'][0] === 'crm';

		if ($isCrm && empty($fields['TEMPLATE_SETTINGS']['SHOW_IN_TIMELINE']))
		{
			$fields['TEMPLATE_SETTINGS']['SHOW_IN_TIMELINE'] = 'N';
		}
	}

	private function getSerializedForm($arTemplate)
	{
		return WorkflowTemplateTable::toSerializedForm($arTemplate);
	}

	private function getSerializedSettings($arTemplate)
	{
		return WorkflowTemplateTable::encodeJson($arTemplate);
	}

	public static function delete($id)
	{
		$loader = CBPWorkflowTemplateLoader::GetLoader();
		$loader->DeleteTemplate($id);
		$loader->deleteTemplateSettings($id);
		self::cleanTemplateCache($id);

		\Bitrix\Bizproc\Storage\Factory::getInstance()->onAfterTemplateDelete($id);
	}

	protected static function cleanTemplateCache($id)
	{
		$cache = \Bitrix\Main\Application::getInstance()->getManagedCache();
		$cache->clean(self::CONSTANTS_CACHE_TAG_PREFIX . $id);
		unset(self::$workflowConstants[$id]);
	}

	public function deleteTemplate($id)
	{
		$id = (int)$id;
		if ($id <= 0)
		{
			throw new Exception("id");
		}

		$hasInstance = (bool)WorkflowInstanceTable::getRow([
			'select' => ['ID'],
			'filter' => ['=WORKFLOW_TEMPLATE_ID' => $id],
		]);

		if (!$hasInstance)
		{
			WorkflowTemplateTable::delete($id);

			$event = new Event(
				'bizproc',
				'onAfterWorkflowTemplateDelete',
				[
					'ID' => $id,
				]
			);
			EventManager::getInstance()->send($event);

			WorkflowDurationStatTable::deleteAllByTemplateId($id);
			Bitrix\Main\Config\Option::delete('bizproc', ['name' => 'tpl_track_on_' . $id]);
		}
		else
		{
			throw new CBPInvalidOperationException(GetMessage("BPCGWTL_CANT_DELETE"));
		}
	}

	public function loadWorkflow($workflowTemplateId)
	{
		$workflowTemplateId = intval($workflowTemplateId);
		if ($workflowTemplateId <= 0)
		{
			throw new CBPArgumentOutOfRangeException("workflowTemplateId", $workflowTemplateId);
		}

		$dbTemplatesList = $this->GetTemplatesList(
			[],
			['ID' => $workflowTemplateId],
			false,
			false,
			['TEMPLATE', 'VARIABLES', 'PARAMETERS']
		);
		$arTemplatesListItem = $dbTemplatesList->Fetch();

		if (!$arTemplatesListItem)
		{
			throw new Exception(str_replace('#ID#', $workflowTemplateId, GetMessage('BPCGWTL_INVALID_WF_ID')));
		}

		$arTemplatesListItem['ID'] = $workflowTemplateId;

		return $this->loadWorkflowFromArray($arTemplatesListItem);
	}

	public function loadWorkflowFromArray($templatesListItem): array
	{
		$wfId = $templatesListItem['ID'];
		$wfTemplate = $templatesListItem['TEMPLATE'];
		$wfVariablesTypes = $templatesListItem['VARIABLES'];
		$wfParametersTypes = $templatesListItem['PARAMETERS'];

		if (!is_array($wfTemplate) || count($wfTemplate) <= 0)
		{
			throw new Exception(str_replace('#ID#', $wfId, GetMessage('BPCGWTL_EMPTY_TEMPLATE')));
		}

		$activityNames = [];
		$rootActivity = $this->parseWorkflowTemplate($wfTemplate, $activityNames);

		return [$rootActivity, $wfVariablesTypes, $wfParametersTypes];
	}

	private function parseWorkflowTemplate($arWorkflowTemplate, &$arActivityNames, CBPActivity $parentActivity = null)
	{
		if (!is_array($arWorkflowTemplate))
		{
			throw new CBPArgumentOutOfRangeException('arWorkflowTemplate');
		}

		foreach ($arWorkflowTemplate as $activityFormatted)
		{
			if (in_array($activityFormatted['Name'], $arActivityNames))
			{
				throw new Exception('DuplicateActivityName');
			}

			$arActivityNames[] = $activityFormatted['Name'];
			$activity = $this->createActivity($activityFormatted);
			if ($activity === null)
			{
				$code = $activityFormatted['Type'] ?? '(?)';
				throw new Exception("Activity {$code} is not found.");
			}

			$activity->initializeFromArray($activityFormatted['Properties']);
			if ($parentActivity)
			{
				$parentActivity->fixUpParentChildRelationship($activity);
			}

			if (!empty($activityFormatted['Children']))
			{
				$this->parseWorkflowTemplate($activityFormatted['Children'], $arActivityNames, $activity);
			}
		}

		return $activity;
	}

	private function createActivity(array $activityFormatted): ?CBPActivity
	{
		$code = $activityFormatted['Type'];
		$name = $activityFormatted['Name'];
		$activated = !isset($activityFormatted['Activated']) || $activityFormatted['Activated'] === 'Y';
		$documentContext = $activityFormatted['Document'] ?? null;

		if (CBPActivity::includeActivityFile($code))
		{
			$instance = CBPActivity::createInstance($code, $name, $activityFormatted);
			if ($instance)
			{
				$instance->setActivated($activated);
				// Not truthiness: an id of "0" is a document the author named, and dropping it here would
				// leave the node working over the document of the process instead.
				$hasDocumentContext = $documentContext !== null && $documentContext !== '';
				if ($hasDocumentContext)
				{
					$instance->setDocumentContext($documentContext);
				}
			}

			return $instance;
		}
		else
		{
			throw new Exception("Activity {$code} is not found.");
		}
	}

	public static function getStatesOfTemplate($arWorkflowTemplate)
	{
		if (!is_array($arWorkflowTemplate))
			throw new CBPArgumentTypeException("arWorkflowTemplate", "array");

		if (!is_array($arWorkflowTemplate[0]))
			throw new CBPArgumentTypeException("arWorkflowTemplate");

		$arStates = array();
		foreach ($arWorkflowTemplate[0]["Children"] as $state)
			$arStates[$state["Name"]] = ($state["Properties"]["Title"] <> '' ? $state["Properties"]["Title"] : $state["Name"]);

		return $arStates;
	}

	private static function findSetStateActivities($arWorkflowTemplate)
	{
		$arResult = array();

		if ($arWorkflowTemplate["Type"] == "SetStateActivity")
			$arResult[] = $arWorkflowTemplate["Properties"]["TargetStateName"];

		if (is_array($arWorkflowTemplate["Children"]))
		{
			foreach ($arWorkflowTemplate["Children"] as $key => $value)
				$arResult = $arResult + self::FindSetStateActivities($arWorkflowTemplate["Children"][$key]);
		}

		return $arResult;
	}

	public static function getTransfersOfState($arWorkflowTemplate, $stateName)
	{
		if (!is_array($arWorkflowTemplate))
			throw new CBPArgumentTypeException("arWorkflowTemplate", "array");

		if (!is_array($arWorkflowTemplate[0]))
			throw new CBPArgumentTypeException("arWorkflowTemplate");

		$stateName = trim($stateName);
		if ($stateName == '')
			throw new CBPArgumentNullException("stateName");

		$arTransfers = array();
		foreach ($arWorkflowTemplate[0]["Children"] as $state)
		{
			if ($stateName == $state["Name"])
			{
				foreach ($state["Children"] as $event)
					$arTransfers[$event["Name"]] = self::FindSetStateActivities($event);

				break;
			}
		}

		return $arTransfers;
	}

	private static function parseDocumentTypeStates($arTemplatesListItem)
	{
		$arWorkflowTemplate = $arTemplatesListItem["TEMPLATE"];
		if (!is_array($arWorkflowTemplate))
			throw new CBPArgumentTypeException("arTemplatesListItem");

		$result = array(
			"ID" => "",
			"TEMPLATE_ID" => $arTemplatesListItem["ID"],
			"TEMPLATE_NAME" => $arTemplatesListItem["NAME"],
			"TEMPLATE_DESCRIPTION" => $arTemplatesListItem["DESCRIPTION"],
			"STATE_NAME" => "",
			"STATE_TITLE" => "",
			"TEMPLATE_PARAMETERS" => $arTemplatesListItem["PARAMETERS"],
			"STATE_PARAMETERS" => array(),
			"STATE_PERMISSIONS" => array(),
			"WORKFLOW_STATUS" => -1,
		);

		$type = "CBP".$arWorkflowTemplate[0]["Type"];
		$bStateMachine = (
			$type === CBPStateMachineWorkflowActivity::class
			|| (
				class_exists($type)
				&& is_subclass_of($type, CBPStateMachineWorkflowActivity::class)
			)
		);

		if ($bStateMachine)
		{
			//if (strlen($stateName) <= 0)
			$stateName = $arWorkflowTemplate[0]["Properties"]["InitialStateName"];

			if (is_array($arWorkflowTemplate[0]["Children"]))
			{
				foreach ($arWorkflowTemplate[0]["Children"] as $state)
				{
					if ($stateName == $state["Name"])
					{
						$result["STATE_NAME"] = $stateName;
						$result["STATE_TITLE"] = $state["Properties"]["Title"];
						$result["STATE_PARAMETERS"] = array();
						$result["STATE_PERMISSIONS"] = $state["Properties"]["Permission"];

						if (is_array($state["Children"]))
						{
							foreach ($state["Children"] as $event)
							{
								if ($event["Type"] == "EventDrivenActivity")
								{
									if ($event["Children"][0]["Type"] == "HandleExternalEventActivity")
									{
										$result["STATE_PARAMETERS"][] = array(
											"NAME" => $event["Children"][0]["Name"],
											"TITLE" => $event["Children"][0]["Properties"]["Title"],
											"PERMISSION" => $event["Children"][0]["Properties"]["Permission"],
										);
									}
								}
							}
						}

						break;
					}
				}
			}
		}
		else
		{
			$result["STATE_PERMISSIONS"] = $arWorkflowTemplate[0]["Properties"]["Permission"] ?? null;
		}

		if (is_array($result["STATE_PERMISSIONS"]))
		{
			$arKeys = array_keys($result["STATE_PERMISSIONS"]);
			foreach ($arKeys as $key)
			{
				$ar = self::ExtractValuesFromVariables($result["STATE_PERMISSIONS"][$key], $arTemplatesListItem["VARIABLES"], $arTemplatesListItem["CONSTANTS"]);
				$result["STATE_PERMISSIONS"][$key] = CBPHelper::MakeArrayFlat($ar);
			}
		}

		return $result;
	}

	private static function extractValuesFromVariables($ar, $variables, $constants = array())
	{
		if (is_string($ar) && preg_match(CBPActivity::ValuePattern, $ar, $arMatches))
			$ar = array($arMatches['object'], $arMatches['field']);

		if (is_array($ar))
		{
			if (!CBPHelper::IsAssociativeArray($ar))
			{
				if (count($ar) == 2 && ($ar[0] == 'Variable' || $ar[0] == 'Constant' || $ar[0] == 'Template'))
				{
					if ($ar[0] == 'Variable' && is_array($variables) && array_key_exists($ar[1], $variables))
						return array($variables[$ar[1]]["Default"]);
					if ($ar[0] == 'Constant' && is_array($constants) && array_key_exists($ar[1], $constants))
						return array($constants[$ar[1]]["Default"]);

					return array();
				}

				$arResult = array();
				foreach ($ar as $ar1)
					$arResult[] = self::ExtractValuesFromVariables($ar1, $variables, $constants);

				return $arResult;
			}
		}

		return $ar;
	}

	/**
	 * @param int|null $visibleForUserId narrows the result to the templates this employee may see. Absent by
	 *  default: the same states feed the automatic start and the permissions of a document, and both must
	 *  keep seeing every template of the type.
	 */
	public static function getDocumentTypeStates($documentType, $autoExecute = -1, $stateName = "", ?int $visibleForUserId = null)
	{
		$arFilter = array("DOCUMENT_TYPE" => $documentType);
		$autoExecute = intval($autoExecute);

		$cacheKey = implode('@', $documentType).'@'.$autoExecute;

		if (!isset(static::$typesStates[$cacheKey]))
		{
			$result = array();
			if ($autoExecute >= 0)
				$arFilter["AUTO_EXECUTE"] = $autoExecute;
			$arFilter["ACTIVE"] = "Y";

			$dbTemplatesList = self::GetList(
				array(),
				$arFilter,
				false,
				false,
				array('ID', 'NAME', 'DESCRIPTION', 'TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS')
			);
			while ($arTemplatesListItem = $dbTemplatesList->Fetch())
				$result[$arTemplatesListItem["ID"]] = self::ParseDocumentTypeStates($arTemplatesListItem);

			static::$typesStates[$cacheKey] = $result;
		}

		if ($visibleForUserId === null)
		{
			return static::$typesStates[$cacheKey];
		}

		return static::narrowStatesToVisible(static::$typesStates[$cacheKey], $visibleForUserId);
	}

	/**
	 * Drops the templates the employee may not see. The narrowing is done over the result of the cache and
	 * never over the cache itself: the employee is not part of its key, so a set narrowed once and stored
	 * there would be handed to the next employee as his own.
	 */
	private static function narrowStatesToVisible(array $states, int $visibleForUserId): array
	{
		if (!$states)
		{
			return $states;
		}

		$visibleIds = (new PilotVisibilityProvider())->filterVisibleIds($visibleForUserId, array_keys($states));

		return array_intersect_key($states, array_flip($visibleIds));
	}

	public static function getTemplateState($workflowTemplateId, $stateName = "")
	{
		$workflowTemplateId = intval($workflowTemplateId);
		if ($workflowTemplateId <= 0)
			throw new CBPArgumentOutOfRangeException("workflowTemplateId", $workflowTemplateId);

		$result = null;

		$dbTemplatesList = self::GetList(
			array(),
			array('ID' => $workflowTemplateId),
			false,
			false,
			array('ID', 'NAME', 'DESCRIPTION', 'TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS')
		);
		if ($arTemplatesListItem = $dbTemplatesList->Fetch())
			$result = self::ParseDocumentTypeStates($arTemplatesListItem);
		else
			throw new Exception(str_replace("#ID#", $workflowTemplateId, GetMessage("BPCGWTL_INVALID_WF_ID")));

		return $result;
	}

	public static function getTemplateUserId($workflowTemplateId)
	{
		$userId = 0;
		$dbTemplatesList = self::GetList(
			[],
			['ID' => (int) $workflowTemplateId], false,false, ['USER_ID']
		);
		if ($row = $dbTemplatesList->Fetch())
		{
			$userId = (int) $row['USER_ID'];
		}

		return $userId;
	}

	public static function getTemplateConstants($workflowTemplateId)
	{
		$workflowTemplateId = (int) $workflowTemplateId;
		if ($workflowTemplateId <= 0)
			throw new CBPArgumentOutOfRangeException("workflowTemplateId", $workflowTemplateId);

		if (!isset(self::$workflowConstants[$workflowTemplateId]))
		{
			$cache = \Bitrix\Main\Application::getInstance()->getManagedCache();
			$cacheTag = self::CONSTANTS_CACHE_TAG_PREFIX.$workflowTemplateId;
			if ($cache->read(3600*24*7, $cacheTag))
			{
				self::$workflowConstants[$workflowTemplateId] = (array) $cache->get($cacheTag);
			}
			else
			{
				$iterator = self::GetList(
					array(),
					array('ID' => $workflowTemplateId),
					false,
					false,
					array('CONSTANTS')
				);
				if ($row = $iterator->fetch())
				{
					self::$workflowConstants[$workflowTemplateId] = (array) $row['CONSTANTS'];
					$cache->set($cacheTag, self::$workflowConstants[$workflowTemplateId]);
				}
				else
					self::$workflowConstants[$workflowTemplateId] = array();

			}
		}

		return self::$workflowConstants[$workflowTemplateId];
	}

	/**
	 * @param $workflowTemplateId - Workflow Template ID
	 * @return bool
	 * @throws CBPArgumentOutOfRangeException
	 */
	public static function isConstantsTuned($workflowTemplateId)
	{
		$result = true;
		$constants = self::getTemplateConstants($workflowTemplateId);
		if (!empty($constants) && is_array($constants))
		{
			foreach ($constants as $key => $const)
			{
				$value = isset($const['Default']) ? $const['Default'] : null;
				if (CBPHelper::getBool($const['Required']) && CBPHelper::isEmptyValue($value))
				{
					$result = false;
					break;
				}
			}
		}
		return $result;
	}

	public static function checkWorkflowParameters($arTemplateParameters, $arPossibleValues, $documentType, &$arErrors)
	{
		$arErrors = [];
		$arWorkflowParameters = [];

		if (count($arTemplateParameters) <= 0)
		{
			return [];
		}

		$documentService = CBPRuntime::getRuntime()->getDocumentService();

		foreach ($arTemplateParameters as $parameterKey => $arParameter)
		{
			$arErrorsTmp = [];

			$arWorkflowParameters[$parameterKey] = $documentService->GetFieldInputValue(
				$documentType,
				$arParameter,
				$parameterKey,
				$arPossibleValues,
				$arErrorsTmp
			);

			if (
				CBPHelper::getBool($arParameter['Required'] ?? null)
				&& CBPHelper::isEmptyValue($arWorkflowParameters[$parameterKey] ?? null)
			)
			{
				$arErrorsTmp[] = [
					'code' => 'RequiredValue',
					'message' => Loc::getMessage('BPCGWTL_INVALID8', ['#NAME#' => $arParameter['Name']]),
					'parameter' => $parameterKey,
				];
			}

			if ($arErrorsTmp)
			{
				$arErrors[] = $arErrorsTmp;
			}
		}

		if ($arErrors)
		{
			$arErrors = array_merge(...$arErrors);
		}

		return $arWorkflowParameters;
	}

	public static function searchTemplatesByDocumentType($documentType, $autoExecute = -1)
	{
		$result = [];

		$arFilter = ['DOCUMENT_TYPE' => $documentType];
		$autoExecute = intval($autoExecute);
		if ($autoExecute >= 0)
		{
			$arFilter['AUTO_EXECUTE'] = $autoExecute;
		}

		$dbTemplatesList = self::GetList(
			[],
			$arFilter,
			false,
			false,
			['ID', 'NAME', 'DESCRIPTION', 'AUTO_EXECUTE']
		);
		while ($arTemplatesListItem = $dbTemplatesList->Fetch())
		{
			$result[] = [
				'ID' => $arTemplatesListItem['ID'],
				'NAME' => $arTemplatesListItem['NAME'],
				'DESCRIPTION' => $arTemplatesListItem['DESCRIPTION'],
				'AUTO_EXECUTE' => $arTemplatesListItem['AUTO_EXECUTE'],
			];
		}

		return $result;
	}

	public static function &findActivityByName(&$arWorkflowTemplate, $activityName)
	{
		$res = null;

		if (!$activityName)
		{
			return $res;
		}

		foreach ($arWorkflowTemplate as $key => $value)
		{
			$valueName = $value['Name'] ?? null;
			if ($valueName == $activityName)
			{
				return $arWorkflowTemplate[$key];
			}

			if (is_array($value["Children"] ?? null))
			{
				if ($res = &self::FindActivityByName($arWorkflowTemplate[$key]["Children"], $activityName))
				{
					return $res;
				}
			}
		}

		return $res;
	}

	public static function &FindParentActivityByName(&$arWorkflowTemplate, $activityName)
	{
		$res = null;
		foreach ($arWorkflowTemplate as $key => $value)
		{
			if (is_array($value["Children"]))
			{
				for ($i = 0, $s = sizeof($value['Children']); $i < $s; $i++)
				{
					if ($value["Children"][$i]["Name"] == $activityName)
						return $arWorkflowTemplate[$key];
				}

				if ($res = &self::FindParentActivityByName($arWorkflowTemplate[$key]["Children"], $activityName))
					return $res;
			}
		}

		return $res;
	}

	public static function exportTemplate($id, $bCompress = true)
	{
		$tpl = WorkflowTemplateTable::getById($id)->fetchObject();
		if (!$tpl)
		{
			return false;
		}

		$packer = new \Bitrix\Bizproc\Workflow\Template\Packer\Bpt();
		if (!$bCompress)
		{
			$packer->disableCompression();
		}

		return $packer->pack($tpl)->getPackage();
	}

	private static function walkThroughWorkflowTemplate(&$arWorkflowTemplate, $callback, $user)
	{
		foreach ($arWorkflowTemplate as $key => $value)
		{
			if (!call_user_func_array($callback, array($value, $user)))
				return false;

			if (isset($value['Children']) && is_array($value['Children']))
			{
				if (
					!self::WalkThroughWorkflowTemplate(
						$arWorkflowTemplate[$key]['Children'],
						$callback,
						$user
					)
				)
				{
					return false;
				}
			}
		}
		return true;
	}

	private static function importTemplateChecker($arActivity, $user)
	{
		$arErrors = CBPActivity::CallStaticMethod($arActivity["Type"], "ValidateProperties", array($arActivity["Properties"], $user));
		if (count($arErrors) > 0)
		{
			$m = "";
			foreach ($arErrors as $er)
				$m .= $er["message"].". ";

			throw new Exception($m);

			return false;
		}

		return true;
	}

	public static function checkImportedTemplateActivities(array $template, CBPWorkflowTemplateUser $user): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		try
		{
			$isValid = self::WalkThroughWorkflowTemplate(
				$template,
				["CBPWorkflowTemplateLoader", "ImportTemplateChecker"],
				$user
			);

			if ($isValid !== true)
			{
				$result->addError(new \Bitrix\Main\Error('Imported template activities check failed'));
			}
		}
		catch (\Throwable $e)
		{
			$result->addError(new \Bitrix\Main\Error($e->getMessage()));
		}

		return $result;
	}

	public static function importTemplate($id, $documentType, $autoExecute, $name, $description, $datum, $systemCode = null, $systemImport = false)
	{

		$packer = new \Bitrix\Bizproc\Workflow\Template\Packer\Bpt();
		$unpackResult = $packer->unpack($datum);

		if (!$unpackResult->isSuccess())
		{
			throw new \Bitrix\Main\ArgumentException(reset($unpackResult->getErrorMessages()));
		}

		$templateFields = $unpackResult->getTpl()->collectValues();
		$templateFields['DOCUMENT_FIELDS'] = $unpackResult->getDocumentFields();
		$templateFields['TEMPLATE_SETTINGS'] = $unpackResult->getTemplateSettings();

		return self::importTemplateFromArray($id, $documentType, $autoExecute, $name, $description, $templateFields, $systemCode, $systemImport);
	}

	public static function importTemplateFromArray($id, $documentType, $autoExecute, $name, $description, $templateFields, $systemCode = null, $systemImport = false)
	{
		$id = intval($id);
		if ($id <= 0)
			$id = 0;

		if (!$systemImport)
		{
			if (!self::WalkThroughWorkflowTemplate($templateFields["TEMPLATE"], array("CBPWorkflowTemplateLoader", "ImportTemplateChecker"), new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser)))
				return false;
		}
		elseif ($id > 0 && !empty($templateFields["CONSTANTS"]))
		{
			$userConstants = self::getTemplateConstants($id);
			if (!empty($userConstants))
			{
				foreach ($userConstants as $constantName => $constantData)
				{
					if (isset($templateFields["CONSTANTS"][$constantName]))
					{
						$templateFields["CONSTANTS"][$constantName]['Default'] = $constantData['Default'];
					}
				}
			}
		}

		$templateData = array(
			"DOCUMENT_TYPE" => $documentType,
			"AUTO_EXECUTE" => $autoExecute,
			"NAME" => $name,
			"DESCRIPTION" => $description,
			"TEMPLATE" => $templateFields["TEMPLATE"],
			"PARAMETERS" => $templateFields["PARAMETERS"],
			"VARIABLES" => $templateFields["VARIABLES"],
			"CONSTANTS" => $templateFields["CONSTANTS"],
			"USER_ID" => $systemImport ? 1 : $GLOBALS["USER"]->GetID(),
			"MODIFIER_USER" => new CBPWorkflowTemplateUser($systemImport ? 1 : CBPWorkflowTemplateUser::CurrentUser),
		);
		if (!is_null($systemCode))
			$templateData["SYSTEM_CODE"] = $systemCode;
		if ($id <= 0)
			$templateData['ACTIVE'] = 'Y';

		$settingsForImport = (new ProcessDataMechanicsResolver())
			->settingsForImport($templateFields, (int)$id)
		;
		if ($settingsForImport)
		{
			$templateData['TEMPLATE_SETTINGS'] = $settingsForImport;
		}

		if ($id > 0)
			self::Update($id, $templateData, $systemImport);
		else
			$id = self::Add($templateData, $systemImport, markFirstPublication: false);

		if ($templateFields['DOCUMENT_FIELDS'] && is_array($templateFields['DOCUMENT_FIELDS']))
		{
			static::importDocumentFields($documentType, $templateFields['DOCUMENT_FIELDS']);
		}

		return $id;
	}

	public static function importDocumentFields(array $documentType, array $fields)
	{
		$documentService = CBPRuntime::GetRuntime(true)->getDocumentService();
		$currentDocumentFields = $documentService->GetDocumentFields($documentType, true);

		\Bitrix\Main\Type\Collection::sortByColumn($fields, "sort");
		$len = mb_strlen("_PRINTABLE");

		foreach ($fields as $code => $field)
		{
			//skip printable
			if (mb_strtoupper(mb_substr($code, -$len)) == "_PRINTABLE")
			{
				continue;
			}

			//skip references
			if (mb_strpos($code, '.') !== false)
			{
				continue;
			}

			$documentField = [
				"name" => $field["Name"],
				"code" => $code,
				"type" => $field["Type"],
				"multiple" => $field["Multiple"] ?? null,
				"required" => $field["Required"] ?? null,
			];

			if (isset($field['Options']) && is_array($field["Options"]) && count($field["Options"]) > 0)
			{
				$documentField['options'] = '';
				foreach ($field["Options"] as $k => $v)
				{
					if (!is_scalar($v))
					{
						continue;
					}

					$documentField["options"] .= "[".$k."]".$v."\n";
				}
			}

			unset($field["Name"], $field["Type"], $field["Multiple"], $field["Required"], $field["Options"]);
			$documentField = array_merge($documentField, $field);

			if ($currentDocumentFields && !array_key_exists($code, $currentDocumentFields))
			{
				$documentService->AddDocumentField($documentType, $documentField);
			}
			else
			{
				$documentService->UpdateDocumentField($documentType, $documentField);
			}
		}
	}

	public function getTemplatesList(
		array $order = ['ID' => 'DESC'],
		array $filter = [],
		$group = false,
		$navStartParams = false,
		array $select = []
	)
	{
		$this->prepareTemplatesSelect($select);
		$this->prepareTemplatesFilter($filter);
		$this->prepareTemplatesOrder($order);

		if (is_array($group) && empty($group))
		{
			$countQuery =
				WorkflowTemplateTable::query()
					->addSelect(new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)'))
					->setFilter($filter);

			$countResult = $countQuery->fetch();

			return $countResult ? $countResult['CNT'] : false;
		}

		$query = WorkflowTemplateTable::query()
			->setSelect($select)
			->setFilter($filter)
			->setOrder($order)
		;

		if (is_array($group) && !empty($group))
		{
			$query->setSelect($group);
			$query->addSelect(new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)'));
			$query->setGroup($group);
		}

		$topCount = 0;
		$hasNavStartParams = (is_array($navStartParams) && !empty($navStartParams));
		if ($hasNavStartParams && isset($navStartParams['nTopCount']))
		{
			$topCount = (int)$navStartParams['nTopCount'];
		}

		if ($hasNavStartParams && $topCount > 0)
		{
			$query->setLimit($topCount);
		}
		elseif ($hasNavStartParams && isset($navStartParams['iNumPage']) && isset($navStartParams['nPageSize']))
		{
			$query->setOffset(((int)$navStartParams['iNumPage'] - 1) * (int)$navStartParams['nPageSize']);
			$query->setLimit((int)$navStartParams['nPageSize']);
		}

		$result = $query->exec();

		return new CBPWorkflowTemplateResult($result, $this->useGZipCompression);
	}

	protected function prepareTemplatesFilter(array &$filter): void
	{
		if (array_key_exists('DOCUMENT_TYPE', $filter) && is_array($filter['DOCUMENT_TYPE']))
		{
			[$moduleId, $entity, $documentId] = CBPHelper::ParseDocumentId($filter['DOCUMENT_TYPE']);
			$filter['=MODULE_ID'] = $moduleId;
			$filter['=ENTITY'] = $entity;
			$filter['=DOCUMENT_TYPE'] = $documentId;

			unset($filter['DOCUMENT_TYPE']);
		}

		$entity = WorkflowTemplateTable::getEntity();
		$strictMatchFields = [];

		foreach ($entity->getFields() as $field)
		{
			if ($field->getDataType() === 'string' || $field->getDataType() === 'boolean')
			{
				$strictMatchFields[] = $field->getName();
			}
		}

		foreach ($strictMatchFields as $fieldName)
		{
			if (isset($filter[$fieldName]) && is_string($filter[$fieldName]))
			{
				$filter['=' . $fieldName] = $filter[$fieldName];
				unset($filter[$fieldName]);
			}
		}

		if (array_key_exists('AUTO_EXECUTE', $filter))
		{
			$filter['AUTO_EXECUTE'] = AutoExecuteFilter::getFilterValue((int)$filter['AUTO_EXECUTE']);
		}
	}

	protected function prepareTemplatesSelect(array &$select): void
	{
		if (empty($select))
		{
			$select = [
				'ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'DOCUMENT_STATUS', 'AUTO_EXECUTE',
				'NAME', 'DESCRIPTION', 'TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS',
				'MODIFIED', 'USER_ID', 'ACTIVE', 'IS_MODIFIED', 'IS_SYSTEM', 'SORT', 'TYPE', 'SYSTEM_CODE',
			];
		}

		if ($select === ['*'])
		{
			$select = array_merge($select, ['USER_NAME', 'USER_LAST_NAME', 'USER_SECOND_NAME', 'USER_LOGIN']);
		}

		if (count(array_intersect($select, ['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])) > 0)
		{
			foreach (['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'] as $field)
			{
				if (!in_array($field, $select, true))
				{
					$select[] = $field;
				}
			}
		}

		$userFields = ['USER_NAME', 'USER_LAST_NAME', 'USER_SECOND_NAME', 'USER_LOGIN'];
		if (array_intersect($select, $userFields))
		{
			foreach ($userFields as $userField)
			{
				if (in_array($userField, $select, true))
				{
					$select[$userField] = str_replace('USER_', 'USER.', $userField);

					$index = array_search($userField, $select, true);
					unset($select[$index]);
				}
			}
		}
	}

	/**
	 * Sanitizes the requested sort order against the entity map.
	 *
	 * The legacy CBPHelper::PrepareSql() implementation silently ignored any sort
	 * field/direction that was not part of the whitelist. The ORM query, on the
	 * contrary, throws Main\ArgumentException ("Unknown field definition") for an
	 * unknown field and for an invalid direction. Untrusted input (e.g. the grid
	 * "by"/"order" URL parameters) must not be able to trigger a fatal, so we drop
	 * unknown fields and normalize the direction, restoring the tolerant behavior.
	 */
	protected function prepareTemplatesOrder(array &$order): void
	{
		if (empty($order))
		{
			return;
		}

		$entity = WorkflowTemplateTable::getEntity();

		$orderableFields = [];
		foreach ($entity->getScalarFields() as $field)
		{
			$orderableFields[mb_strtoupper($field->getName())] = $field->getName();
		}

		$preparedOrder = [];
		foreach ($order as $fieldName => $direction)
		{
			$upperName = mb_strtoupper((string)$fieldName);
			if (!isset($orderableFields[$upperName]))
			{
				continue;
			}

			$preparedOrder[$orderableFields[$upperName]] =
				mb_strtoupper(trim((string)$direction)) === 'DESC' ? 'DESC' : 'ASC'
			;
		}

		$order = $preparedOrder;
	}

	/**
	 * @param array $fields
	 * @param bool $isSystemImport
	 * @return int
	 * @throws CBPArgumentNullException
	 * @throws CBPArgumentTypeException
	 * @throws CBPWorkflowTemplateValidationException
	 */
	public function addTemplate(array $fields, bool $isSystemImport = false): int
	{
		self::ParseFields($fields, 0, $isSystemImport);

		unset($fields['ID']);

		$tplFields = $this->prepareTplFields($fields);
		$result = WorkflowTemplateTable::add($tplFields);

		$id = 0;
		if ($result->isSuccess())
		{
			$id = $result->getId();

			$event = new Event(
				'bizproc',
				'onAfterWorkflowTemplateAdd',
				[
					'FIELDS' => $fields,
				]
			);
			EventManager::getInstance()->send($event);

			return $id;
		}

		return $id;
	}

	/**
	 * @param $id
	 * @param array $fields
	 * @param bool $systemImport
	 * @param bool $validationRequired
	 * @return int
	 * @throws CBPArgumentNullException
	 * @throws CBPArgumentTypeException
	 * @throws CBPWorkflowTemplateValidationException
	 */
	public function updateTemplate($id, array $fields, bool $systemImport = false, bool $validationRequired = true): int
	{
		$id = (int)$id;
		if ($id <= 0)
		{
			throw new CBPArgumentNullException('id');
		}

		self::ParseFields($fields, $id, $systemImport, $validationRequired);

		$tplFields = $this->prepareTplFields($fields);
		$result = WorkflowTemplateTable::update($id, $tplFields);

		if ($result->isSuccess())
		{
			$event = new Event(
				'bizproc',
				'onAfterWorkflowTemplateUpdate',
				[
					'ID' => $id,
					'FIELDS' => $fields,
				]
			);
			EventManager::getInstance()->send($event);

			return $id;
		}

		throw new PersistenceException(
			"Unable to update workflow template {$id}: ". implode('; ', $result->getErrorMessages()),
		);
	}

	private function prepareTplFields(array $fields): array
	{
		$fields['MODIFIED'] = new \Bitrix\Main\Type\DateTime();
		unset($fields['TEMPLATE_SETTINGS']);

		return $fields;
	}

	public static function useGZipCompression()
	{
		$useGZipCompressionOption = \Bitrix\Main\Config\Option::get("bizproc", "use_gzip_compression", "");
		if ($useGZipCompressionOption === "Y")
		{
			$result = true;
		}
		elseif ($useGZipCompressionOption === "N")
		{
			$result = false;
		}
		else
		{
			$result = function_exists("gzcompress");
		}

		return $result;
	}

	public static function getCompressedFieldLength($field): false|int
	{
		if (self::useGZipCompression())
		{
			return mb_strlen(gzcompress(serialize($field), 9));
		}

		return mb_strlen(serialize($field));
	}

	private function prepareFieldsForTemplate(array $fields): array
	{
		unset($fields['MODIFIER_USER']);
		[$moduleId, $entity, $documentType] = $fields['DOCUMENT_TYPE'];
		$fields['MODULE_ID'] = $moduleId;
		$fields['ENTITY'] = $entity;
		$fields['DOCUMENT_TYPE'] = $documentType;

		return $fields;
	}

	public static function prepareDocumentType(array &$fields)
	{
		if (array_key_exists('DOCUMENT_TYPE', $fields) && !is_array($fields['DOCUMENT_TYPE']))
		{
			$fields['DOCUMENT_TYPE'] = [
				$fields['MODULE_ID'],
				$fields['ENTITY'],
				$fields['DOCUMENT_TYPE']
			];
		}
	}

	public static function prepareSettingsCollection(array &$fields)
	{
		if (array_key_exists('TEMPLATE_SETTINGS', $fields))
		{
			$newValues = [];
			$settingsValues = $fields['TEMPLATE_SETTINGS']?->getAll();

			if (!empty($settingsValues))
			{
				foreach ($settingsValues as $setting)
				{
					$newValues[$setting->getName()] = $setting->getValue();
				}
			}

			$fields['TEMPLATE_SETTINGS'] = $newValues;
		}
	}

	private function isExternalModified(array $fields): ?bool
	{
		$template = new \Bitrix\Bizproc\Automation\Engine\Template($fields['DOCUMENT_TYPE']);
		$template->setTemplate($fields['TEMPLATE']);
		$template->setExecuteType($fields['AUTO_EXECUTE']);

		return $template->isExternalModified();
	}
}

class CBPWorkflowTemplateResult extends CDBResult
{
	private $useGZipCompression = false;

	public function __construct($res, $useGZipCompression)
	{
		$this->useGZipCompression = $useGZipCompression;
		parent::__construct($res);
	}

	private function getFromSerializedForm($value)
	{
		return WorkflowTemplateTable::getFromSerializedForm($value);
	}

	private function getFromSerializedSettings($value)
	{
		return WorkflowTemplateTable::decodeJson($value);
	}

	function fetch()
	{
		$res = parent::Fetch();

		if ($res)
		{
			if (array_key_exists("DOCUMENT_TYPE", $res) && !is_array($res["DOCUMENT_TYPE"]))
			{
				$res["DOCUMENT_TYPE"] = array($res["MODULE_ID"], $res["ENTITY"], $res["DOCUMENT_TYPE"]);
			}

			if (array_key_exists("TEMPLATE", $res) && !is_array($res["TEMPLATE"]))
			{
				$res["TEMPLATE"] = $this->GetFromSerializedForm($res["TEMPLATE"]);
			}

			if (array_key_exists("VARIABLES", $res) && !is_array($res["VARIABLES"]))
			{
				$res["VARIABLES"] = $this->GetFromSerializedForm($res["VARIABLES"]);
			}

			if (array_key_exists("CONSTANTS", $res) && !is_array($res["CONSTANTS"]))
			{
				$res["CONSTANTS"] = $this->GetFromSerializedForm($res["CONSTANTS"]);
			}

			if (array_key_exists("PARAMETERS", $res) && !is_array($res["PARAMETERS"]))
			{
				$res["PARAMETERS"] = $this->GetFromSerializedForm($res["PARAMETERS"]);
			}

			if (array_key_exists('TEMPLATE_SETTINGS', $res) && !is_array($res['TEMPLATE_SETTINGS']))
			{
				$res['TEMPLATE_SETTINGS'] = $this->getFromSerializedSettings($res['TEMPLATE_SETTINGS']);
			}

			if (array_key_exists('MODIFIED', $res) && $res['MODIFIED'] instanceof \Bitrix\Main\Type\DateTime)
			{
				$res['MODIFIED'] =
					$res['MODIFIED']->toString(new \Bitrix\Main\Context\Culture(['FORMAT_DATETIME' => FORMAT_DATETIME]))
				;
			}
		}

		return $res;
	}
}

class CBPWorkflowTemplateUser
{
	const CurrentUser = "CurrentUser";

	private $userId = 0;
	private $isAdmin = false;
	private $fullName = '';

	public function __construct($userId = null)
	{
		$this->userId = 0;
		$this->isAdmin = false;
		$this->fullName = '';

		if (is_int($userId))
		{
			$userGroups = CUser::GetUserGroup($userId);
			$this->userId = (int)$userId;
			$this->isAdmin = in_array(1, $userGroups);
		}
		elseif ($userId === self::CurrentUser)
		{
			global $USER;
			if (is_object($USER) && $USER->IsAuthorized())
			{
				$this->userId = (int)$USER->GetID();
				$this->isAdmin = (
					$USER->IsAdmin()
					|| CModule::IncludeModule('bitrix24') && CBitrix24::IsPortalAdmin($USER->GetID())
				);
				$this->fullName = $USER->GetFullName();
			}
		}
	}

	public function getId()
	{
		return $this->userId;
	}

	public function getBizprocId()
	{
		return $this->userId > 0 ? 'user_'.$this->userId : null;
	}

	public function isAdmin()
	{
		return ($this->isAdmin || self::isBpAdmin($this->userId));
	}

	public function getFullName()
	{
		return $this->fullName;
	}

	private static function isBpAdmin(int $userId): bool
	{
		static $ids;
		if ($ids === null)
		{
			$idsString = \Bitrix\Main\Config\Option::get('bizproc', 'wtu_admins');
			$ids = array_map('intval', explode(',', $idsString));
		}
		return $userId && in_array($userId, $ids, true);
	}
}

class CBPWorkflowTemplateValidationException extends Exception
{
	private $errors;
	public function __construct($message = "", array $errors = array())
	{
		parent::__construct($message, 10010);
		$this->errors = $errors;
	}

	public function getErrors()
	{
		return $this->errors;
	}
}
