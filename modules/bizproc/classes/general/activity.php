<?php

use Bitrix\Bizproc;
use Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload;
use Bitrix\Bizproc\Result\RenderedResult;
use Bitrix\Main;

abstract class CBPActivity
{
	use Bizproc\Debugger\Mixins\WriterDebugTrack;
	use Bizproc\Runtime\Mixins\ActivityRuntimePropertyGetter;

	/**
	 * The one traversal of the children, shared with the flow-directed activities: an activity served by the
	 * unified settings panel runs its children through the very same queue, only from another point
	 * ({@see self::executeWithPayload()}).
	 *
	 * The callback of the traversal is aliased instead of being applied under its own name: activities
	 * shipped long before the mixin declare `OnEvent` as `protected` and one-argument
	 * ({@see CBPIfElseActivity}, {@see CBPApproveActivity}), so a public two-argument declaration inherited
	 * from here would make every one of them an incompatible override. The base class declares that legacy
	 * shape itself ({@see self::OnEvent()}) and routes it into the traversal.
	 */
	use Bizproc\Public\Activity\Mixins\ChildFlowTraversal
	{
		onEvent as protected runChildFlowClosedEvent;
	}

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

	/** Positions of the arguments of a settings form {@see self::savePropertiesDialogValues()} reads itself. */
	private const DialogValuesActivityNameArgument = 1;
	private const DialogValuesTemplateArgument = 2;

	/**
	 * Property naming the filter of the host node an action reads its document from
	 * ({@see Bizproc\Activity\Mixins\TargetDocumentResolverTrait}).
	 */
	private const TargetFilterIdProperty = 'TargetFilterId';

	protected array $arProperties = [];
	protected array $arPropertiesTypes = [];

	/**
	 * Constants that came with the scheme of the instance ({@see \CBPActivity::setConstantsTypes()}); null
	 * for an instance reading the constants of the published template, empty for a scheme having none.
	 */
	protected ?array $arConstantsTypes = null;

	/** Meaningful on the root activity alone {@see self::getTestRunContext()}. */
	private ?Bizproc\Internal\Entity\Workflow\TestRunContext $testRunContext = null;

	protected string $name = '';
	protected int $outputPortId = 0;
	protected bool $activated = true;
	/** @var CBPWorkflow | \Bitrix\Bizproc\Debugger\Workflow\DebugWorkflow $workflow */
	public $workflow = null;

	public array $arEventsMap = [];

	protected int $resultPriority = 0;

	protected ?string $documentContext;

	/**
	 * Document the context expression named, resolved once. Both {@see self::getDocumentId()} and
	 * {@see self::getDocumentType()} lie on the hot path of every execution, and resolving parses the
	 * expression anew every time.
	 *
	 * The snapshot is valid for one binding and one execution only, so it is dropped in
	 * {@see self::setDocumentId()} - the invariant the mixin of the target document keeps as well - and in
	 * {@see self::reInitialize()}, since the value a loop reads may differ from pass to pass.
	 */
	private ?array $resolvedDocumentContextId = null;
	private bool $isDocumentContextResolved = false;

	/**
	 * Children of the activity. Only an activity owning a container ({@see self::$childContainerEnabled})
	 * ever gets one: for every other activity the array stays the empty one it starts as.
	 *
	 * @var CBPActivity[]
	 */
	protected $arActivities = [];

	/**
	 * Whether this activity owns a container of children, and with it the whole lifecycle of those children -
	 * binding to the workflow, initialization, finalization and the merging of their properties.
	 *
	 * A composite activity owns one by definition; an ordinary activity owns one only when the unified
	 * settings panel serves it ({@see self::completeWithUnifiedPanelProperties()}), which is the single
	 * verdict of the descriptor and is read once per instance. Everything on the common path of an activity
	 * without a container costs exactly this one flag.
	 */
	protected bool $childContainerEnabled = false;

	/**
	 * Whether the flow of the children is started by this activity itself once its own logic has closed
	 * ({@see self::executeWithPayload()}).
	 *
	 * True for an ordinary node served by the unified settings panel and only for it. A composite activity
	 * owns children too, but it runs them from its own execution
	 * ({@see \Bitrix\Bizproc\Public\Activity\Structure\FlowDirectedActivity::execute()}) - starting them a
	 * second time is what this second verdict keeps apart from the first one.
	 */
	protected bool $childFlowAfterNativeLogic = false;

	/**
	 * Whether the engine skipped this node instead of running it (a false condition; set alongside the
	 * SkipActivity record, {@see CBPWorkflow::writeActivitySkipTracking()}). The skip record stands in
	 * for the whole ExecuteActivity/CloseActivity pair of the node, so closing a skipped node must not
	 * journal a CloseActivity: the log consumers read that record as "ran and completed" and it breaks
	 * the level pairing of the dump.
	 */
	protected bool $skippedByEngine = false;

	/**
	 * Input port the current execution arrived at, remembered for the addressing of the chain of children
	 * ({@see self::getStartActivityNames()}). Written only by an activity that runs children of its own.
	 *
	 * Null until this execution of the node reaches its own logic, and that is what the second point of the
	 * two phases reads it for ({@see self::startChildFlowOnce()}): a node the engine did not run at all
	 * - a false condition, a deactivated node - is closed without ever being executed and must skip the chain
	 * of its children together with its logic.
	 */
	protected ?int $childFlowInputPort = null;

	/**
	 * Service properties of the activities served by the unified settings panel, by lower-cased activity
	 * code; an activity served by no such panel is remembered by an empty map. Memoized because the
	 * completion below lies on the creation path of every activity of every process.
	 *
	 * @var array<string, array<string, array>>
	 */
	private static array $unifiedPanelServiceProperties = [];

	/**
	 * Defaults the panel really wrote into an instance, by lower-cased activity code. A property the class of
	 * the node declares itself is absent here: the panel leaves such a property, and its default, to the class
	 * ({@see self::completeWithUnifiedPanelProperties()}).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $unifiedPanelDefaultsApplied = [];

	/**
	 * Names of the service properties of the unified settings panel, as a set: the same for every activity, so
	 * the one read of them serves the whole request ({@see self::getUnifiedPanelServicePropertyNames()}).
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $unifiedPanelServicePropertyNames = null;

	/************************  PROPERTIES  ************************************************/

	/**
	 * @return array
	 */
	public function getDocumentId()
	{
		if (isset($this->documentContext))
		{
			$documentId = $this->resolveDocumentContextId();
			if ($documentId !== null)
			{
				return $documentId;
			}

			// A node wired to a chain of filters is not judged by its context anywhere
			// ({@see self::isBoundToFilterChain()}), so it must not be answered by it either: its target is
			// the selection of the chain, and the document of the process is what it fell back to before all
			// this. An empty answer there would reach the module resolver as a document id of its own.
			$rootActivity = $this->getRootActivity();
			if ($this->isBoundToFilterChain() && $rootActivity !== $this)
			{
				return $rootActivity->getDocumentId();
			}

			// An unresolved context is not the document of the process: answering with that document is
			// what let a node act over the wrong one. The engine skips such a node outright.
			return [];
		}

		return $this->getRootActivity()->getDocumentId();
	}

	/**
	 * Whether the node carries a document context of its own that names no document. Read by the engine
	 * before the node runs ({@see CBPWorkflow::runExecuteActivityOperation()}).
	 *
	 * A node wired to a chain of filters is never judged by its context. The designer writes both onto such
	 * a node - `TargetFilterId` and a context reading `{=<filterId>:Document}` - and that context names no
	 * activity at run time: a filter publishes its selection as a property of the host node
	 * ({@see Bizproc\Public\Activity\Mixins\NodeFilterResultProperties::initializeFilterResultPropertiesOf()}),
	 * and the target of the action is resolved from `TargetFilterId` by the resolver of the document module.
	 * Judging those nodes here would stop every template built on a filter chain.
	 */
	public function hasUnresolvedDocumentContext(): bool
	{
		if (!isset($this->documentContext) || $this->isBoundToFilterChain())
		{
			return false;
		}

		return $this->resolveDocumentContextId() === null;
	}

	/**
	 * Whether the node takes its target from a chain of filters rather than from the document its context
	 * names. The property alone does not say so: the designer stamps it whenever the id of a filter of the
	 * node matches either half of the expression in the document field
	 * ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand::resolveTargetFilterIdForAction()}),
	 * so an expression on a variable of that same name is labelled too. Read as a chain, such a node would
	 * lose the skip its unresolved value has to earn it.
	 *
	 * A chain is named in one of two ways and both are kept: `{=<filterId>:Document}`, the filter as an
	 * object of its own, and `{=<hostId>:<filterId>}`, the selection as a property of the host node
	 * ({@see Bizproc\Public\Activity\Mixins\NodeFilterResultProperties::initializeFilterResultPropertiesOf()}).
	 * Whether the object of an expression is a node or a source of values of the process is answered by the
	 * classifier of the engine, so the expression reads the same way here as it does everywhere else.
	 */
	private function isBoundToFilterChain(): bool
	{
		$filterId = trim((string)$this->getRawProperty(self::TargetFilterIdProperty));
		if ($filterId === '')
		{
			return false;
		}

		if (!isset($this->documentContext))
		{
			return true;
		}

		$parsed = static::parseExpression($this->documentContext);
		if ($parsed === null)
		{
			// An id written by hand names no filter, whatever the node is labelled with.
			return false;
		}

		$object = (string)($parsed['object'] ?? '');
		$field = (string)($parsed['field'] ?? '');

		if ($object === $filterId)
		{
			return true;
		}

		$source = Bizproc\Workflow\Template\SourceType::getObjectSourceType($object, $field);

		return $field === $filterId
			&& ($source[0] ?? null) === Bizproc\Workflow\Template\SourceType::Activity
		;
	}

	private function resolveDocumentContextId(): ?array
	{
		if (!$this->isDocumentContextResolved)
		{
			$this->resolvedDocumentContextId = Bizproc\Internal\Service\Container::instance()
				->getDocumentContextResolver()
				->resolveDocumentId($this, (string)$this->documentContext)
			;
			$this->isDocumentContextResolved = true;
		}

		return $this->resolvedDocumentContextId;
	}

	private function forgetResolvedDocumentContext(): void
	{
		$this->resolvedDocumentContextId = null;
		$this->isDocumentContextResolved = false;
	}

	/**
	 * @param array $documentId
	 * @return void
	 */
	public function setDocumentId($documentId)
	{
		$this->forgetResolvedDocumentContext();

		$this->getRootActivity()->setDocumentId($documentId);
	}

	/**
	 * @return array
	 */
	public function getDocumentType()
	{
		if (isset($this->documentContext))
		{
			$documentId = $this->resolveDocumentContextId();
			if ($documentId !== null)
			{
				$documentType = $this->workflow->getService('DocumentService')->getDocumentType($documentId);
				if (is_array($documentType))
				{
					return $documentType;
				}
			}

			// The context names no document, so there is nothing to read a type off. The type the node acts
			// in is still known, and asking the document service for one would throw on the invalid id.
			return Bizproc\Internal\Service\Container::instance()
				->getDocumentContextResolver()
				->resolveEffectiveDocumentType($this) ?? []
			;
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

	/**
	 * Setting names an external AI agent passes through verbatim (persisted but not type-mappable).
	 * The auto-describer merges them into `skippedSettings`, so reverse validation accepts them by name
	 * while their values bypass type coercion. Activities override this to declare such names.
	 *
	 * A name declared here and nowhere else stands for the free map category
	 * ({@see \Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory::EditableFreeMap}).
	 *
	 * @param array $documentType ['module', 'entityType', 'documentType'].
	 * @return array<int, string>
	 */
	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		return [];
	}

	/**
	 * Subset of {@see getAiPassthroughSettingNames()} whose values an external AI agent may only carry through
	 * verbatim from the published template: the payload is executable, so authoring it requires rights the agent
	 * has no way to hold. Reverse validation compares such a value against the published baseline and refuses
	 * anything else - the passthrough contract alone would accept an arbitrary payload as is.
	 *
	 * These names are the carried category
	 * ({@see \Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory::Carried}) and are published as the
	 * block's `carriedSettings`.
	 *
	 * @param array $documentType ['module', 'entityType', 'documentType'].
	 * @return array<int, string>
	 */
	public static function getAiRestrictedSettingNames(array $documentType): array
	{
		return [];
	}

	/**
	 * Setting values a non-dialog AI agent path must apply on its own, because the activity's properties
	 * dialog writes them on every save and the agent never opens that dialog. Declared as name => value pairs
	 * so the caller applies them without knowing what the field means. Activities override this to declare
	 * such pairs.
	 *
	 * Every key here is of the enforced category
	 * ({@see \Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory::Enforced}): the server writes the
	 * value whatever the agent sent, so the name is never published as an editable setting.
	 *
	 * @param array $documentType ['module', 'entityType', 'documentType'].
	 * @return array<string, string>
	 */
	public static function getAiEnforcedSettingValues(array $documentType): array
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

	/**
	 * Returns type descriptors of the activity own properties: ['PropertyName' => ['Type' => ...], ...].
	 *
	 * @return array
	 */
	public function getPropertiesTypes(): array
	{
		return $this->arPropertiesTypes;
	}

	/**
	 * Returns the activity's explicit "modified by" value, or — when it is empty —
	 * the user who started the workflow (getStartedBy). The starter is persisted
	 * with the workflow instance, so it survives pauses, timers and async resume.
	 *
	 * Document-modifying activities use this instead of a bare $this->ModifiedBy so
	 * that automated changes (e.g. scheduled-trigger workflows) are attributed to a
	 * deliberate user instead of falling back to the default system user (admin).
	 *
	 * @return mixed Same shape as ModifiedBy (a "user_<id>" string), or its empty value.
	 */
	public function getModifiedByOrStarter(): mixed
	{
		$modifiedBy = $this->ModifiedBy;
		if (!CBPHelper::isEmptyValue($modifiedBy))
		{
			return $modifiedBy;
		}

		$startedBy = $this->workflow ? $this->workflow->getStartedBy() : null;

		return $startedBy ? ('user_' . $startedBy) : $modifiedBy;
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

	/**
	 * Returns type descriptors of the workflow variables: ['VariableName' => ['Type' => ...], ...].
	 *
	 * @return array
	 */
	public function getVariablesTypes(): array
	{
		$variablesTypes = $this->getRootActivity()->arVariablesTypes ?? [];

		return is_array($variablesTypes) ? $variablesTypes : [];
	}

	public function setConstantsTypes(array $constantsTypes): void
	{
		$this->getRootActivity()->arConstantsTypes = $constantsTypes;
	}

	private function getConstantTypes()
	{
		$rootActivity = $this->GetRootActivity();
		if ($rootActivity->arConstantsTypes !== null)
		{
			return $rootActivity->arConstantsTypes;
		}

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

	/** {@see Bizproc\Internal\Entity\Workflow\TestRunContext} */
	public function getTestRunContext(): ?Bizproc\Internal\Entity\Workflow\TestRunContext
	{
		return $this->getRootActivity()->testRunContext;
	}

	public function setTestRunContext(?Bizproc\Internal\Entity\Workflow\TestRunContext $context): void
	{
		$this->getRootActivity()->testRunContext = $context;
	}

	/**
	 * Of a root activity read from the saved state before its workflow is restored: the workflow it keeps
	 * is an empty shell until then, so the root is not looked up through it.
	 */
	public function getOwnTestRunContext(): ?Bizproc\Internal\Entity\Workflow\TestRunContext
	{
		return $this->testRunContext;
	}

	public function setWorkflow(CBPWorkflow $workflow)
	{
		$this->workflow = $workflow;

		if (!$this->childContainerEnabled)
		{
			return;
		}

		$debugSessionService = $workflow->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('setWorkflow'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_SET_WF', ['#NAME#' => $this->getName()]),
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		foreach ($this->arActivities as $activity)
		{
			if (!method_exists($activity, 'setWorkflow'))
			{
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Error,
					$this->getChildContainerTraceKey('setWorkflow'),
					self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_SET_WF_NO_METHOD'),
					[
						'parent_activity' => $this->getName(),
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
					],
				);

				throw new Exception('ActivitySetWorkflow');
			}
			$activity->setWorkflow($workflow);

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				$this->getChildContainerTraceKey('setWorkflow'),
				self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_SET_WF_CHILD_DONE'),
				[
					'child_activity' => $activity->getName(),
					'child_type' => $activity->getType(),
				],
			);
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('setWorkflow'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_SET_WF_DONE'),
			[
				'parent_activity' => $this->getName(),
				'processed_count' => count($this->arActivities),
			],
		);
	}

	public function unsetWorkflow()
	{
		if (!$this->childContainerEnabled)
		{
			$this->workflow = null;

			return;
		}

		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('unsetWorkflow'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_UNSET_WF', ['#NAME#' => $this->getName()]),
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		$this->workflow = null;

		foreach ($this->arActivities as $activity)
		{
			if (method_exists($activity, 'SetWorkflow'))
			{
				$activity->unsetWorkflow();

				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					$this->getChildContainerTraceKey('unsetWorkflow'),
					self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_UNSET_WF_CHILD_DONE'),
					[
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
					],
				);
			}
		}
	}

	/**
	 * The key names the class the container belongs to, so a complex node keeps tracing under the very name
	 * it traced under before the mechanics became common to both kinds of container owner.
	 */
	protected function getChildContainerTraceKey(string $method): string
	{
		return 'CBPActivity::' . $method;
	}

	/**
	 * The debug phrases of the child lifecycle keep living next to the composite activity: the mechanics moved
	 * here, the translations of every locale did not. Reached only inside a debug session - a nullsafe call
	 * evaluates no argument when there is no debug service.
	 */
	protected static function getChildContainerTraceMessage(string $phraseId, array $replace = []): string
	{
		Main\Localization\Loc::loadMessages(__DIR__ . '/compositeactivity.php');

		return Main\Localization\Loc::getMessage($phraseId, $replace) ?? '';
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

	/**
	 * Value of a property as the template carries it, with no expression substitution. Service properties of
	 * a node are read this way: a condition and a filter map are payloads their own consumers parse, with
	 * the type of the field at hand, so parsing them here would corrupt them.
	 *
	 * @internal engine only, not part of the public activity contract.
	 */
	public function getRawPropertyValue($name)
	{
		return $this->getRawProperty($name);
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
		// The flag is read once and first, like in every other cascade: service properties of the panel
		// and children of the node appear together ({@see self::completeWithUnifiedPanelProperties()}),
		// so an activity outside the panel pulls its properties exactly as it did before.
		if (!$this->childContainerEnabled)
		{
			return [$this->getName() => $this->pullOwnProperties()];
		}

		$result = [
			$this->getName() => $this->pullOwnProperties($this->collectUntouchedUnifiedPanelProperties()),
		];

		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('pullProperties'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_PULL_PROPS', ['#NAME#' => $this->getName()]),
			[
				'activity_name' => $this->getName(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		foreach ($this->arActivities as $activity)
		{
			foreach ($activity->pullProperties() as $activityId => $props)
			{
				$result[$activityId] = $props;

				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					$this->getChildContainerTraceKey('pullProperties'),
					self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_PULL_PROPS_CHILD'),
					[
						'child_activity' => $activity->getName(),
						'activity_id' => $activityId,
						'properties_count' => count($props),
					],
				);
			}
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('pullProperties'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_PULL_PROPS_DONE'),
			[
				'parent_activity' => $this->getName(),
				'total_activities' => count($result),
			],
		);

		return $result;
	}

	/**
	 * Properties of the activity itself, taken off the instance and handed to the caller.
	 *
	 * @param array<string, mixed> $keptProperties Properties left on the instance,
	 *     {@see self::collectUntouchedUnifiedPanelProperties()}.
	 * @return array<string, mixed>
	 */
	private function pullOwnProperties(array $keptProperties = []): array
	{
		$pulledProperties = array_diff_key($this->arProperties, $keptProperties);
		foreach (array_keys($pulledProperties) as $propertyName)
		{
			$this->arProperties[$propertyName] = null;
		}

		return $pulledProperties;
	}

	/**
	 * Service properties of the unified settings panel the instance was completed with
	 * ({@see self::completeWithUnifiedPanelProperties()}) and nothing has written to since. Such a property
	 * is no data of the process - the panel saved nothing into it - so it is left on the instance instead of
	 * being hoisted into the read-only storage of the root: the bag {@see self::pullProperties()} returns is
	 * read by consumers as "the properties this node carries", and a declared default is not one of them.
	 *
	 * A property carrying a configured value is pulled the usual way, so the round trip of a condition, of
	 * the rules and of the graph of the children stays exactly as it was.
	 *
	 * Read against the default the instance was really initialized with ({@see self::$unifiedPanelDefaultsApplied})
	 * instead of the one the descriptor declares: a node declaring the property itself keeps its own default,
	 * and the two need not be the same value. The comparison is strict, so a value merely equal to the default
	 * - a string where the default is an int, an array rebuilt in another order - counts as written.
	 *
	 * @return array<string, mixed> property name => value, in the shape `arProperties` carries
	 */
	private function collectUntouchedUnifiedPanelProperties(): array
	{
		$untouched = [];
		$appliedDefaults = self::$unifiedPanelDefaultsApplied[mb_strtolower($this->getType())] ?? [];

		foreach ($appliedDefaults as $propertyName => $default)
		{
			if (
				array_key_exists($propertyName, $this->arProperties)
				&& $this->arProperties[$propertyName] === $default
			)
			{
				$untouched[$propertyName] = $this->arProperties[$propertyName];
			}
		}

		return $untouched;
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

	/**
	 * @return CBPActivity[]|null The children of the activity, null for an activity owning no container:
	 *     the answer every consumer walking the tree of a process reads.
	 */
	public function collectNestedActivities()
	{
		return $this->childContainerEnabled ? $this->arActivities : null;
	}

	public function walkRecursive(): iterable
	{
		yield $this;

		$children = $this->collectNestedActivities();
		if (is_array($children))
		{
			foreach ($children as $child)
			{
				foreach ($child->walkRecursive() as $descendant)
				{
					yield $descendant;
				}
			}
		}
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

		/** @var CBPActivity $activity */
		foreach ($this->arActivities as $activity)
			$result .= $activity->Dump($level + 1);

		return $result;
	}

	/************************  PROCESS  ***********************************************************/

	public function initialize()
	{
		if (!$this->childContainerEnabled)
		{
			return;
		}

		$debugSessionService = $this->workflow->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('initialize'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_INIT', ['#NAME#' => $this->getName()]),
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'workflow_instance_id' => $this->getWorkflowInstanceId(),
				'workflow_status_id' => $this->getWorkflowStatus(),
				'workflow_template_id' => $this->getWorkflowTemplateId(),
				'nested_activities_count' => count($this->arActivities),
				'properties' => $this->arProperties,
				'property_types' => $this->arPropertiesTypes,
				'read_only_data' => $this->getChildContainerTraceReadOnlyData(),
			],
		);

		try
		{
			foreach ($this->arActivities as $activity)
			{
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					$this->getChildContainerTraceKey('initialize'),
					self::getChildContainerTraceMessage(
						'BPCGCA_DEBUG_TRACE_INIT_CHILD',
						['#TITLE#' => $activity->getTitle()],
					),
					[
						'parent_activity' => $this->getName(),
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
						'child_title' => $activity->getTitle(),
					],
				);

				$this->workflow->initializeActivity($activity);

				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					$this->getChildContainerTraceKey('initialize'),
					self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_INIT_CHILD_DONE'),
					[
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
						'workflow_instance_id' => $activity->getWorkflowInstanceId(),
						'workflow_status_id' => $activity->getWorkflowStatus(),
					],
				);
			}

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				$this->getChildContainerTraceKey('initialize'),
				self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_INIT_DONE'),
				[
					'parent_activity' => $this->getName(),
					'initialized_count' => count($this->arActivities),
				],
			);
		}
		catch (Throwable $exception)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Error,
				$this->getChildContainerTraceKey('initialize') . '::exception',
				self::getChildContainerTraceMessage(
					'BPCGCA_DEBUG_TRACE_INIT_ERROR',
					['#MESSAGE#' => $exception->getMessage()],
				),
				[
					'parent_activity' => $this->getName(),
					'exception_class' => $exception::class,
					'exception_message' => $exception->getMessage(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine(),
					'code' => $exception->getCode(),
					'trace' => $exception->getTraceAsString(),
				],
			);

			throw $exception;
		}
	}

	/** Read-only data belongs to the composite activity alone; an ordinary container owner carries none. */
	protected function getChildContainerTraceReadOnlyData(): array
	{
		return [];
	}

	public function finalize()
	{
		if (!$this->childContainerEnabled)
		{
			return;
		}

		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('finalize'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_FINALIZE', ['#NAME#' => $this->getName()]),
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		foreach ($this->arActivities as $activity)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				$this->getChildContainerTraceKey('finalize'),
				self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_FINALIZE_CHILD'),
				[
					'parent_activity' => $this->getName(),
					'child_activity' => $activity->getName(),
					'child_type' => $activity->getType(),
				],
			);

			$this->workflow->finalizeActivity($activity);
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('finalize'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_FINALIZE_DONE'),
			[
				'parent_activity' => $this->getName(),
				'finalized_count' => count($this->arActivities),
			],
		);
	}

	/**
	 * The two phases of a node served by the unified settings panel: its own logic first, the flow of its
	 * children after it. A node that closed and has a chain of children stays open instead and closes once
	 * the queue of that chain has drained ({@see self::close()}); every other status is returned untouched,
	 * so an asynchronous node goes on waiting exactly as it did.
	 *
	 * Everything an activity owning no such flow pays for this is the one flag read below. A false condition
	 * of the node never reaches here at all: it is answered before the activity is executed
	 * ({@see CBPWorkflow::runExecuteActivityOperation()}), so it skips the children together with the logic
	 * of the node.
	 *
	 * A node overriding this method either runs no children - a node of type `operators`
	 * ({@see CBPStateNode}, {@see CBPMergeFlowNode}, {@see CBPForEachActivity}), and the unified panel
	 * serves none of those
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider}) - or starts the
	 * chain itself through {@see self::startChildFlowOnce()}, which is what a trigger of a test run does
	 * ({@see \Bitrix\Bizproc\Activity\BaseTrigger::executeWithPayload()}).
	 */
	public function executeWithPayload(ExecutionPayload $payload)
	{
		if (!$this->childFlowAfterNativeLogic)
		{
			// compatible behavior
			return $this->execute($payload);
		}

		// The port the node was reached through addresses its chain, and a node that closes later reads it
		// long after this payload is gone ({@see self::markCompleted()}).
		$this->childFlowInputPort = $payload->getInputPort();

		$status = $this->execute($payload);
		if ($status !== CBPActivityExecutionStatus::Closed)
		{
			return $status;
		}

		return $this->startChildFlowOnce() ? CBPActivityExecutionStatus::Executing : $status;
	}

	/**
	 * The one point the chain of children of a node is started from, whichever of the two phases reaches it:
	 * the logic of the node closing right away ({@see self::executeWithPayload()}) or a node that closes
	 * later marking itself completed ({@see self::markCompleted()}).
	 *
	 * Three things have to hold, and the first of them is all an ordinary activity ever reads:
	 * - the node runs children at all;
	 * - this execution really reached the logic of the node - a node the engine skipped over a false
	 *   condition or a deactivated node never did, and skips the chain together with the logic;
	 * - the chain has not run yet. The drained queue closes the node through the very same marking, and that
	 *   closing must not start the chain all over again.
	 *
	 * The flag of the last one belongs to the traversal and is set by its own entry point, so that a node
	 * whose whole execution is the flow of its children marks itself as well: such a node carries the same
	 * verdict of the descriptor and would otherwise be restarted from its closing.
	 *
	 * @return bool Whether the node now waits for children instead of closing.
	 */
	protected function startChildFlowOnce(): bool
	{
		return $this->childFlowAfterNativeLogic
			&& $this->childFlowInputPort !== null
			&& !$this->childFlowStarted
			&& $this->startChildFlow()
		;
	}

	public function execute()
	{
		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * Start of the chain of children of a node served by the unified settings panel, addressed the way the
	 * template carries it: a node with an input port names its chain by the number of that port, exactly as
	 * a complex node does, and a portless node - a trigger - keeps its single chain under the reserved key
	 * of the portless rules container. A node with no chain names nothing, which is what "no children" is.
	 *
	 * A single entry may carry several starts, one per rule of the container, in the shape the converter of
	 * the rules writes them.
	 *
	 * @return list<string>
	 */
	protected function getStartActivityNames(): array
	{
		$inputNames = $this->getRawProperty(
			Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider::INPUT_NAMES_PROPERTY,
		);
		if (!is_array($inputNames))
		{
			return [];
		}

		$startNames = $inputNames[$this->childFlowInputPort ?? 0]
			?? $inputNames[Bizproc\Internal\Service\Activity\ComplexActivityService::PORTLESS_RULES_KEY]
			?? null
		;

		return $startNames === null ? [] : array_values(array_filter((array)$startNames, 'is_string'));
	}

	/**
	 * The legacy shape of the handler of the child status changes: `protected` and one-argument, the way the
	 * activities of the very first versions declare it. Declared here so that the traversal mixed into this
	 * class reaches its callback without making every one of those declarations incompatible.
	 */
	protected function OnEvent(CBPActivity $sender)
	{
		$this->runChildFlowClosedEvent($sender);
	}

	protected function reInitialize()
	{
		$this->executionStatus = CBPActivityExecutionStatus::Initialized;
		$this->executionResult = CBPActivityExecutionResult::None;
		// A skip belongs to one execution: left over from a previous run of a loop it would
		// suppress the CloseActivity record the new run owes the log.
		$this->skippedByEngine = false;
		// The document the context names may differ from pass to pass of a loop.
		$this->forgetResolvedDocumentContext();

		if (!$this->childContainerEnabled)
		{
			return;
		}

		// A node run again - a branch of a loop, a state entered twice - owes its children a new flow, and
		// the new execution has not reached the logic of the node yet.
		$this->childFlowStarted = false;
		$this->childFlowInputPort = null;

		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('reInitialize'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_REINIT', ['#NAME#' => $this->getName()]),
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		foreach ($this->arActivities as $activity)
		{
			$activity->reInitialize();

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				$this->getChildContainerTraceKey('reInitialize'),
				self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_REINIT_CHILD'),
				[
					'child_activity' => $activity->getName(),
					'child_type' => $activity->getType(),
				],
			);
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('reInitialize'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_REINIT_DONE'),
			[
				'parent_activity' => $this->getName(),
				'processed_count' => count($this->arActivities),
			],
		);
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

		if (!$this->childContainerEnabled)
		{
			return;
		}

		// an activity restored from a legacy blob can carry no container at all
		if (!is_array($this->arActivities))
		{
			$this->arActivities = [];
		}

		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('fixUpParentChildRelationship'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_FIX_RELATION'),
			[
				'parent_activity' => $this->getName(),
				'parent_type' => $this->getType(),
				'child_activity' => $nestedActivity->getName(),
				'child_type' => $nestedActivity->getType(),
				'current_children_count' => count($this->arActivities),
			],
		);

		$this->arActivities[] = $nestedActivity;

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			$this->getChildContainerTraceKey('fixUpParentChildRelationship'),
			self::getChildContainerTraceMessage('BPCGCA_DEBUG_TRACE_FIX_RELATION_DONE'),
			[
				'parent_activity' => $this->getName(),
				'child_activity' => $nestedActivity->getName(),
				'new_children_count' => count($this->arActivities),
			],
		);
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
	 * @param array|null $activityData The node as its template carries it, passed by a caller that has it:
	 *     it decides without reading any descriptor whether the completion with the service properties of the
	 *     unified panel is needed at all ({@see self::needsUnifiedPanelCompletion()}). A caller passing
	 *     nothing is answered exactly as before.
	 * @return CBPActivity|null
	 * @throws CBPArgumentOutOfRangeException
	 */
	public static function createInstance($code, $name, ?array $activityData = null)
	{
		if (preg_match("#[^a-zA-Z0-9_]#", $code))
		{
			throw new CBPArgumentOutOfRangeException("Activity '" . $code . "' is not valid");
		}

		$classname = 'CBP' . $code;
		if (!class_exists($classname))
		{
			return null;
		}

		$activity = new $classname($name);
		if ($activity instanceof self && self::needsUnifiedPanelCompletion($activity, $activityData))
		{
			self::completeWithUnifiedPanelProperties((string)$code, $activity);
		}

		return $activity;
	}

	/**
	 * Whether the node can carry anything of the unified settings panel at all: a service property of the panel
	 * among the properties the template saved, or children of its own. Neither of the two means there is nothing
	 * to complete - and the descriptor of the activity, a `.description.php` read with its language file for the
	 * first instance of every type in the request, is not asked for.
	 *
	 * The gate is what keeps the cost off the general creation path: loading a process builds every node of its
	 * template, most of them ordinary activities the panel never serves, and each distinct type would otherwise
	 * pay that read on every run.
	 *
	 * Cheap and one-sided on purpose: it never turns a node the panel does serve into one it does not - the
	 * verdict itself stays with the descriptor ({@see self::completeWithUnifiedPanelProperties()}) - and a caller
	 * that cannot tell what the node carries passes nothing and is completed as before.
	 */
	private static function needsUnifiedPanelCompletion(CBPActivity $activity, ?array $activityData): bool
	{
		if ($activityData === null)
		{
			return true;
		}

		// Children of a composite activity are held and run by its own class, so having them says nothing about
		// the panel; for every other activity the container of children is what the panel opens.
		if (!empty($activityData['Children']) && !($activity instanceof CBPCompositeActivity))
		{
			return true;
		}

		$properties = $activityData['Properties'] ?? null;

		return is_array($properties)
			&& array_intersect_key($properties, self::getUnifiedPanelServicePropertyNames()) !== []
		;
	}

	/** @return array<string, true> Names of the service properties of the panel, as a set. */
	private static function getUnifiedPanelServicePropertyNames(): array
	{
		return self::$unifiedPanelServicePropertyNames ??= array_fill_keys(
			Bizproc\Internal\Service\Container::instance()
				->getUnifiedPanelDescriptorProvider()
				->getServicePropertyNames(),
			true,
		);
	}

	/**
	 * Brings the service properties of the unified panel to an instance: the class of a translated node
	 * declares none of them, and `initializeFromArray()` silently drops a key the class does not declare -
	 * a setting configured in the panel would disappear the moment the process is loaded.
	 *
	 * A node declaring the property itself keeps its own declaration and its own default, so what the panel
	 * wrote is remembered apart ({@see self::$unifiedPanelDefaultsApplied}): only that answers later whether a
	 * property carries data of the process ({@see self::collectUntouchedUnifiedPanelProperties()}).
	 *
	 * The same verdict opens the container of children of the node: a node the panel serves may be given
	 * children by its template, only such a node ever owns them, and it is the node itself that runs them
	 * after its own logic ({@see self::executeWithPayload()}).
	 */
	private static function completeWithUnifiedPanelProperties(string $code, CBPActivity $activity): void
	{
		$serviceProperties = self::getUnifiedPanelServiceProperties($code);
		if ($serviceProperties === [])
		{
			return;
		}

		$activity->childContainerEnabled = true;
		// An activity owning children by definition runs them from its own execution and is never asked to run
		// them a second time; the same boundary the white list of the children is drawn along
		// ({@see self::validateChild()}), and the action block of such a node is withheld by the descriptor.
		$activity->childFlowAfterNativeLogic = !($activity instanceof CBPCompositeActivity);

		$appliedDefaults = [];
		foreach ($serviceProperties as $propertyName => $property)
		{
			if (array_key_exists($propertyName, $activity->arProperties))
			{
				continue;
			}

			$appliedDefaults[$propertyName] = $property['Default'] ?? null;
			$activity->arProperties[$propertyName] = $appliedDefaults[$propertyName];
			$activity->arPropertiesTypes[$propertyName] = ['Type' => (string)($property['Type'] ?? '')];
		}

		self::$unifiedPanelDefaultsApplied[mb_strtolower($code)] = $appliedDefaults;
	}

	/**
	 * The one recognition point of "this activity is served by the unified settings panel", and a second one
	 * must not appear: the very same verdict opens the container of children of a node
	 * ({@see self::completeWithUnifiedPanelProperties()}), keeps the white list of those children
	 * ({@see self::validateChild()}) and tells the root of a node process that a child owning children of its
	 * own is a legitimate one ({@see CBPNodeWorkflowActivity::validateChild()}).
	 *
	 * Answered by the descriptor alone - no database, no activity class - and memoized per activity code, so
	 * asking it on the path every template is saved through costs one read per code and per request.
	 */
	protected static function isServedByUnifiedPanel(string $activityCode): bool
	{
		return self::getUnifiedPanelServiceProperties($activityCode) !== [];
	}

	/**
	 * @return array<string, array> Property descriptors the activity of this code needs on top of the ones
	 *     its class declares, empty for an activity served by no unified panel.
	 */
	private static function getUnifiedPanelServiceProperties(string $code): array
	{
		$cacheKey = mb_strtolower($code);
		if (!array_key_exists($cacheKey, self::$unifiedPanelServiceProperties))
		{
			self::$unifiedPanelServiceProperties[$cacheKey] =
				Bizproc\Internal\Service\Container::instance()
					->getUnifiedPanelDescriptorProvider()
					->getServicePropertiesForActivity($code)
			;
		}

		return self::$unifiedPanelServiceProperties[$cacheKey];
	}

	/**
	 * The entry a settings form filled in by a user is saved through, and every caller saving one must use it.
	 * Three callers save a template no node of the panel can reach and stay on the direct call of the activity:
	 * {@see Bizproc\Automation\Engine\Template::saveRobotSettings()} and its crm twin
	 * {@see \Bitrix\Crm\Automation\Engine\Template::saveRobotSettings()}, both saving a robot against a
	 * throwaway template built around that single robot, and
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService},
	 * recomputing the properties of a draft against a synthetic one-node template.
	 *
	 * An activity answers the save by composing the properties of its node out of the fields of its own form
	 * and assigning them to the node as a whole - about a hundred and seventy implementations of
	 * `getPropertiesDialogValues()` do it that way. The service properties of the unified panel
	 * ({@see self::getUnifiedPanelServiceProperties()}) belong to the node and to no form of an activity, so
	 * that assignment drops the condition, the graph of the children and the filter of the node every time
	 * the user saves the form.
	 *
	 * The snapshot is put back over whatever the activity answered: a key of the set belongs to the node and to
	 * no form of an activity, so an answer of that form about it is authoritative at no point. It cannot even
	 * be a meaningful one - the shared form of the complex nodes answers with the `null` a form field that
	 * never existed reads back ({@see Bizproc\Public\Activity\BaseComplexActivity::getPropertiesDialogValues()}),
	 * and would erase the rules of the node with it. Only the keys of the set are put back, so a form field the
	 * user cleared stays cleared, and the single way to drop a service property is to send a template no longer
	 * carrying it. For an activity no unified panel serves the set is empty and this is plain delegation.
	 *
	 * Two shapes of a call degrade to that same plain delegation: an argument list carrying no template to
	 * take the snapshot from, and a call naming no node of it - an empty `activityName`, or a name the
	 * template does not carry. Both leave the snapshot empty and the restore a no-op, so the save loses the
	 * service properties of the node exactly the way it did before this entry existed.
	 *
	 * What is put back comes from the template the client sent and passes no `validateProperties()` of the
	 * activity. That template has always belonged to the client and the rights to it are checked above, so
	 * this opens nothing new; what it does drop is the side effect the assignment used to have - a service key
	 * of the node is no longer thrown away with the answer of its form.
	 *
	 * Named apart from {@see Bizproc\Activity\BaseTrigger::applyPropertiesDialogValues()}: that one is the hook
	 * a trigger composes its own answer in, and an inherited method of the same name would collide with it.
	 *
	 * @param array $parameters Arguments of the form in their existing order: documentType, activityName,
	 *     &workflowTemplate, &workflowParameters, &workflowVariables, currentValues, &errors, constants.
	 *     Elements the caller passed by reference stay references.
	 * @return mixed What the activity answered, unchanged.
	 */
	public static function savePropertiesDialogValues(string $code, array $parameters): mixed
	{
		$serviceProperties = self::getUnifiedPanelServiceProperties($code);

		// The second condition guards the reference taken below: a reference to a missing element would create
		// it and change the set of arguments the delegation passes on. Arguments not shaped like the ones of a
		// settings form are delegated the way they always were instead.
		if ($serviceProperties === [] || !is_array($parameters[self::DialogValuesTemplateArgument] ?? null))
		{
			return self::callStaticMethod($code, 'getPropertiesDialogValues', $parameters);
		}

		$activityName = $parameters[self::DialogValuesActivityNameArgument] ?? '';
		$template = &$parameters[self::DialogValuesTemplateArgument];

		// Taken right before the delegation and not earlier: the caller may have edited the node on its way
		// here, and a property it removed must stay removed.
		$node = &CBPWorkflowTemplateLoader::findActivityByName($template, $activityName);
		$savedProperties = is_array($node['Properties'] ?? null)
			? array_intersect_key($node['Properties'], $serviceProperties)
			: []
		;
		unset($node);

		$result = self::callStaticMethod($code, 'getPropertiesDialogValues', $parameters);

		$node = &CBPWorkflowTemplateLoader::findActivityByName($template, $activityName);

		// The form of the properties is checked the same way the snapshot above checks it: writing into
		// properties that are not a map would raise where the plain delegation only overwrote the value.
		if (is_array($node) && is_array($node['Properties'] ?? []))
		{
			foreach ($savedProperties as $name => $value)
			{
				$node['Properties'][$name] = $value;
			}
		}

		return $result;
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

	/**
	 * @param array $context Template scheme context for getPropertiesMap(), e.g. ['Template' => $workflowTemplate].
	 *   The 'Properties' key always belongs to $currentValues and cannot be overridden by a caller.
	 */
	public static function createConfigurator(
		string $activityType = '',
		array $currentValues = [],
		array $documentType = [],
		array $context = [],
	): \Bitrix\Bizproc\Public\Activity\Configurator
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

		$propertiesMapContext = array_merge($context, ['Properties' => $currentValues]);
		$propertiesMap = $className::getPropertiesMap($documentType, $propertiesMapContext);

		$configurator
			->setActivityType($activityType)
			// `+` leaves untouched the declaration of a node that declares a service property of its own.
			->setPropertiesMap($propertiesMap + self::getUnifiedPanelServiceProperties($activityType));

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

			if ($this->cancelChildFlow())
			{
				return;
			}

			$this->markClosed($arEventParameters);
		}
	}

	/**
	 * Takes the chain of children down with the node when the node itself is cancelled in the middle of that
	 * chain: the children are cancelled and the closing of the node waits for them, which is how a composite
	 * activity has always ended a cancelled branch ({@see CBPSequenceActivity::cancel()}). The drained queue
	 * brings the node back to its marking, this time with nothing left to wait for.
	 *
	 * A node running no chain has an empty queue and pays one comparison: closing over a child that is still
	 * running is what the guard of the closing point refuses ("ActiveChildExist"), and the refusal would take
	 * the whole process down instead of the one branch being cancelled.
	 *
	 * @return bool Whether the node now waits for its children instead of closing.
	 */
	private function cancelChildFlow(): bool
	{
		if ($this->activityQueue === [])
		{
			return false;
		}

		// Nothing new may start once the node is going down.
		$this->pendingQueue = [];

		foreach (array_keys($this->activityQueue) as $childName)
		{
			$child = $this->workflow->getActivityByName((string)$childName);
			if ($child === null)
			{
				unset($this->activityQueue[$childName]);

				continue;
			}

			if ($child->executionStatus === CBPActivityExecutionStatus::Executing)
			{
				$this->workflow->cancelActivity($child);
			}
		}

		return $this->activityQueue !== [];
	}

	/**
	 * The second point of the two phases of a node served by the unified settings panel: a node whose own
	 * logic finishes later - by a timer or an external event - has already left
	 * {@see self::executeWithPayload()} with "executing" and marks itself completed only now, so this is
	 * where its chain of children starts. The node stays open and closes for real once the queue has drained
	 * ({@see self::close()}), which brings it back here with the chain behind it.
	 *
	 * One point for every such node: their own classes know nothing about children. The fault path never
	 * reaches this method, so a failed logic starts no chain, and a node with no chain of its own pays one
	 * flag read.
	 */
	public function markCompleted($arEventParameters = [])
	{
		$this->executionResult = CBPActivityExecutionResult::Succeeded;

		if ($this->startChildFlowOnce())
		{
			return;
		}

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
				// Whether an activity has living children is answered by its container, not by its class:
				// an ordinary node served by the unified panel owns children just as a composite one does.
				foreach ($this->collectNestedActivities() ?? [] as $activity)
				{
					if (
						($activity->executionStatus != CBPActivityExecutionStatus::Initialized)
						&& ($activity->executionStatus != CBPActivityExecutionStatus::Closed)
					)
					{
						throw new CBPInvalidOperationException('ActiveChildExist');
					}
				}

				if ($this->isActivated() && !$this->skippedByEngine)
				{
					/** @var CBPTrackingService $trackingService */
					$trackingService = $this->workflow->getService('TrackingService');
					try
					{
						$trackingService->write(
							$this->getWorkflowInstanceId(),
							CBPTrackingType::CloseActivity,
							$this->getName(),
							$this->executionStatus,
							$this->executionResult,
							$this->getTitle()
						);
					}
					catch (Throwable $trackingException)
					{
						if ($this->executionResult !== CBPActivityExecutionResult::Faulted)
						{
							throw $trackingException;
						}
						// best effort on the fault path, see CBPWorkflow::writeFaultTracking()
						Main\Application::getInstance()->getExceptionHandler()->writeToLog($trackingException);
					}
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

		try
		{
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
		}
		catch (CBPArgumentNullException $e) {}

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

	protected function trackError(?string $errorMsg)
	{
		if ($errorMsg)
		{
			$this->writeToTrackingService($errorMsg, 0, \CBPTrackingType::Error);
		}
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

	public function markSkippedByEngine(): void
	{
		$this->skippedByEngine = true;
	}

	public function setDocumentContext(string $contextExpression): void
	{
		$this->documentContext = $contextExpression;
		$this->forgetResolvedDocumentContext();
	}

	public function getOutputPortId(): int
	{
		return $this->outputPortId;
	}

	public static function validateProperties($arTestProperties = array(), CBPWorkflowTemplateUser $user = null)
	{
		return array();
	}

	/**
	 * Composition of the children of an activity, checked on the one point every entry into a template passes
	 * ({@see CBPWorkflowTemplateLoader::validateTemplate()}): the transport of the designer, an import, REST
	 * and the AI converter are limited alike.
	 *
	 * A node served by the unified settings panel - the very verdict that opens its container of children
	 * ({@see self::completeWithUnifiedPanelProperties()}) - keeps the white list of that panel
	 * ({@see Bizproc\Public\Activity\BaseComplexActivity::validateUnifiedPanelChild()}). Every other activity
	 * answers as it always has, without any restriction, and pays a memoized descriptor read for the answer -
	 * asked at all only for an activity the template really gave children to.
	 *
	 * The document context the white list is resolved in is not a parameter of this method: activities of
	 * every module and of every portal override it with a signature of their own, so a parameter added here
	 * would make every one of them incompatible (a fatal on include). The validation pass publishes the
	 * context instead ({@see Bizproc\Internal\Service\Activity\ChildValidationContext}).
	 */
	public static function validateChild($childActivity, $bFirstChild = false)
	{
		// An activity owning children by definition answers for their composition itself, the complex node of
		// the unified panel among them ({@see CBPCompositeActivity}); the container the panel opens belongs to
		// an ordinary activity, and only that one is answered here.
		if (is_subclass_of(static::class, CBPCompositeActivity::class))
		{
			return [];
		}

		$activityCode = static::getActivityCode();
		if (!self::isServedByUnifiedPanel($activityCode))
		{
			return [];
		}

		return Bizproc\Public\Activity\BaseComplexActivity::validateUnifiedPanelChild($activityCode, $childActivity);
	}

	/** Code of the activity of this class: the name of the class without the `CBP` prefix, lower-cased. */
	protected static function getActivityCode(): string
	{
		$class = static::class;

		return mb_strtolower(str_starts_with($class, 'CBP') ? mb_substr($class, 3) : $class);
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
