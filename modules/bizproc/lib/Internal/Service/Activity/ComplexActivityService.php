<?php

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeAction;
use Bitrix\Bizproc\Activity\Dto\Complex\Settings;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use CBPRuntime;

use Bitrix\Main\Localization\Loc;

class ComplexActivityService
{
	/**
	 * Request-local memo for getRelationActionActivity(), negative results included: the relation
	 * action and its description are the same for every caller within one request (validateChild()
	 * alone resolves it once per child activity).
	 *
	 * @var array<string, ActivityDescription|null>
	 */
	private array $relationActionActivityCache = [];

	/**
	 * Request-local memo of the node-action catalog, by document context: the traversal behind
	 * searchByType() is the same for every node asked about, while validateChild() asks once per child
	 * and the AI converter once per node type. Keyed by the document type, because a description is
	 * included with it and context-dependent restrictions (LOCKED) are part of the answer.
	 *
	 * @var array<string, Activities>
	 */
	private array $nodeActionCatalogCache = [];

	/**
	 * Request-local memo of the white list of the children of a node served by the unified panel, as a set of
	 * normalized codes ready to be read: building it filters, sorts and normalizes the whole node-action
	 * catalog, while validation asks about one child at a time - a node with several children would pay that
	 * per child. Keyed by the node and by the document context, because a context-dependent restriction
	 * (LOCKED) is part of the answer.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $unifiedPanelChildCodesCache = [];

	/**
	 * Request-local memo of the event document type of a trigger, negative results included: resolving it
	 * builds an activity instance, while one node is asked about by more than one surface within a request
	 * (the settings panel and, on save, one validation command per rules container). Keyed by the properties
	 * of the node too, because they are what the answer depends on.
	 *
	 * @var array<string, array|null>
	 */
	private array $triggerEventDocumentTypeCache = [];

	public function __construct(
		private readonly Searcher $searcher,
		private readonly ActionCatalogMap $actionCatalogMap,
	)
	{
	}

	/** Name of the rules property: a consumer addresses the rules of a node by it, never by its type. */
	public const RULES_PARAM = 'Rules';

	/**
	 * Reserved key of the rules container of a node without input ports: rules are keyed by the input port
	 * they belong to, and such a node has none. Not a port id - it creates no port on the canvas and none
	 * in the saved template.
	 */
	public const PORTLESS_RULES_KEY = 'n0';

	private const FIRST_INPUT_PORT_ID = 'i0';

	/**
	 * Service activities of a conditional branch: a child of any node the unified panel serves, whatever the
	 * action catalog of that node offers.
	 *
	 * @var list<string>
	 */
	private const UNIFIED_PANEL_SERVICE_CHILDREN = [
		'IfElseActivity',
		'EmptyBlockActivity',
	];

	/**
	 * Methods whose presence on a class declares the channel of the condition document: "the condition of this
	 * node is decided not on the document of the process, but on the record of its own event".
	 *
	 * - `public static getConditionDocumentSourceFieldNames(): array` - `FieldName`s of the own properties map of
	 *   the class whose values decide the document of the condition;
	 * - `public getConditionDocumentComplexType(): ?array` - complex type of that document by the properties of
	 *   the node, null while the condition is decided on the document of the process;
	 * - `public getConditionDocumentId(TriggerParameters $parameters): ?array` - complex identifier
	 *   `[moduleId, entity, documentId]` of the record the condition is to be decided on.
	 *
	 * Declaring the channel and publishing a document type are answers to different questions, and a class does
	 * not do both: a published type would carry the real document into the forms of the child actions and into
	 * the catalog. An invalid answer is treated as no answer: neither building the settings nor checking before
	 * the start may fail over what a node returns here.
	 *
	 * @var array<string, bool> Name of the method, and whether the class declares it static.
	 */
	private const CONDITION_DOCUMENT_CHANNEL_METHODS = [
		'getConditionDocumentSourceFieldNames' => true,
		'getConditionDocumentComplexType' => false,
		'getConditionDocumentId' => false,
	];

	/**
	 * The children a node served by the unified settings panel may carry: the service activities of a
	 * conditional branch, the actions of the catalog of the node and the relation action its descriptor names.
	 * The one answer both surfaces of the panel read
	 * ({@see \Bitrix\Bizproc\Public\Activity\BaseComplexActivity::validateUnifiedPanelChild()}), so a second
	 * white list cannot appear.
	 *
	 * Answered as a set of normalized codes and memoized per node and document context: a child is compared
	 * against it by one array read, whatever the number of children the template gave the node.
	 *
	 * @param array|null $documentType {@see self::getCorrespondingNodeActionActivityByName()}
	 * @return array<string, true> normalized activity code => true
	 */
	public function getUnifiedPanelChildCodes(string $activityCode, ?array $documentType = null): array
	{
		$cacheKey = $this->searcher->normalizeActivityCode($activityCode) . '|' . serialize($documentType);

		return $this->unifiedPanelChildCodesCache[$cacheKey] ??= $this->resolveUnifiedPanelChildCodes(
			$activityCode,
			$documentType,
		);
	}

	/** @return array<string, true> */
	private function resolveUnifiedPanelChildCodes(string $activityCode, ?array $documentType): array
	{
		$classes = self::UNIFIED_PANEL_SERVICE_CHILDREN;

		foreach ($this->getCorrespondingNodeActionActivityByName($activityCode, $documentType) as $nodeAction)
		{
			$classes[] = $nodeAction->getClass();
		}

		$relationActionActivity = $this->getRelationActionActivity($activityCode);
		if ($relationActionActivity !== null)
		{
			$classes[] = $relationActionActivity->getClass();
		}

		// Normalized (case, CBP prefix) like the rest of bizproc: a child node-action type comes from the preset
		// in lower case (BaseSettingsAction), while getClass() returns PascalCase.
		return array_fill_keys(
			array_map(
				fn(string $class): string => $this->searcher->normalizeActivityCode($class),
				$classes,
			),
			true,
		);
	}

	public function getActivityDescriptionByCode(string $complexActivityCode): ?ActivityDescription
	{
		$description = $this->searcher->searchByCode($complexActivityCode);
		if (!$description)
		{
			return null;
		}

		// The descriptor alone is the marker: neither the activity type nor the node type is asked about,
		// a trigger declares `trigger` without `node` and a base node keeps its `simple` type.
		if (!$description->getComplexActivitySettings())
		{
			return null;
		}

		return $description;
	}

	/**
	 * Document type declared by the class of a node ({@see FixedDocumentComplexActivity}), the same for every
	 * instance of it; null for a node whose class declares none.
	 *
	 * This is the answer the surfaces with a runtime counterpart are gated by - the filter block and the
	 * relation autofill - and it stays class-declared on purpose: the runtime resolves both by the very same
	 * contract ({@see \Bitrix\Bizproc\Public\Activity\Registry\FilterResultPropertyResolverRegistry},
	 * {@see \Bitrix\Bizproc\Public\Activity\Registry\TargetDocumentResolverRegistry}), so an editor answer wider
	 * than this one would offer a surface the runtime cannot serve. What the settings panel shows the user is
	 * {@see self::getPublishedDocumentTypeForNode()} instead.
	 */
	public function getFixedDocumentTypeForNodeAction(string $activityType): ?array
	{
		CBPRuntime::getRuntime()->includeActivityFile($activityType);

		$className = 'CBP' . $activityType;
		if (
			!class_exists($className)
			|| !isset(class_implements($className, false)[FixedDocumentComplexActivity::class])
		)
		{
			return null;
		}

		/* @var FixedDocumentComplexActivity $className */
		return $className::getDocumentTypeForNodeAction();
	}

	/**
	 * Document type a node works on, as the editor publishes it: the type the class declares, and for a trigger
	 * the document type of the event it reacts to. Null when neither answers - the document type of the edited
	 * template is what the caller falls back to
	 * ({@see \Bitrix\BizprocDesigner\Internal\Service\Activity\NodeFilterAvailability::resolveEffectiveDocumentType()}).
	 *
	 * A trigger is the reason this is not the same answer as {@see self::getFixedDocumentTypeForNodeAction()}:
	 * the document of its event is what its condition compares fields of and what its sub-actions act on, but
	 * it is not fixed by its class - two nodes of one trigger class serve two document types - and it opens no
	 * runtime-backed surface.
	 *
	 * @param array $activityProperties Properties of the node being asked about, as the template carries them.
	 *     They only matter for a trigger; omitted, a trigger configured per instance answers null instead of
	 *     guessing. Every surface publishing the type passes them, so the settings panel, the capability
	 *     catalog and the save-time validation cannot disagree about the document context of one node.
	 */
	public function getPublishedDocumentTypeForNode(string $activityType, array $activityProperties = []): ?array
	{
		return $this->getFixedDocumentTypeForNodeAction($activityType)
			?? $this->getEventDocumentTypeForTrigger($activityType, $activityProperties)
		;
	}

	/**
	 * Document type of the event a trigger reacts to, read from the trigger itself and never derived from its
	 * code: {@see \Bitrix\Bizproc\Activity\BaseTrigger::getConfigurator()} carries it, and the very same value
	 * is what {@see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable::upsert()} stores as
	 * the MODULE_ID/ENTITY/DOCUMENT_TYPE the event is matched against. The instance is completed from the
	 * properties of the node first, because a trigger of one class serves several document types
	 * (`FieldChangedTrigger` and the CRM triggers built on it resolve theirs from a property).
	 *
	 * Null for anything that is not a trigger, for a trigger whose event carries no document
	 * ({@see UnifiedPanelDescriptorProvider::getTriggerCodesWithoutDocument()} - the very list that withholds
	 * the condition block from them) and for a trigger left on the `Workflow` pseudo-document: the pseudo-type
	 * names no real document, so the document type of the edited template has to keep answering.
	 */
	private function getEventDocumentTypeForTrigger(string $activityType, array $activityProperties): ?array
	{
		$cacheKey = $this->searcher->normalizeActivityCode($activityType) . '|' . serialize($activityProperties);
		if (array_key_exists($cacheKey, $this->triggerEventDocumentTypeCache))
		{
			return $this->triggerEventDocumentTypeCache[$cacheKey];
		}

		return $this->triggerEventDocumentTypeCache[$cacheKey] = $this->resolveEventDocumentTypeForTrigger(
			$activityType,
			$activityProperties,
		);
	}

	private function resolveEventDocumentTypeForTrigger(string $activityType, array $activityProperties): ?array
	{
		if (!$this->searcher->isTriggerActivity($activityType))
		{
			return null;
		}

		if ($this->isTriggerWithoutDocument($activityType))
		{
			return null;
		}

		try
		{
			$activity = \CBPActivity::createInstance($activityType, '');
			if (!$activity instanceof \CBPActivity)
			{
				return null;
			}

			$activity->initializeFromArray($activityProperties);
			$documentType = $activity instanceof BaseTrigger
				? $activity->getEventDocumentComplexType()
				: $activity->getConfigurator()->getDocumentComplexType()?->toArray()
			;
		}
		catch (\Throwable)
		{
			// Asked while a settings panel is being opened or a template saved, and a trigger of an absent
			// module answers with an incomplete type its own configurator rejects. Neither surface may break
			// over it: no document type is published and the document type of the template keeps answering.
			return null;
		}

		if (!self::isComplexDocumentType($documentType))
		{
			return null;
		}

		return $documentType === Workflow::getComplexType() ? null : array_values($documentType);
	}

	/**
	 * Document type the condition of a node is decided on: the type the node declares through the channel
	 * ({@see self::declaresConditionDocumentChannel()}), and the published type for a node that declares none.
	 *
	 * The order of the guards is the contract and not a matter of style. "Not a trigger" answers first,
	 * because the condition of an ordinary node is decided inside the process ({@see \CBPWorkflow::isNodeConditionMet()});
	 * were that guard to come after the channel, this would start answering with a type for non-triggers and
	 * the values of the condition of every complex node with a fixed document would be normalized by it.
	 *
	 * The second summand is what keeps the answer unchanged for the four families whose process runs on the
	 * document of the event - the native CRM entity create/edit nodes, the field change of an entity and the
	 * file creation on the disk: the published type is the right answer for them, so they declare no channel.
	 *
	 * @param array $activityProperties Properties of the node being asked about, as the template carries them.
	 */
	public function getConditionDocumentTypeForNode(string $activityType, array $activityProperties): ?array
	{
		if ($activityType === '' || !$this->searcher->isTriggerActivity($activityType))
		{
			return null;
		}

		if ($this->resolveConditionDocumentTriggerClass($activityType) === null)
		{
			return $this->getPublishedDocumentTypeForNode($activityType, $activityProperties);
		}

		return $this->resolveDeclaredConditionDocumentType($activityType, $activityProperties);
	}

	/**
	 * `FieldName`s of the properties of a node whose values decide the document of its condition, as the class
	 * declares them ({@see self::CONDITION_DOCUMENT_CHANNEL_METHODS}); empty for a node that declares no channel.
	 *
	 * No instance is raised on this path: the answer belongs to the class, and every summand of it is either
	 * memoized or a lookup the request has already paid for.
	 */
	public function getConditionDocumentSourceFieldNames(string $activityType): array
	{
		if ($activityType === '' || !$this->searcher->isTriggerActivity($activityType))
		{
			return [];
		}

		if ($this->isTriggerWithoutDocument($activityType))
		{
			return [];
		}

		$className = $this->resolveConditionDocumentTriggerClass($activityType);
		if ($className === null)
		{
			return [];
		}

		// The detection has passed, so this method is declared by the class itself, public and static.
		return $className::getConditionDocumentSourceFieldNames();
	}

	private function resolveDeclaredConditionDocumentType(string $activityType, array $activityProperties): ?array
	{
		try
		{
			$activity = \CBPActivity::createInstance($activityType, '');
			if (!self::declaresConditionDocumentChannel($activity))
			{
				return null;
			}

			$activity->initializeFromArray($activityProperties);
			$documentType = $activity->getConditionDocumentComplexType();
		}
		catch (\Throwable)
		{
			// Asked while a settings panel is being opened or a template saved: neither surface may break
			// over a node whose own module answers with something it cannot complete.
			return null;
		}

		return self::isComplexDocumentType($documentType) ? array_values($documentType) : null;
	}

	/**
	 * Whether a node declares the channel of the condition document
	 * ({@see self::CONDITION_DOCUMENT_CHANNEL_METHODS}), asked of its class or of an instance of it.
	 *
	 * The declaration is read off the methods and not off an interface on purpose, and this is not a tidier
	 * spelling of the same thing. `implements` is resolved when the class of the node is declared, so a node of
	 * another module would fatal on every portal where that module is already updated and `bizproc` is not yet -
	 * on the behaviour of today, at existing templates, before a single call. The detection by name is what lets
	 * a node of a foreign module declare the channel with no update dependency on `bizproc`, and bringing an
	 * interface back would bring that fatal back with it.
	 *
	 * All three methods or none: a part of the channel is no channel. Visibility and staticness are part of the
	 * check because both readers call these methods from outside - that is the guarantee the compiler used to
	 * give here, and nothing else gives it now.
	 */
	public static function declaresConditionDocumentChannel(object|string $activity): bool
	{
		foreach (self::CONDITION_DOCUMENT_CHANNEL_METHODS as $method => $isStatic)
		{
			if (!method_exists($activity, $method))
			{
				return false;
			}

			$declared = new \ReflectionMethod($activity, $method);
			if (!$declared->isPublic() || $declared->isStatic() !== $isStatic)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Name of the class of a node that declared the channel, null for every other node - including a node
	 * whose activity file names no class at all.
	 */
	private function resolveConditionDocumentTriggerClass(string $activityType): ?string
	{
		CBPRuntime::getRuntime()->includeActivityFile($activityType);

		$className = 'CBP' . $activityType;
		if (!class_exists($className) || !self::declaresConditionDocumentChannel($className))
		{
			return null;
		}

		return $className;
	}

	/**
	 * A trigger whose event carries no document ({@see UnifiedPanelDescriptorProvider::getTriggerCodesWithoutDocument()})
	 * - the very list that withholds the condition block from it.
	 */
	private function isTriggerWithoutDocument(string $activityType): bool
	{
		$provider = Container::instance()->getUnifiedPanelDescriptorProvider();

		return in_array(
			$this->searcher->normalizeActivityCode($activityType),
			$provider->getTriggerCodesWithoutDocument(),
			true,
		);
	}

	/** A complex identifier - a document type, a document id - is three non-empty strings, and nothing else. */
	public static function isComplexDocumentType(mixed $documentType): bool
	{
		if (!is_array($documentType) || count($documentType) !== 3)
		{
			return false;
		}

		foreach ($documentType as $part)
		{
			if (!is_string($part) || $part === '')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $complexActivityCode
	 * @param array|null $documentType Document context of the edited template. Descriptions receive
	 *     it while being included, so context-dependent restrictions (LOCKED) apply: a locked
	 *     action is excluded from the node catalog and thereby from server-side validation.
	 */
	public function getCorrespondingNodeActionActivityByName(
		string $complexActivityCode,
		?array $documentType = null,
	): Activities
	{
		$complexActivityDescription = $this->getActivityDescriptionByCode($complexActivityCode);
		if (!$complexActivityDescription)
		{
			return new Activities();
		}

		$settings = $complexActivityDescription->getComplexActivitySettings();
		if (!$settings)
		{
			return new Activities();
		}

		if (!$settings->useGlobalActionCatalog && $settings->actionDictionary->isEmpty())
		{
			return new Activities();
		}

		$nodeActionDescriptionCollection = $this->getNodeActionCatalog($documentType);

		// Classification is context-free — reuse the collection in hand so the catalog map
		// does not repeat the expensive folder traversal within the same request.
		$this->actionCatalogMap->warmUp($nodeActionDescriptionCollection);

		$nodeActionDescriptionCollection = $nodeActionDescriptionCollection->filter(
			static fn(ActivityDescription $description) => $description->getLocked() === [],
		);

		$declaredActivities = (new Activities(
			$nodeActionDescriptionCollection
				->filter(fn(ActivityDescription $description) => $this->getDictionaryAction($settings, $description) !== null)
				->map(
					function (ActivityDescription $description) use ($settings): ActivityDescription
					{
						$action = $this->getDictionaryAction($settings, $description);
						if ($action === null)
						{
							return $description;
						}

						$descriptionWithPreset = $action->presetId
							? $description->applyPresetById($action->presetId)
							: $description
						;

						$nodeActionPreset = $action->toPreset();
						if (empty($nodeActionPreset))
						{
							return $descriptionWithPreset;
						}

						return $descriptionWithPreset->applyPreset($nodeActionPreset);
					},
				),
		))->sort();

		if (!$settings->useGlobalActionCatalog)
		{
			return $declaredActivities;
		}

		// Explicit dictionary entries keep their presets and precedence; the rest of the
		// classified catalog follows them, so widening the node never reorders declared actions.
		$classifiedCodes = $this->actionCatalogMap->getAvailable();
		$catalogActivities = $nodeActionDescriptionCollection
			->filter(
				fn(ActivityDescription $description) => $this->getDictionaryAction($settings, $description) === null
					&& isset($classifiedCodes[$this->searcher->normalizeActivityCode($description->getClass())])
			)
			->sort()
		;

		return $declaredActivities->addCollection($catalogActivities);
	}

	/**
	 * The node-action catalog of one document context, walked once per request. The folder traversal
	 * behind it costs tens of milliseconds and does not depend on the node being asked about, so the
	 * white list of children (one ask per child) and the AI converters (one ask per node type) share it.
	 *
	 * Copies and never the remembered collection itself: ActivityDescription is mutable and consumers
	 * mutate what they get - the same copy contract as {@see Searcher::searchByCode()}, and a shallow
	 * copy holds only while the objects a description carries are not modified in place.
	 */
	private function getNodeActionCatalog(?array $documentType): Activities
	{
		$cacheKey = serialize($documentType);
		$catalog = $this->nodeActionCatalogCache[$cacheKey] ??= $this->searcher->searchByType(
			ActivityType::NODE_ACTION->value,
			$documentType,
		);

		return new Activities($catalog->map(static fn(ActivityDescription $description) => clone $description));
	}

	private function getDictionaryAction(Settings $settings, ActivityDescription $description): ?NodeAction
	{
		return $settings->actionDictionary->get($this->searcher->normalizeActivityCode($description->getClass()));
	}


	public function getRelationActionActivity(string $complexActivityCode): ?ActivityDescription
	{
		$cacheKey = $this->searcher->normalizeActivityCode($complexActivityCode);
		if (array_key_exists($cacheKey, $this->relationActionActivityCache))
		{
			return $this->relationActionActivityCache[$cacheKey];
		}

		return $this->relationActionActivityCache[$cacheKey] = $this->resolveRelationActionActivity($complexActivityCode);
	}

	private function resolveRelationActionActivity(string $complexActivityCode): ?ActivityDescription
	{
		$complexActivityDescription = $this->getActivityDescriptionByCode($complexActivityCode);
		if (!$complexActivityDescription)
		{
			return null;
		}

		$settings = $complexActivityDescription->getComplexActivitySettings();
		if (!$settings || $settings->relationAction === null)
		{
			return null;
		}

		$relationAction = $settings->relationAction;
		$description = $this->searcher->searchByCode($relationAction->activityCode);
		if (!$description)
		{
			return null;
		}

		// Same preset contract as the ordinary node-action path above: a named preset first,
		// then the dictionary-level overrides (applyPreset ignores empty values itself).
		if ($relationAction->presetId)
		{
			$description = $description->applyPresetById($relationAction->presetId);
		}

		return $description->applyPreset($relationAction->toPreset());
	}

	/**
	 * @param bool $hasInputPort Decides how the single default rule container is addressed: by the id of
	 *     the first input port, or by the reserved {@see self::PORTLESS_RULES_KEY}.
	 */
	public function configureRuleProperty(bool $hasInputPort = true): array
	{
		$ruleContainerKey = $hasInputPort ? self::FIRST_INPUT_PORT_ID : self::PORTLESS_RULES_KEY;
		$defaultParamValue = [
			$ruleContainerKey => [
				'portId' => $ruleContainerKey,
				'ruleCards' => [],
			],
		];

		return [
			self::RULES_PARAM => [
				'Name' => Loc::getMessage('BIZPROC_BCA_RULES_PROPERTY_NAME'),
				'FieldName' => self::RULES_PARAM,
				'Type' => FieldType::RULES,
				'Required' => true,
				'Default' => $defaultParamValue,
			],
		];
	}

	/**
	 * The rules property of a node, addressed by name and not by its position in the properties map: the map
	 * carries more than one property of type {@see FieldType::RULES} - the node condition and the filter
	 * settings share it - so the order of the map must not decide which one the rules are read from and
	 * written to. The single answer for every consumer, the manual editor and the AI converters alike.
	 * The lookup by type stays a fallback for a node whose rules property is named otherwise.
	 *
	 * @param string $activityCode Code of the node the configurator was built for, naming the last fallback:
	 *     a class implementing no `IBPConfigurableActivity` is answered by
	 *     {@see \CBPActivity::createConfigurator()} with an empty configurator, while the rules of such a node
	 *     live in the service properties of the unified panel all the same. Omitted, the lookup stays
	 *     configurator-only.
	 */
	public function resolveRuleProperty(Configurator $configurator, string $activityCode = ''): ?array
	{
		foreach ($configurator->getPropertiesMap() as $propertyName => $property)
		{
			if (
				$propertyName === self::RULES_PARAM
				|| ($property['FieldName'] ?? null) === self::RULES_PARAM
			)
			{
				return $property;
			}
		}

		return $configurator->getFirstPropertyByType(FieldType::RULES)
			?? $this->resolveServiceRuleProperty($activityCode)
		;
	}

	/**
	 * Rules a node served by the unified panel carries without its class declaring them - the same set the
	 * instance of the activity is completed with ({@see \CBPActivity::createInstance()}), so the descriptor
	 * read here and the property the runtime carries cannot diverge. Empty for any other node.
	 *
	 * Only this lookup is answered: the properties map of the configurator stays empty, because a non-empty
	 * map is what tells
	 * {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommand} that the class
	 * renders the unified form - which a legacy-dialog class does not.
	 */
	private function resolveServiceRuleProperty(string $activityCode): ?array
	{
		if ($activityCode === '')
		{
			return null;
		}

		return Container::instance()
			->getUnifiedPanelDescriptorProvider()
			->getServicePropertiesForActivity($activityCode)[self::RULES_PARAM] ?? null
		;
	}

	/**
	 * Name the rules of a node are addressed by, {@see self::resolveRuleProperty()}.
	 *
	 * @param string $activityCode {@see self::resolveRuleProperty()}
	 */
	public function resolveRulePropertyName(Configurator $configurator, string $activityCode = ''): ?string
	{
		$name = $this->resolveRuleProperty($configurator, $activityCode)['FieldName'] ?? null;

		return is_string($name) && $name !== '' ? $name : null;
	}
}
