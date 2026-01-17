<?php

use Bitrix\Bizproc;
use Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload;
use Bitrix\Bizproc\Result\RenderedResult;
use Bitrix\Main;

abstract class CBPActivity
{
	use Bizproc\Debugger\Mixins\WriterDebugTrack;
	use Bizproc\Runtime\Mixins\ActivityRuntimePropertyGetter;

	public ?CBPActivity $parent = null;

	public int $executionStatus = CBPActivityExecutionStatus::Initialized;
	public int $executionResult = CBPActivityExecutionResult::None;

	private array $arStatusChangeHandlers = [];

	public const StatusChangedEvent = 0;
	public const ExecutingEvent = 1;
	public const CancelingEvent = 2;
	public const ClosedEvent = 3;
	public const FaultingEvent = 4;

	private const ValueSinglePattern = '\{=\s*(?<object>[a-z0-9_]+)\s*\:\s*(?<field>[a-z0-9_\.]+)(\s*>\s*(?<mod1>[a-z0-9_\:]+)(\s*,\s*(?<mod2>[a-z0-9_]+))?)?\s*\}';

	public const ValuePattern = '#^\s*'.self::ValueSinglePattern.'\s*$#i';
	private const ValueSimplePattern = '#^\s*\{\{(.*?)\}\}\s*$#i';
	public const ValueInlinePattern = '#'.self::ValueSinglePattern.'#i';
	/** Internal pattern used in calc.php */
	public const ValueInternalPattern = '\{=\s*([a-z0-9_]+)\s*\:\s*([a-z0-9_\.]+)(\s*>\s*([a-z0-9_\:]+)(\s*,\s*([a-z0-9_]+))?)?\s*\}';

	public const CalcPattern = '#^\s*(=\s*(.*)|\{\{=\s*(.*)\s*\}\})\s*$#is';
	public const CalcInlinePattern = '#\{\{=\s*(.*?)\s*\}\}([^\}]|$)#is';

	protected array $arProperties = [];
	protected array $arPropertiesTypes = [];

	protected string $name = '';
	protected int $outputPortId = 0;
	protected bool $activated = true;
	/** @var CBPWorkflow | \Bitrix\Bizproc\Debugger\Workflow\DebugWorkflow $workflow */
	public $workflow = null;

	public array $arEventsMap = [];

	protected int $resultPriority = 0;

	protected ?string $documentContext;

	/************************  PROPERTIES  ************************************************/

	/**
	 * @return array
	 */
	public function getDocumentId()
	{
		if (isset($this->documentContext))
		{
			return $this->getRootActivity()->parseValue($this->documentContext);
		}

		return $this->getRootActivity()->getDocumentId();
	}

	/**
	 * @param array $documentId
	 * @return void
	 */
	public function setDocumentId($documentId)
	{
		$this->getRootActivity()->setDocumentId($documentId);
	}

	/**
	 * @return array
	 */
	public function getDocumentType()
	{
		if (isset($this->documentContext))
		{
			return $this->workflow->getService('DocumentService')->getDocumentType(
				 $this->getDocumentId()
			);
		}

		$rootActivity = $this->getRootActivity();
		if (empty($rootActivity->documentType))
		{
			/** @var CBPDocumentService $documentService */
			$documentService = $this->workflow->getService('DocumentService');
			$rootActivity->setDocumentType(
				$documentService->getDocumentType($rootActivity->getDocumentId())
			);
		}

		return $rootActivity->documentType;
	}

	public function setDocumentType(array $documentType): void
	{
		$this->getRootActivity()->documentType = $documentType;
	}

	public function getDocumentEventType(): int
	{
		return (int)$this->getRootActivity()->getRawProperty(CBPDocument::PARAM_DOCUMENT_EVENT_TYPE);
	}

	/**
	 * @return int
	 */
	public function getWorkflowStatus()
	{
		return $this->getRootActivity()->getWorkflowStatus();
	}

	public function setWorkflowStatus($status)
	{
		$this->getRootActivity()->setWorkflowStatus($status);
	}

	public function setFieldTypes(array $arFieldTypes = []): void
	{
		$rootActivity = $this->getRootActivity();
		foreach ($arFieldTypes as $key => $value)
		{
			$rootActivity->arFieldTypes[$key] = $value;
		}
	}

	/**
	 * @return int
	 */
	public function getWorkflowTemplateId()
	{
		$rootActivity = $this->getRootActivity();
		//prevent recursion by checking setter
		if (method_exists($rootActivity, 'setWorkflowTemplateId'))
		{
			return $rootActivity->getWorkflowTemplateId();
		}

		return 0;
	}

	/**
	 * @return int
	 */
	public function getTemplateUserId()
	{
		$userId = 0;
		$rootActivity = $this->getRootActivity();
		//prevent recursion by checking setter
		if (method_exists($rootActivity, 'setTemplateUserId'))
		{
			$userId = $rootActivity->getTemplateUserId();
		}

		if (!$userId && $tplId = $this->getWorkflowTemplateId())
		{
			$userId = CBPWorkflowTemplateLoader::getTemplateUserId($tplId);
		}

		return $userId;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [];
	}

	/**********************************************************/
	protected function clearProperties()
	{
		if ($this !== $this->getRootActivity())
		{
			throw new CBPInvalidOperationException('Only root activity can clear properties.');
		}

		foreach ($this->arPropertiesTypes as $id => $property)
		{
			$this->clearPropertyValue($property, $this->__get($id));
		}
	}

	private function clearPropertyValue(array $property, mixed $value): void
	{
		$documentId = $this->getDocumentId();
		$documentType = $this->getDocumentType();
		$documentService = $this->workflow->getService('DocumentService');
		$fieldType = Bizproc\FieldType::normalizeProperty($property);
		$fieldTypeObject = $documentService->getFieldTypeObject($documentType, $fieldType);
		$fieldTypeObject?->setDocumentId($documentId)->clearValue($value);
	}

	public function getPropertyBaseType($propertyName)
	{
		$rootActivity = $this->getRootActivity();

		return $rootActivity->arFieldTypes[$rootActivity->arPropertiesTypes[$propertyName]["Type"]]["BaseType"] ?? null;
	}

	public function getTemplatePropertyType($propertyName)
	{
		$rootActivity = $this->GetRootActivity();
		if ($propertyName === 'TargetUser' && !isset($rootActivity->arPropertiesTypes[$propertyName]))
		{
			return ['Type' => 'user'];
		}

		return $rootActivity->arPropertiesTypes[$propertyName] ?? null;
	}

	public function setProperties($arProperties = array())
	{
		if (count($arProperties) > 0)
		{
			foreach ($arProperties as $key => $value)
			{
				$this->arProperties[$key] = $value;
			}
		}
	}

	public function setPropertiesTypes($arPropertiesTypes = array())
	{
		if (count($arPropertiesTypes) > 0)
		{
			foreach ($arPropertiesTypes as $key => $value)
			{
				$this->arPropertiesTypes[$key] = $value;
			}
		}
	}

	public function getPropertyType($propertyName): ?array
	{
		return $this->arPropertiesTypes[$propertyName] ?? null;
	}

	/**********************************************************/
	protected function clearVariables()
	{
		if ($this !== $this->getRootActivity())
		{
			throw new CBPInvalidOperationException('Only root activity can clear variables.');
		}

		if (is_array($this->arVariablesTypes))
		{
			foreach ($this->arVariablesTypes as $id => $property)
			{
				$this->clearPropertyValue($property, $this->getVariable($id));
			}
		}
	}

	public function getVariableBaseType($variableName)
	{
		$rootActivity = $this->getRootActivity();

		return $rootActivity->arFieldTypes[$rootActivity->arVariablesTypes[$variableName]["Type"]]["BaseType"] ?? null;
	}

	public function setVariables($variables = [])
	{
		if (!is_array($variables))
		{
			throw new CBPArgumentTypeException("variables", "array");
		}

		if (count($variables) > 0)
		{
			$rootActivity = $this->GetRootActivity();
			foreach ($variables as $key => $value)
			{
				$rootActivity->arVariables[$key] = $value;
			}
		}
	}

	public function setVariablesTypes($arVariablesTypes = array())
	{
		if (count($arVariablesTypes) > 0)
		{
			$rootActivity = $this->GetRootActivity();
			foreach ($arVariablesTypes as $key => $value)
				$rootActivity->arVariablesTypes[$key] = $value;
		}
	}

	public function setVariable($name, $value)
	{
		$rootActivity = $this->GetRootActivity();
		$rootActivity->arVariables[$name] = $value;
	}

	public function getVariable($name)
	{
		$rootActivity = $this->GetRootActivity();

		if (array_key_exists($name, $rootActivity->arVariables))
		{
			return $rootActivity->arVariables[$name];
		}

		return null;
	}

	public function getVariableType($name)
	{
		$rootActivity = $this->GetRootActivity();
		return isset($rootActivity->arVariablesTypes[$name]) ? $rootActivity->arVariablesTypes[$name] : null;
	}

	private function getConstantTypes()
	{
		$rootActivity = $this->GetRootActivity();
		if (method_exists($rootActivity, 'GetWorkflowTemplateId'))
		{
			$templateId = $rootActivity->GetWorkflowTemplateId();
			if ($templateId > 0)
			{
				return CBPWorkflowTemplateLoader::getTemplateConstants($templateId);
			}
		}
		return null;
	}

	public function getConstant($name)
	{
		$constants = $this->GetConstantTypes();
		if (isset($constants[$name]['Default']))
			return $constants[$name]['Default'];
		return null;
	}

	public function getConstantType($name)
	{
		$constants = $this->GetConstantTypes();
		if (isset($constants[$name]))
			return $constants[$name];
		return array('Type' => null, 'Multiple' => false, 'Required' => false, 'Options' => null);
	}

	public function isVariableExists($name)
	{
		$rootActivity = $this->GetRootActivity();
		$variables = $rootActivity->arVariables ?? [];
		$variablesTypes = $rootActivity->arVariablesTypes ?? [];

		return (
			array_key_exists($name, $variables)
			|| array_key_exists($name, $variablesTypes)
		);
	}

	/************************************************/
	public function getName(): string
	{
		return $this->name;
	}

	public function getType(): string
	{
		return substr(get_class($this), 3);
	}

	public function getRootActivity(): CBPActivity
	{
		if ($this->workflow)
		{
			return $this->workflow->getRootActivity();
		}

		$p = $this;
		while ($p->parent !== null)
		{
			$p = $p->parent;
		}

		return $p;
	}

	public function setWorkflow(CBPWorkflow $workflow)
	{
		$this->workflow = $workflow;
	}

	public function unsetWorkflow()
	{
		$this->workflow = null;
	}

	public function getWorkflowInstanceId()
	{
		return $this->workflow->GetInstanceId();
	}

	public function setStatusTitle($title = '')
	{
		$rootActivity = $this->GetRootActivity();
		$stateService = $this->workflow->GetService("StateService");
		if ($rootActivity instanceof CBPStateMachineWorkflowActivity)
		{
			$arState = $stateService->GetWorkflowState($this->GetWorkflowInstanceId());

			$arActivities = $rootActivity->CollectNestedActivities();
			/** @var CBPActivity $activity */
			foreach ($arActivities as $activity)
				if ($activity->GetName() == $arState["STATE_NAME"])
					break;

			$stateService->SetStateTitle(
				$this->GetWorkflowInstanceId(),
				$activity->Title.($title != '' ? ": ".$title : '')
			);
		}
		else
		{
			if ($title != '')
			{
				$stateService->SetStateTitle(
					$this->GetWorkflowInstanceId(),
					$title
				);
			}
		}
	}

	public function addStatusTitle($title = '')
	{
		if ($title == '')
			return;

		$stateService = $this->workflow->GetService("StateService");

		$mainTitle = $stateService->GetStateTitle($this->GetWorkflowInstanceId());
		$mainTitle .= ((mb_strpos($mainTitle, ": ") !== false) ? ", " : ": ").$title;

		$stateService->SetStateTitle($this->GetWorkflowInstanceId(), $mainTitle);
	}

	public function deleteStatusTitle($title = '')
	{
		if ($title == '')
			return;

		$stateService = $this->workflow->GetService("StateService");
		$mainTitle = $stateService->GetStateTitle($this->GetWorkflowInstanceId());

		$ar1 = explode(":", $mainTitle);
		if (count($ar1) <= 1)
			return;

		$newTitle = "";

		$ar2 = explode(",", $ar1[1]);
		foreach ($ar2 as $a)
		{
			$a = trim($a);
			if ($a != $title)
			{
				if ($newTitle <> '')
					$newTitle .= ", ";
				$newTitle .= $a;
			}
		}

		$result = $ar1[0].($newTitle <> '' ? ": " : "").$newTitle;

		$stateService->SetStateTitle($this->GetWorkflowInstanceId(), $result);
	}

	private function getPropertyValueRecursive($val, $convertToType = null, ?callable $decorator = null)
	{
		// array(2, 5, array("SequentialWorkflowActivity1", "DocumentApprovers"))
		// array("Document", "IBLOCK_ID")
		// array("Workflow", "id")
		// "Hello, {=SequentialWorkflowActivity1:DocumentApprovers}, {=Document:IBLOCK_ID}!"

		$parsed = static::parseExpression($val);
		if ($parsed)
		{
			$result = null;
			if ($convertToType)
				$parsed['modifiers'][] = $convertToType;
			$this->getRealParameterValue(
				$parsed['object'],
				$parsed['field'],
				$result,
				$parsed['modifiers'],
				$decorator
			);
			return array(1, $result);
		}
		elseif (is_array($val))
		{
			$b = true;
			$r = array();

			$keys = array_keys($val);

			$i = 0;
			foreach ($keys as $key)
			{
				if ($key."!" != $i."!")
				{
					$b = false;
					break;
				}
				$i++;
			}

			foreach ($keys as $key)
			{
				[$t, $a] = $this->GetPropertyValueRecursive($val[$key], $convertToType, $decorator);
				if ($b)
				{
					if ($t == 1 && is_array($a))
						$r = array_merge($r, $a);
					else
						$r[] = $a;
				}
				else
				{
					$r[$key] = $a;
				}
			}

			if (count($r) == 2)
			{
				$keys = array_keys($r);
				if ($keys[0] == 0 && $keys[1] == 1 && is_string($r[0]) && is_string($r[1]))
				{
					$result = null;
					$modifiers = $convertToType ? array($convertToType) : array();
					if ($this->GetRealParameterValue($r[0], $r[1], $result, $modifiers, $decorator))
						return array(1, $result);
				}
			}
			return array(2, $r);
		}
		else
		{
			if (is_string($val))
			{
				$typeClass = null;
				$fieldTypeObject = null;
				if ($convertToType)
				{
					/** @var CBPDocumentService $documentService */
					$documentService = $this->workflow->GetService("DocumentService");
					$documentType = $this->GetDocumentType();

					$typesMap = $documentService->getTypesMap($documentType);
					$convertToType = mb_strtolower($convertToType);
					if (isset($typesMap[$convertToType]))
					{
						$typeClass = $typesMap[$convertToType];
						$fieldTypeObject = $documentService->getFieldTypeObject(
							$documentType,
							array('Type' => \Bitrix\Bizproc\FieldType::STRING)
						);
					}
				}

				$calc = new Bizproc\Calc\Parser($this);
				if (preg_match(self::CalcPattern, $val))
				{
					$r = $calc->Calculate($val);
					if ($r !== null)
					{
						if ($typeClass && $fieldTypeObject)
						{
							if (is_array($r))
								$fieldTypeObject->setMultiple(true);
							$r = $fieldTypeObject->convertValue($r, $typeClass);
						}
						return array(is_array($r)? 1 : 2, $r);
					}
				}

				//parse inline calculator
				$val = preg_replace_callback(
					static::CalcInlinePattern,
					function($matches) use ($calc)
					{
						$r = $calc->Calculate($matches[1]);
						if (is_array($r))
							$r = implode(', ', CBPHelper::MakeArrayFlat($r));
						return $r !== null? $r.$matches[2] : $matches[0];
					},
					$val
				);

				//parse properties
				$val = preg_replace_callback(
					static::ValueInlinePattern,
					fn($matches) => $this->parseStringParameter($matches, $convertToType, $decorator),
					$val
				);

				//converting...
				if ($typeClass && $fieldTypeObject)
				{
					$val = $fieldTypeObject->convertValue($val, $typeClass);
				}
			}

			return array(2, $val);
		}
	}

	private function getRealParameterValue(
		$objectName,
		$fieldName,
		&$result,
		array $modifiers = [],
		?callable $decorator = null
	)
	{
		$return = true;

		if (str_ends_with($fieldName, '_printable'))
		{
			$fieldName = mb_substr($fieldName, 0, -10);
			if (!in_array('printable', $modifiers))
			{
				array_unshift($modifiers, 'printable');
			}
		}

		[$property, $result] = $this->getRuntimeProperty($objectName, $fieldName, $this);

		if ($property === null && $result === null)
		{
			$return = false;
		}

		// compatibility: for Document object return empty string instead of null
		if ($objectName === 'Document' && !isset($result))
		{
			$result = '';
		}

		if ($property && $result)
		{
			/** @var CBPDocumentService $documentService */
			$documentService = $this->workflow->getService("DocumentService");
			$fieldTypeObject = $documentService->getFieldTypeObject($this->getDocumentType(), $property);
			if ($fieldTypeObject)
			{
				$fieldTypeObject->setDocumentId($this->getDocumentId());
				$result = $fieldTypeObject->internalizeValue($objectName, $result);
			}
		}

		if ($return && $result !== null && $result !== '')
		{
			$result = $this->applyPropertyValueModifiers($fieldName, $property, $result, $modifiers);

			if ($decorator)
			{
				$result = $decorator($objectName, $fieldName, $property, $result);
			}
		}

		return $return;
	}

	private function applyPropertyValueModifiers($fieldName, $property, $value, array $modifiers)
	{
		if (empty($property) || empty($modifiers) || !is_array($property))
			return $value;

		$typeName = null;
		$typeClass = null;
		$format = null;
		$modifiers = array_slice($modifiers, 0, 2);

		$rootActivity = $this->GetRootActivity();
		$documentId = $rootActivity->GetDocumentId();
		/** @var CBPDocumentService $documentService */
		$documentService = $this->workflow->GetService("DocumentService");
		$documentType = $this->GetDocumentType();

		$typesMap = $documentService->getTypesMap($documentType);
		foreach ($modifiers as $m)
		{
			$m = mb_strtolower($m);
			if (isset($typesMap[$m]))
			{
				$typeName ??= $m;
				$typeClass ??= $typesMap[$m];
			}
			else
			{
				$format = $m;
			}
		}

		$priority = $format && array_search($format, $modifiers) === 0 ? 'format' : 'type';

		if ($typeName === \Bitrix\Bizproc\FieldType::STRING && $format === 'printable')
		{
			$typeClass = null;
		}

		if ($typeClass || $format)
		{
			$fieldTypeObject = $documentService->getFieldTypeObject($documentType, $property);

			if ($fieldTypeObject)
			{
				$fieldTypeObject->setDocumentId($documentId);

				if ($format && $priority === 'format')
				{
					$value = $fieldTypeObject->formatValue($value, $format);
					//$value becomes String
					$fieldTypeObject->setTypeClass(Bizproc\BaseType\StringType::class);
				}

				if ($typeClass)
				{
					$value = $fieldTypeObject->convertValue($value, $typeClass);
				}

				if ($format && $priority !== 'format')
				{
					$value = $fieldTypeObject->formatValue($value, $format);
				}
			}
			elseif ($format == 'printable') // compatibility: old printable style
			{
				$value = $documentService->GetFieldValuePrintable(
					$documentId,
					$fieldName,
					$property['Type'],
					$value,
					$property
				);
			}
		}

		if ($format === 'printable' && is_array($value))
		{
			$value = CBPHelper::stringify($value);
		}

		return $value;
	}

	private function parseStringParameter($matches, $convertToType = null, ?callable $decorator = null)
	{
		$result = "";
		$modifiers = [];
		if (!empty($matches['mod1']))
		{
			$modifiers[] = $matches['mod1'];
		}
		if (!empty($matches['mod2']))
		{
			$modifiers[] = $matches['mod2'];
		}
		if ($convertToType)
		{
			$modifiers[] = $convertToType;
		}

		if (empty($modifiers))
		{
			$modifiers[] = \Bitrix\Bizproc\FieldType::STRING;
		}

		if ($this->getRealParameterValue($matches['object'], $matches['field'], $result, $modifiers, $decorator))
		{
			if (is_array($result))
			{
				$result = implode(", ", CBPHelper::MakeArrayFlat($result));
			}
		}
		else
		{
			$result = $matches[0];
		}

		return $result;
	}

	public function parseValue($value, $convertToType = null, ?callable $decorator = null)
	{
		[$t, $r] = $this->getPropertyValueRecursive($value, $convertToType, $decorator);

		return $r;
	}

	protected function getRawProperty($name)
	{
		if (isset($this->arProperties[$name]))
		{
			return $this->arProperties[$name];
		}
		else
		{
			$ro = $this->getRootActivity()->getReadOnlyData();
			if (isset($ro[$this->getName()]) && isset($ro[$this->getName()][$name]))
			{
				return $ro[$this->getName()][$name];
			}
		}

		return null;
	}

	public function __get($name)
	{
		$property = $this->getRawProperty($name);
		if ($property !== null)
		{
			[$t, $r] = $this->GetPropertyValueRecursive($property);
			return $r;
		}
		return null;
	}

	public function __isset($name)
	{
		return $this->isPropertyExists($name);
	}

	public function pullProperties(): array
	{
		$result = $this->arProperties;
		$this->arProperties = array_fill_keys(array_keys($this->arProperties), null);

		return [$this->getName() => $result];
	}

	public function __set($name, $val)
	{
		if (array_key_exists($name, $this->arProperties))
		{
			$this->arProperties[$name] = $val;
		}
	}

	public function isPropertyExists($name)
	{
		return array_key_exists($name, $this->arProperties);
	}

	public function collectNestedActivities()
	{
		return null;
	}

	public function collectUsages()
	{
		$usages = [];
		$this->collectUsagesRecursive($this->arProperties, $usages);
		return $usages;
	}

	public function collectPropertyUsages($propertyName): array
	{
		$usages = [];
		$this->collectUsagesRecursive($this->getRawProperty($propertyName), $usages);

		return $usages;
	}

	protected function collectUsagesRecursive($val, &$usages)
	{
		if (is_array($val))
		{
			foreach ($val as $v)
			{
				$this->collectUsagesRecursive($v, $usages);
			}
		}
		elseif (is_string($val))
		{
			$expressions = self::findExpressions($val);
			foreach ($expressions as $expression)
			{
				$usages[] = $this->getObjectSourceType($expression['object'], $expression['field']);
			}
		}
	}

	protected function getObjectSourceType($objectName, $fieldName)
	{
		return \Bitrix\Bizproc\Workflow\Template\SourceType::getObjectSourceType($objectName, $fieldName);
	}

	/************************  CONSTRUCTORS  *****************************************************/

	public function __construct($name)
	{
		$this->name = $name;
	}

	/************************  DEBUG  ***********************************************************/

	public function toString()
	{
		return $this->name.
			" [".get_class($this)."] (status=".
			CBPActivityExecutionStatus::Out($this->executionStatus).
			", result=".
			CBPActivityExecutionResult::Out($this->executionResult).
			", count(ClosedEvent)=".
			count($this->arStatusChangeHandlers[self::ClosedEvent]).
			")";
	}

	public function dump($level = 3)
	{
		$result = str_repeat("	", $level).$this->ToString()."\n";

		if (is_subclass_of($this, "CBPCompositeActivity"))
		{
			/** @var CBPActivity $activity */
			foreach ($this->arActivities as $activity)
				$result .= $activity->Dump($level + 1);
		}

		return $result;
	}

	/************************  PROCESS  ***********************************************************/

	public function initialize()
	{
	}

	public function finalize()
	{
	}

	public function executeWithPayload(ExecutionPayload $payload)
	{
		// compatible behavior
		return $this->execute($payload);
	}

	public function execute()
	{
		return CBPActivityExecutionStatus::Closed;
	}

	protected function reInitialize()
	{
		$this->executionStatus = CBPActivityExecutionStatus::Initialized;
		$this->executionResult = CBPActivityExecutionResult::None;
	}

	public function cancel()
	{
		return CBPActivityExecutionStatus::Closed;
	}

	public function handleFault(Exception $exception)
	{
		$status = $this->cancel();
		if ($status == CBPActivityExecutionStatus::Canceling)
		{
			return CBPActivityExecutionStatus::Faulting;
		}

		return $status;
	}

	/************************  LOAD / SAVE  *******************************************************/

	public function fixUpParentChildRelationship(CBPActivity $nestedActivity)
	{
		$nestedActivity->parent = $this;
	}

	public static function load($stream)
	{
		if ($stream == '')
		{
			throw new CBPArgumentNullException("stream");
		}

		return CBPRuntime::GetRuntime()->unserializeWorkflowStream($stream);
	}

	protected function getACNames()
	{
		return array(mb_substr(get_class($this), 3));
	}

	private static function searchUsedActivities(CBPActivity $activity, &$arUsedActivities)
	{
		$arT = $activity->GetACNames();
		foreach ($arT as $t)
		{
			if (!in_array($t, $arUsedActivities))
			{
				$arUsedActivities[] = $t;
			}
		}

		if ($arNestedActivities = $activity->CollectNestedActivities())
		{
			foreach ($arNestedActivities as $nestedActivity)
			{
				self::SearchUsedActivities($nestedActivity, $arUsedActivities);
			}
		}
	}

	public function save()
	{
		$usedActivities = [];
		self::SearchUsedActivities($this, $usedActivities);

		if ($children = $this->collectNestedActivities())
		{
			/** @var CBPActivity $child */
			foreach ($children as $child)
			{
				$child->unsetWorkflow();
			}
		}

		$strUsedActivities = implode(",", $usedActivities);
		return $strUsedActivities.";".serialize($this);
	}

	/************************  STATUS CHANGE HANDLERS  **********************************************/

	public function addStatusChangeHandler($event, $eventHandler)
	{
		if (!is_array($this->arStatusChangeHandlers))
		{
			$this->arStatusChangeHandlers = [];
		}

		if (!array_key_exists($event, $this->arStatusChangeHandlers))
		{
			$this->arStatusChangeHandlers[$event] = [];
		}

		$this->arStatusChangeHandlers[$event][] = $eventHandler;
	}

	public function removeStatusChangeHandler($event, $eventHandler)
	{
		if (!is_array($this->arStatusChangeHandlers))
		{
			$this->arStatusChangeHandlers = [];
		}

		if (!array_key_exists($event, $this->arStatusChangeHandlers))
		{
			$this->arStatusChangeHandlers[$event] = [];
		}

		$index = array_search($eventHandler, $this->arStatusChangeHandlers[$event], true);

		if ($index !== false)
		{
			unset($this->arStatusChangeHandlers[$event][$index]);
		}
	}

	/************************  EVENTS  **********************************************************************/

	private function fireStatusChangedEvents($event, $arEventParameters = array())
	{
		if (array_key_exists($event, $this->arStatusChangeHandlers) && is_array($this->arStatusChangeHandlers[$event]))
		{
			foreach ($this->arStatusChangeHandlers[$event] as $eventHandler)
				call_user_func_array(array($eventHandler, "OnEvent"), array($this, $arEventParameters));
		}
	}

	public function setStatus($newStatus, $arEventParameters = array())
	{
		$this->executionStatus = $newStatus;
		$this->fireStatusChangedEvents(self::StatusChangedEvent, $arEventParameters);

		switch ($newStatus)
		{
			case CBPActivityExecutionStatus::Executing:
				$this->fireStatusChangedEvents(self::ExecutingEvent, $arEventParameters);
				break;

			case CBPActivityExecutionStatus::Canceling:
				$this->fireStatusChangedEvents(self::CancelingEvent, $arEventParameters);
				break;

			case CBPActivityExecutionStatus::Closed:
				$this->fireStatusChangedEvents(self::ClosedEvent, $arEventParameters);
				break;

			case CBPActivityExecutionStatus::Faulting:
				$this->fireStatusChangedEvents(self::FaultingEvent, $arEventParameters);
				break;

			default:
				return;
		}
	}

	/************************  CREATE  *****************************************************************/

	public static function includeActivityFile($code)
	{
		return CBPRuntime::getRuntime()->includeActivityFile($code);
	}

	/**
	 * @param string $code
	 * @param string $name
	 * @return CBPActivity|null
	 * @throws CBPArgumentOutOfRangeException
	 */
	public static function createInstance($code, $name)
	{
		if (preg_match("#[^a-zA-Z0-9_]#", $code))
		{
			throw new CBPArgumentOutOfRangeException("Activity '" . $code . "' is not valid");
		}

		$classname = 'CBP' . $code;
		if (class_exists($classname))
		{
			return new $classname($name);
		}

		return null;
	}

	public static function callStaticMethod($code, $method, $arParameters = array())
	{
		$runtime = CBPRuntime::GetRuntime();
		if (!$runtime->IncludeActivityFile($code))
		{
			return [
				[
					"code" => "ActivityNotFound",
					"parameter" => $code,
					"message" => GetMessage("BPGA_ACTIVITY_NOT_FOUND_1", ['#ACTIVITY#' => htmlspecialcharsbx($code)]),
				],
			];
		}

		if (preg_match("#[^a-zA-Z0-9_]#", $code))
		{
			throw new CBPArgumentOutOfRangeException("Activity '".$code."' is not valid");
		}

		if (strpos($code, 'CBP') === 0)
		{
			$code = mb_substr($code, 3);
		}

		$classname = 'CBP'.$code;

		if (method_exists($classname,$method))
		{
			return call_user_func_array(array($classname, $method), $arParameters);
		}

		return false;
	}

	public static function createConfigurator(string $activityType = '', array $currentValues = []): \Bitrix\Bizproc\Public\Activity\Configurator
	{
		$configurator = new \Bitrix\Bizproc\Public\Activity\Configurator();
		if (empty($activityType))
		{
			return $configurator;
		}

		\CBPRuntime::getRuntime()->includeActivityFile($activityType);
		$className = 'CBP' . $activityType;

		if (!class_exists($className) || !isset(class_implements($className, false)[\IBPConfigurableActivity::class]))
		{
			return $configurator;
		}

		$configurator
			->setActivityType($activityType)
			->setPropertiesMap($className::getPropertiesMap([], ['Properties' => $currentValues]));

		return $configurator;
	}

	public function getConfigurator(): \Bitrix\Bizproc\Public\Activity\Configurator
	{
		return static::createConfigurator();
	}

	public function initializeFromArray($arParams)
	{
		if (is_array($arParams))
		{
			foreach ($arParams as $key => $value)
			{
				if (array_key_exists($key, $this->arProperties))
				{
					$this->arProperties[$key] = $value;
				}
			}
		}
	}

	/************************  MARK  ****************************************************************/

	public function markCanceled($arEventParameters = [])
	{
		if ($this->executionStatus != CBPActivityExecutionStatus::Closed)
		{
			if ($this->executionStatus != CBPActivityExecutionStatus::Canceling)
			{
				throw new CBPInvalidOperationException("InvalidCancelActivityState");
			}

			$this->executionResult = CBPActivityExecutionResult::Canceled;
			$this->markClosed($arEventParameters);
		}
	}

	public function markCompleted($arEventParameters = [])
	{
		$this->executionResult = CBPActivityExecutionResult::Succeeded;
		$this->markClosed($arEventParameters);
	}

	public function markFaulted($arEventParameters = [])
	{
		$this->executionResult = CBPActivityExecutionResult::Faulted;
		$this->markClosed($arEventParameters);
	}

	private function markClosed($arEventParameters = [])
	{
		switch ($this->executionStatus)
		{
			case CBPActivityExecutionStatus::Executing:
			case CBPActivityExecutionStatus::Canceling:
			case CBPActivityExecutionStatus::Faulting:
			{
				if ($this instanceof \CBPCompositeActivity)
				{
					foreach ($this->arActivities as $activity)
					{
						if (
							($activity->executionStatus != CBPActivityExecutionStatus::Initialized)
							&& ($activity->executionStatus != CBPActivityExecutionStatus::Closed)
						)
						{
							throw new CBPInvalidOperationException('ActiveChildExist');
						}
					}
				}

				if ($this->isActivated())
				{
					/** @var CBPTrackingService $trackingService */
					$trackingService = $this->workflow->getService('TrackingService');
					$trackingService->write(
						$this->getWorkflowInstanceId(),
						CBPTrackingType::CloseActivity,
						$this->getName(),
						$this->executionStatus,
						$this->executionResult,
						$this->getTitle()
					);
				}
				$this->setStatus(CBPActivityExecutionStatus::Closed, $arEventParameters);

				return;
			}
		}

		throw new CBPInvalidOperationException('InvalidCloseActivityState');
	}

	protected function writeToTrackingService($message = "", $modifiedBy = 0, $trackingType = -1)
	{
		/** @var CBPTrackingService $trackingService */
		$trackingService = $this->workflow->GetService("TrackingService");
		if ($trackingType < 0)
			$trackingType = CBPTrackingType::Custom;
		$trackingService->Write($this->GetWorkflowInstanceId(), $trackingType, $this->name, $this->executionStatus, $this->executionResult, ($this->IsPropertyExists("Title") ? $this->Title : ""), $message, $modifiedBy);
	}

	protected function fixResult(Bitrix\Bizproc\Result\ResultDto $result): void
	{
		$workflowId = $this->getWorkflowInstanceId();
		try
		{
			Bizproc\Result\Entity\ResultTable::upsert([
				'WORKFLOW_ID' => $workflowId,
				'PRIORITY' => $this->resultPriority,
				'ACTIVITY' => $result->activity,
				'RESULT' => $result->data,
			]);
		}
		catch (Throwable $e)
		{
			$this->trackError($e->getMessage());
		}
	}

	public static function renderResult(array $result, string $workflowId, int $userId): RenderedResult
	{
		if (!self::checkResultViewRights($result, $workflowId, $userId))
		{

			return RenderedResult::makeNoRights();
		}

		$documentService = CBPRuntime::getRuntime()->getDocumentService();

		if (isset($result['DOCUMENT_ID']))
		{
			$url = $documentService->getDocumentDetailUrl($result['DOCUMENT_ID']);
			$name = $documentService->getDocumentName($result['DOCUMENT_ID']);
			if (isset($result['DOCUMENT_TYPE']))
			{
				$type = (string)$documentService->getDocumentTypeCaption($result['DOCUMENT_TYPE']);
				$name = $type . ': ' . $name;
			}

			return new RenderedResult('[URL=' . $url . ']' . $name . '[/URL]', RenderedResult::BB_CODE_RESULT);
		}

		return RenderedResult::makeNoResult();
	}

	protected static function checkResultViewRights(array $result, string $workflowId, int $userId): bool
	{
		$currentUser = new \CBPWorkflowTemplateUser($userId);
		$userCanReadDocument = false;

		if (isset($result['DOCUMENT_ID']))
		{
			$userCanReadDocument = \CBPDocument::canUserOperateDocument(
				\CBPCanUserOperateOperation::ReadDocument,
				$currentUser->getId(),
				$result['DOCUMENT_ID'],
			);
		}

		return
			$currentUser->isAdmin()
			|| self::checkUserAccessWithSubordination($currentUser->getId(), $result['USERS'] ?? [])
			|| $userCanReadDocument;
	}

	protected static function checkUserAccessWithSubordination(int $userId, array $users): bool
	{
		if (in_array($userId, $users, true))
		{
			return true;
		}
		foreach ($users as $user)
		{
			if (\CBPHelper::checkUserSubordination($userId, $user))
			{
				return true;
			}
		}

		return false;
	}

	protected function trackError(string $errorMsg)
	{
		$this->writeToTrackingService($errorMsg, 0, \CBPTrackingType::Error);
	}

	protected function getDebugInfo(array $values = [], array $map = []): array
	{
		if (count($map) <= 0)
		{
			$map = static::getPropertiesMap($this->getDocumentType());
		}

		foreach ($map as $key => &$property)
		{
			if (is_string($property))
			{
				$property = [
					'Name' => $property,
					'Type' => 'string',
				];
			}

			if (!array_key_exists('TrackType', $property))
			{
				$property['TrackType'] = CBPTrackingType::Debug;
			}

			if (array_key_exists('TrackValue', $property))
			{
				continue;
			}

			if (!array_key_exists($key, $values))
			{
				$property['TrackValue'] = $this->__get($key);

				continue;
			}

			$property['TrackValue'] = $values[$key];
		}

		return $map;
	}

	protected function writeDebugInfo(array $map)
	{
		if (!$this->workflow->isDebug())
		{
			return;
		}

		/** @var CBPDocumentService $documentService */
		$documentService = $this->workflow->GetService("DocumentService");

		foreach ($map as $property)
		{
			if (is_string($property))
			{
				$property = [
					'Name' => $property,
					'Type' => 'string',
				];
			}

			$fieldType = $documentService->getFieldTypeObject($this->getDocumentType(), $property);
			if (!$fieldType)
			{
				if (!array_key_exists('BaseType', $property))
				{
					continue;
				}
				$property['Type'] = $property['BaseType'];
				$fieldType = $documentService->getFieldTypeObject($this->getDocumentType(), $property);

				if (!$fieldType)
				{
					continue;
				}
			}

			$value = $fieldType->formatValue($property['TrackValue']);
			$value = ($value !== '') ? $value : '[]';

			$this->writeDebugTrack(
				$this->getWorkflowInstanceId(),
				$this->getName(),
				$this->executionStatus,
				$this->executionResult,
				$this->getTitle(),
				$this->preparePropertyForWritingToTrack($value, $property['Name'] ?? ''),
				$property['TrackType'] ?? \CBPTrackingType::Debug
			);
		}
	}

	public function getTitle(): string
	{
		$activityTitle = $this->isPropertyExists('Title') ? $this->Title : '';

		if (is_string($activityTitle))
		{
			return $activityTitle;
		}

		return '';
	}

	public function setActivated(bool $activated): void
	{
		$this->activated = $activated;
	}

	public function isActivated(): bool
	{
		return $this->activated;
	}

	public function setDocumentContext(string $contextExpression): void
	{
		$this->documentContext = $contextExpression;
	}

	public function getOutputPortId(): int
	{
		return $this->outputPortId;
	}

	public static function validateProperties($arTestProperties = array(), CBPWorkflowTemplateUser $user = null)
	{
		return array();
	}

	public static function validateChild($childActivity, $bFirstChild = false)
	{
		return array();
	}

	public static function &findActivityInTemplate(&$arWorkflowTemplate, $activityName)
	{
		return CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
	}

	public static function isExpression($text)
	{
		if (is_string($text))
		{
			$text = trim($text);
			if (
				preg_match(static::CalcPattern, $text)
				|| preg_match(static::ValuePattern, $text)
				|| preg_match(self::ValueSimplePattern, $text)
			)
			{
				return true;
			}
		}

		return false;
	}

	public static function parseExpression($exp): ?array
	{
		$matches = null;
		if (is_string($exp) && preg_match(static::ValuePattern, $exp, $matches))
		{
			return self::buildExpressionResult((array)$matches);
		}
		return null;
	}

	public static function findExpressions(mixed $exp): array
	{
		$expressions = [];
		$matches = null;

		$pattern = '/' . self::ValueSinglePattern . '/i';
		if (is_string($exp) && preg_match_all($pattern, $exp, $matches, PREG_SET_ORDER))
		{
			foreach ($matches as $match)
			{
				$result = self::buildExpressionResult($match);

				$expressions[] = $result;
			}
		}

		return $expressions;
	}

	protected static function buildExpressionResult(array $matches): array
	{
		$result = [
			'object' => $matches['object'] ?? '',
			'field' => $matches['field'] ?? '',
			'modifiers' => [],
		];

		if (!empty($matches['mod1']))
		{
			$result['modifiers'][] = $matches['mod1'];
		}
		if (!empty($matches['mod2']))
		{
			$result['modifiers'][] = $matches['mod2'];
		}

		return $result;
	}

	protected function getStorage(): Bizproc\Storage\ActivityStorage
	{
		return $this->getStorageFactory()->getActivityStorage($this);
	}

	private function getStorageFactory(): Bizproc\Storage\Factory
	{
		return Bizproc\Storage\Factory::getInstance();
	}
}
