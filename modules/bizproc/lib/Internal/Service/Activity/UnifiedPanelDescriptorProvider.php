<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\Complex\AvailableBlock;
use Bitrix\Bizproc\Activity\Dto\Complex\BlockAvailability;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeActionDictionary;
use Bitrix\Bizproc\Activity\Dto\Complex\Settings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;

/**
 * Completes a trigger and a base node that declares no complex settings of its own with the default
 * unified-panel descriptor. A description declaring its own descriptor is returned untouched.
 */
final class UnifiedPanelDescriptorProvider
{
	/**
	 * Property the condition of a node served by the unified panel is stored in: the list of condition
	 * entries `CBPMixedCondition` reads. An empty list and an absent property both mean "no condition".
	 */
	public const CONDITION_PROPERTY = 'NodeCondition';

	/**
	 * Properties the graph of the children of a node is stored in: the links between the children, the start
	 * of every chain and the output port every chain leaves through. The names and the shapes are the ones
	 * of the complex nodes ({@see \Bitrix\Bizproc\Public\Activity\BaseComplexActivity}), because the very
	 * same converter writes them and the very same traversal reads them
	 * ({@see \Bitrix\Bizproc\Public\Activity\Mixins\ChildFlowTraversal}).
	 */
	public const LINKS_PROPERTY = 'Links';
	public const INPUT_NAMES_PROPERTY = 'InputNames';
	public const OUTPUT_NAMES_PROPERTY = 'OutputNames';

	/**
	 * Property the relation cards of a node are stored in: the rule containers of its relation ports, keyed
	 * by port id. The name and the shape are the ones of the complex nodes
	 * ({@see \Bitrix\Bizproc\Public\Activity\BaseComplexActivity}), because the very same panel writes them
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::saveSettingsAction()}) and
	 * reads them back.
	 *
	 * Listed here although only a node whose descriptor declares a relation action ever carries a value: the
	 * set is what a settings form of an activity is not allowed to drop, and the graph properties above are
	 * declared for every served node on the same ground. A node offered no relations block keeps the empty
	 * default and nothing else changes for it. The panel dropping the property itself when the relations
	 * surface is gone is a save of the panel and passes no settings form at all.
	 */
	public const RELATIONS_PROPERTY = 'Relations';

	/**
	 * Property the verdict of the panel over its own cards is stored in: `Y` while a card the panel saved is
	 * left unfinished. The name and the shape are the ones of the complex nodes
	 * ({@see \Bitrix\Bizproc\Public\Activity\BaseComplexActivity}), because the very same panel writes and
	 * clears it ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::saveSettingsAction()})
	 * and the very same check reads it ({@see BaseComplexActivity::validateProperties()}).
	 */
	public const NOT_FILLED_PROPERTY = 'NotFilled';

	/**
	 * Triggers whose event carries no real document, so the condition block - it reads document fields -
	 * is not offered to them. An emitter of `Starter::addEvent()` added later without a document has to be
	 * listed here, otherwise the user configures a condition that silently never matches;
	 * `UnifiedPanelDescriptorProviderTest` walks the emitters and turns red when one is missing.
	 *
	 * @var list<string> normalized (lower-case) activity codes
	 */
	private const TRIGGERS_WITHOUT_DOCUMENT = [
		'crmcallassessmenttrigger',
		'bookingaicalltrigger',
		'fullreportreadytrigger',
		'fullreportsenttrigger',
		'startworktimetrigger',
		'stopworktimetrigger',
		'absenceleavesicktrigger',
		'absencevacationtrigger',
		'newemployeetrigger',
		'employeedismissaltrigger',
		// Excluded on a second ground too: its rule check is occupied by the repeated-start guard.
		'scheduledtrigger',
		// Emitters passing the synthetic `Workflow` pseudo-document.
		'imbotnewmessagetrigger',
		'imopenlinesbotnewmessagetrigger',
		'aiagentstarttrigger',
		'aiagentrestarttrigger',
		'taskscreatetrigger',
		'tasksexpiredtrigger',
	];

	/**
	 * Triggers whose condition could never prevent the start, so the condition block is not offered to them.
	 * Their `createApplyRules()` publishes no properties - the condition would never reach the APPLY_RULES
	 * row the pre-start check reads ({@see \Bitrix\Bizproc\Activity\Mixins\ApplyRulesChecker}) - and their
	 * scenarios (manual start, the legacy document autostart) select templates by the AUTO_EXECUTE flag and
	 * start them with no rules check at all
	 * ({@see \Bitrix\Bizproc\Starter\AbstractProcessStarter::runManualScenario()}). Offered, the condition
	 * could only skip the trigger node inside a process already created - against the contract that a false
	 * condition of a trigger starts no process. `UnifiedPanelDescriptorProviderTest` walks the shipped
	 * triggers and turns red when one whose saved rules carry no properties is missing here.
	 *
	 * @var list<string> normalized (lower-case) activity codes
	 */
	private const TRIGGERS_WITHOUT_PRESTART_CHECK = [
		'manualstarttrigger',
		'crmcompanymanualstarttrigger',
		'crmcontactmanualstarttrigger',
		'crmdealmanualstarttrigger',
		'crmleadmanualstarttrigger',
		'crmquotemanualstarttrigger',
		'crmsmartinvoicemanualstarttrigger',
		'crmsmartmanualstarttrigger',
		'editdocumenttrigger',
	];

	/**
	 * Nodes completed with this descriptor whose class is a composite activity ({@see \CBPCompositeActivity}):
	 * such a class owns and runs its children itself, so the container the panel opens is never opened for it
	 * ({@see \CBPActivity::completeWithUnifiedPanelProperties()}) and neither is the white list of its children
	 * ({@see \CBPActivity::validateChild()}). The action block is withheld from them for that reason - offered,
	 * it would be a block whose children nothing ever runs.
	 *
	 * The class is never loaded to answer this: the descriptor is built on the catalog path, where loading the
	 * class of every node is exactly the cost the catalog is built to avoid. `UnifiedPanelDescriptorProviderTest`
	 * walks the shipped nodes with their classes loaded and turns red when one is missing here.
	 *
	 * @var list<string> normalized (lower-case) activity codes
	 */
	private const COMPOSITE_TRANSLATED_NODES = [
		// A node only where `Bitrix\Bizproc\Dev\ENV` is defined, and a composite activity running its own
		// children as the event-driven branches it waits for.
		'listenactivity',
	];

	/**
	 * @param bool $isTriggerActivity Verdict of the single trigger recognition point
	 *     ({@see \Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher::isTriggerActivity()}); must not be
	 *     re-derived here from the code, the name or the class.
	 */
	public function enrich(
		string $activityCode,
		ActivityDescription $description,
		bool $isTriggerActivity,
	): ActivityDescription
	{
		if ($description->getComplexActivitySettings() !== null)
		{
			return $description;
		}

		if (!$this->isTranslatedNode($description, $isTriggerActivity))
		{
			return $description;
		}

		return $description
			->setAdditionalResult($this->completeAdditionalResult($description))
			->setComplexActivitySettings(
				$this->buildSettings(
					conditionAvailable: !$isTriggerActivity || $this->conditionCanGateTheStart($activityCode),
					actionAvailable: !$this->runsChildrenItself($activityCode),
				),
			)
		;
	}

	/**
	 * Whether the unified panel is the surface the editor is to open for this node: the verdict published with
	 * a catalog item and with a saved block, and the one the settings endpoints of the designer answer by.
	 *
	 * A node declaring a complex-settings descriptor of its own is served by the panel either way - it is not
	 * what the rollout flag of the feature decides about. A node the descriptor above is completed for - a
	 * trigger, a base node - follows that flag, so switching the flag off leaves exactly those nodes on their
	 * legacy settings form and refuses the unified settings a client would send for them.
	 *
	 * What the panel has already saved keeps being loaded and run whatever the flag says: the service
	 * properties of a node, its container of children and its condition are the data contract of the runtime
	 * and not a surface ({@see \CBPActivity::completeWithUnifiedPanelProperties()}), and the white list of the
	 * children of such a node stays with them ({@see \CBPActivity::validateChild()}) - a template saved while
	 * the feature was on has to keep publishing after it is switched off.
	 *
	 * @param bool $featureAvailable Verdict of the designer feature flag
	 *     \Bitrix\BizprocDesigner\Public\Feature\Node\ComplexNodeConnectionsFlag, read and passed in by the
	 *     editor through {@see \Bitrix\BizprocDesigner\Internal\Config\Feature}.
	 */
	public function isSurfaceAvailableForNode(
		string $activityCode,
		ActivityDescription $description,
		bool $featureAvailable,
	): bool
	{
		if (!$description->isServedByUnifiedPanel())
		{
			return false;
		}

		if ($featureAvailable)
		{
			return true;
		}

		// A node the completion applies to is recognized by the very same two categories the completion itself
		// is limited to: a node carrying a descriptor of its own never reaches them - the early exit of
		// enrich() answers it first - and `UnifiedPanelDescriptorProviderTest` keeps the shipped nodes of such
		// a descriptor out of them.
		return !$this->isTranslatedNode(
			$description,
			Container::instance()
				->getActivitySearcherService()
				->isTriggerActivityByDescription($activityCode, $description),
		);
	}

	/**
	 * Return properties of the node the filter results are added to. Naming the map here is what makes the
	 * selection referencable: {@see \CBPRuntime::getActivityReturnProperties()} expands it only for a node
	 * declaring that property, and {@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes}
	 * recomputes return properties on every template load.
	 *
	 * @return list<string>
	 */
	private function completeAdditionalResult(ActivityDescription $description): array
	{
		return array_values(
			array_unique([
				...$description->getAdditionalResult(),
				BaseComplexActivity::FILTER_RETURN_PROPERTIES_MAP,
			]),
		);
	}

	/**
	 * @internal Exposed for {@see \Bitrix\Bizproc\Tests\Lib\Internal\Service\Activity\UnifiedPanelDescriptorProviderTest},
	 *     the only consumer: the list itself is applied inside the provider.
	 * @return list<string> normalized codes of the triggers whose event carries no document
	 */
	public function getTriggerCodesWithoutDocument(): array
	{
		return self::TRIGGERS_WITHOUT_DOCUMENT;
	}

	/**
	 * The single list of service properties a node served by the unified panel needs on top of its own
	 * ones - a second one must not be introduced: the completion of an activity instance reads this very
	 * set. The rules property stays in {@see ComplexActivityService::configureRuleProperty()} and is not
	 * redeclared here.
	 *
	 * Of the order the single thing that matters is that the rules stay the first property of type
	 * {@see FieldType::RULES} in the set: they are addressed by name, but the fallback of that lookup takes
	 * the first property of the type ({@see ComplexActivityService::resolveRuleProperty()}, over
	 * {@see \Bitrix\Bizproc\Public\Activity\Configurator::getFirstPropertyByType()}), and the set carries
	 * several properties of it - the condition, the filter settings, the graph of the children and the
	 * relations. Beyond that the order carries nothing.
	 *
	 * The `NotFilled` mark belongs to the set for the same reason the data it judges does: the panel writes
	 * the two in one save, and a verdict parted from its data is worse than either alone. Dropped, an
	 * unfinished card would stop holding the node out of a publication
	 * ({@see BaseComplexActivity::validateProperties()}) while staying just as unfinished. Kept, it lives
	 * exactly as long as the state it describes - the panel clears it on the save that finishes the card
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::saveSettingsAction()}), and
	 * the snapshot is taken off the template the caller already edited, so a cleared mark is never restored.
	 *
	 * @param bool $hasInputPort Whether the node has an input port: it decides how the single default
	 *     rules container is addressed.
	 */
	public function getServiceProperties(bool $hasInputPort = true): array
	{
		return [
			...Container::instance()->getComplexActivityService()->configureRuleProperty($hasInputPort),
			self::CONDITION_PROPERTY => $this->configureConditionProperty(),
			...BaseComplexActivity::getFilterServiceProperties(),
			...BaseComplexActivity::getRelationServiceProperties(),
			...$this->configureChildGraphProperties(),
			self::RELATIONS_PROPERTY => $this->configureRelationsProperty(),
			self::NOT_FILLED_PROPERTY => $this->configureNotFilledProperty(),
		];
	}

	/**
	 * Names of the service properties, without asking for a single description: the set is the same for every
	 * node the panel serves ({@see self::getServiceProperties()}), and how the rules container is addressed
	 * does not change it. This is what lets a caller on the creation path of every activity tell "the template
	 * gave this node nothing the panel owns" before paying for the descriptor of that node
	 * ({@see \CBPActivity::createInstance()}).
	 *
	 * @return list<string>
	 */
	public function getServicePropertyNames(): array
	{
		return array_keys($this->getServiceProperties());
	}

	/**
	 * The graph of the children of the node, brought to it the same way every other service property is:
	 * a translated node declares none of these, and `initializeFromArray()` drops a key the class does not
	 * declare - the graph the editor saved would be gone the moment the process is loaded.
	 *
	 * `FieldType::RULES` is what keeps them out of the settings form, the same as for the condition: they
	 * are the payload of the action block and never a control of it.
	 *
	 * @return array<string, array>
	 */
	private function configureChildGraphProperties(): array
	{
		$properties = [];
		foreach ([self::LINKS_PROPERTY, self::INPUT_NAMES_PROPERTY, self::OUTPUT_NAMES_PROPERTY] as $name)
		{
			$properties[$name] = [
				'Name' => $name,
				'FieldName' => $name,
				'Type' => FieldType::RULES,
				'Default' => [],
				'Hidden' => true,
			];
		}

		return $properties;
	}

	/**
	 * The relation cards of the node, declared the same way the graph of its children is and for the same
	 * reason: a node declaring the property itself keeps its own declaration, and one that does not would
	 * lose what the panel saved on the next load of the process.
	 *
	 * Placed behind the rules container, as every other property of type {@see FieldType::RULES} in the set
	 * is ({@see self::getServiceProperties()}).
	 */
	private function configureRelationsProperty(): array
	{
		return [
			'Name' => self::RELATIONS_PROPERTY,
			'FieldName' => self::RELATIONS_PROPERTY,
			'Type' => FieldType::RULES,
			'Default' => [],
			'Hidden' => true,
		];
	}

	/**
	 * The verdict of the panel over its own cards. A string mark and not a rules container, so it cannot be
	 * kept out of the settings form the way the payload properties are ({@see FieldType::RULES}): `Hidden`
	 * is what tells the form it is no control of it, the same as for `FilterReturnPropertiesMap`
	 * ({@see \Bitrix\Bizproc\Public\Activity\Mixins\NodeFilterResultProperties::getFilterServiceProperties()}).
	 *
	 * `N` is the default because that is the value the single reader of the mark substitutes for an absent
	 * one ({@see BaseComplexActivity::validateProperties()}): a node the panel never marked is filled.
	 */
	private function configureNotFilledProperty(): array
	{
		return [
			'Name' => self::NOT_FILLED_PROPERTY,
			'FieldName' => self::NOT_FILLED_PROPERTY,
			'Type' => FieldType::STRING,
			'Default' => 'N',
			'Hidden' => true,
		];
	}

	/**
	 * Whether a node of this code keeps its condition in a property of its own instead of the child
	 * branching activity a complex node builds. Answered by {@see Settings::$conditionInNodeProperty}
	 * and by nothing else, so a node describing itself never moves the address of its condition.
	 */
	public function storesConditionInNodeProperty(string $activityCode): bool
	{
		return Container::instance()
			->getActivitySearcherService()
			->searchByCode($activityCode)
			?->getComplexActivitySettings()
			?->conditionInNodeProperty === true
		;
	}

	/**
	 * `FieldType::RULES` is what keeps the property out of the settings form:
	 * {@see \Bitrix\Bizproc\Public\Activity\ActivityControlsBuilder} renders no control for it, so `Name`
	 * never reaches a user and stays the property name instead of a phrase.
	 */
	private function configureConditionProperty(): array
	{
		return [
			'Name' => self::CONDITION_PROPERTY,
			'FieldName' => self::CONDITION_PROPERTY,
			'Type' => FieldType::RULES,
			'Default' => [],
		];
	}

	/**
	 * The same set, asked for one activity: empty for an activity no unified panel serves. Reads the
	 * description alone - no database, no activity class - and the answer is stable for the whole request,
	 * so a caller on the activity creation path is expected to remember it per activity code.
	 *
	 * @return array<string, array> property name => descriptor, in the shape a properties map carries
	 */
	public function getServicePropertiesForActivity(string $activityCode): array
	{
		$searcher = Container::instance()->getActivitySearcherService();

		$description = $searcher->searchByCode($activityCode);
		if ($description === null || !$description->isServedByUnifiedPanel())
		{
			return [];
		}

		// A description naming no ports at all leaves the category to answer: a trigger has no input port.
		return $this->getServiceProperties(
			hasInputPort: $this->declaresInputPort($description)
				?? !$searcher->isTriggerActivityByDescription($activityCode, $description),
		);
	}

	/** @return bool|null Null when the description declares no ports at all. */
	private function declaresInputPort(ActivityDescription $description): ?bool
	{
		$ports = $description->getNodeSettings()?->ports;

		return $ports === null ? null : ($ports->input?->ports ?? []) !== [];
	}

	/**
	 * @internal Exposed for {@see \Bitrix\Bizproc\Tests\Lib\Internal\Service\Activity\UnifiedPanelDescriptorProviderTest},
	 *     the only consumer: the list itself is applied inside the provider.
	 * @return list<string> normalized codes of the completed nodes whose class runs its children itself
	 */
	public function getCompositeNodeCodes(): array
	{
		return self::COMPOSITE_TRANSLATED_NODES;
	}

	private function carriesDocument(string $triggerCode): bool
	{
		return !in_array(mb_strtolower($triggerCode), self::TRIGGERS_WITHOUT_DOCUMENT, true);
	}

	/**
	 * Whether a condition of this trigger is able to keep the process from starting: it has to read a real
	 * document, and the start path of the trigger has to run the pre-start rules check the condition rides on.
	 */
	private function conditionCanGateTheStart(string $triggerCode): bool
	{
		return $this->carriesDocument($triggerCode)
			&& !in_array(mb_strtolower($triggerCode), self::TRIGGERS_WITHOUT_PRESTART_CHECK, true);
	}

	/**
	 * @internal Exposed for {@see \Bitrix\Bizproc\Tests\Lib\Internal\Service\Activity\UnifiedPanelDescriptorProviderTest},
	 *     the only consumer: the list itself is applied inside the provider.
	 * @return list<string> normalized codes of the triggers whose start runs no pre-start rules check
	 */
	public function getTriggerCodesWithoutPreStartCheck(): array
	{
		return self::TRIGGERS_WITHOUT_PRESTART_CHECK;
	}

	private function runsChildrenItself(string $activityCode): bool
	{
		return in_array(mb_strtolower($activityCode), self::COMPOSITE_TRANSLATED_NODES, true);
	}

	/**
	 * The two categories served by the unified panel. A trigger usually declares no node type at all - the
	 * `trigger` type is substituted later by the editor catalog layer - and a base node is one of node type
	 * `simple` or of no declared type; specialized and already complex nodes are left out.
	 */
	private function isTranslatedNode(ActivityDescription $description, bool $isTriggerActivity): bool
	{
		$nodeType = mb_strtolower((string)$description->getNodeType());
		$isBaseNodeType = $nodeType === '' || $nodeType === ActivityNodeType::SIMPLE->value;

		if ($isTriggerActivity)
		{
			return $isBaseNodeType || $nodeType === ActivityNodeType::TRIGGER->value;
		}

		return $isBaseNodeType && $this->declaresNodeType($description);
	}

	private function declaresNodeType(ActivityDescription $description): bool
	{
		foreach ($description->getType() as $type)
		{
			if (mb_strtolower(trim((string)$type)) === ActivityType::NODE->value)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The block composition of a translated node: base settings, condition, action and filter, every other
	 * block unavailable. The output block stays closed because the ports of a node are not changed by this
	 * panel, and the condition keeps living in a property of the node - it gates the node as a whole, the
	 * children of its action block included.
	 *
	 * The actions offered are the global catalog, the very one the universal node offers: no dictionary of its
	 * own is declared, because there is no product selection of "which action suits which node" to declare -
	 * the existing filtering by document type is the whole selection. The two go together: without the global
	 * catalog the dictionary stays empty and the catalog of the node is left empty by the early exit of
	 * {@see ComplexActivityService::getCorrespondingNodeActionActivityByName()}, so the block would be offered
	 * with nothing in it. The catalog itself is built on demand, per request, when the panel asks for it.
	 *
	 * The filter block is only declared here: whether it is offered depends on the document type, which a
	 * description is built without, and stays with `NodeFilterAvailability` of the editor - the declaration
	 * is intersected with its verdict by {@see CapabilityCatalogService::applyRuntimeGates()}.
	 */
	private function buildSettings(bool $conditionAvailable, bool $actionAvailable): Settings
	{
		return new Settings(
			actionDictionary: new NodeActionDictionary(),
			availableBlocks: BlockAvailability::fromMap([
				NodeBlockType::BASE_SETTINGS->value => new AvailableBlock(available: true),
				NodeBlockType::CONDITION->value => new AvailableBlock(available: $conditionAvailable),
				NodeBlockType::ACTION->value => new AvailableBlock(available: $actionAvailable),
				NodeBlockType::FILTER->value => new AvailableBlock(available: true),
				NodeBlockType::OUTPUT->value => new AvailableBlock(available: false),
				NodeBlockType::GROUP->value => new AvailableBlock(available: false),
				NodeBlockType::RELATIONS->value => new AvailableBlock(available: false),
				NodeBlockType::STORAGES->value => new AvailableBlock(available: false),
			]),
			useGlobalActionCatalog: $actionAvailable,
			conditionInNodeProperty: true,
		);
	}
}
