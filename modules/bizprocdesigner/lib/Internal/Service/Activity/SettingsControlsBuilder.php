<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\ActivityControlsBuilder;
use Bitrix\Bizproc\Public\Activity\ActivityControlsProjectContext;
use Bitrix\Bizproc\Public\Service\Template\ActivityUsageAnalyzer;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Public\Document\DocumentFieldsResolver;
use Bitrix\BizprocDesigner\Public\Service\Activity\TriggerUpgradeResolver;
use Bitrix\Main\Context;

/**
 * The settings form of one node as the editor renders it: the controls of its properties map and the broken
 * links of its references, shared by the editing surface and the read-only view of a system template.
 *
 * Checks no rights of its own: the document type, the scheme and the trusted project context are
 * preconditions the caller has already authorized, each surface by its own gate.
 */
class SettingsControlsBuilder
{
	use ActivitySettingsDecoder;

	/** Told by the prefix and not by a list of them, so that a condition added later is counted as well. */
	private const CONDITION_CONSTRUCTION_PREFIX = 'condition:';

	private readonly int $userId;

	/** @var \Closure(int, array): bool */
	private readonly \Closure $canCreateWorkflowOnDocumentType;

	/**
	 * @param int|null $userId Null resolves the current user.
	 * @param \Closure(int $userId, array $documentType): bool|null $canCreateWorkflowOnDocumentType The gate
	 *     the fields of the own document of a node are disclosed by; null asks the CreateWorkflow provider.
	 */
	public function __construct(?int $userId = null, ?\Closure $canCreateWorkflowOnDocumentType = null)
	{
		$this->userId = $userId
			?? (int)(new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->getId()
		;
		$this->canCreateWorkflowOnDocumentType =
			$canCreateWorkflowOnDocumentType
			?? self::canCreateWorkflowOnDocumentType(...)
		;
	}

	/**
	 * @param array $workflow Scheme around the node in the transport of the editor: JSON of the template,
	 *     the parameters, the variables and the constants.
	 * @param array|\Closure(): array{properties: array, constants: array} $trustedProjectContext Saved
	 *     project context of the node. A closure is called only when the properties map holds project-aware
	 *     controls, so a caller reads the stored template for the forms that need it and for no other.
	 *
	 * @return array{brokenLinks: array, controls: ?array, useDocumentContext: bool}
	 */
	public function build(
		array $documentType,
		array $activity,
		array $workflow,
		array $options,
		array|\Closure $trustedProjectContext = [],
	): array
	{
		$brokenLinks = [];
		$activityName = (string)($activity['Name'] ?? '');
		$hiddenDefaultControls = $this->resolveHiddenDefaultControls($options);
		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
		] = $this->decodeActivitySettings($workflow, $documentType);

		$activity = $this->applyTriggerUpgrade($activity, $documentType);

		$description = Container::instance()->getActivitySearcherService()->searchByCode($activity['Type']);
		$result = [
			'brokenLinks' => $brokenLinks,
			'controls' => null,
			'useDocumentContext' => $description?->getFilter() !== null,
		];
		$configuratorContext = [
			'Template' => $workflowTemplate,
			// Site of the page the editor is opened on, for the maps whose data depends on it; empty when the
			// kernel did not resolve it. The rights are decided without it.
			'SiteId' => (string)Context::getCurrent()->getSite(),
		];
		if (array_key_exists('workflowDocumentType', $options))
		{
			$workflowDocumentType = is_array($options['workflowDocumentType'])
				? array_values($options['workflowDocumentType'])
				: [];
			$validParts = array_filter(
				$workflowDocumentType,
				static fn($part): bool => is_string($part) && $part !== '',
			);
			$configuratorContext['WorkflowDocumentType'] = count($workflowDocumentType) === 3
				&& count($validParts) === 3
					? $workflowDocumentType
					: [];
		}
		if (array_key_exists('relationAncestorBlockIds', $options))
		{
			$relationAncestorBlockIds = is_array($options['relationAncestorBlockIds'])
				? $options['relationAncestorBlockIds']
				: [];
			$configuratorContext['RelationAncestorBlockIds'] = array_values(array_unique(array_filter(
				$relationAncestorBlockIds,
				static fn($blockId): bool => is_string($blockId) && $blockId !== '',
			)));
		}
		$configurator = \CBPActivity::createConfigurator(
			$activity['Type'],
			$activity['Properties'],
			$documentType,
			$configuratorContext + [
				'ConditionBlockAvailable' => UnifiedPanelSurfaceAvailability::isConditionBlockAvailableForNode(
					$activity['Type'],
					$description,
				),
			],
		);

		if (!$configurator->getActivityType())
		{
			return $result;
		}

		$propertiesMap = $configurator->getPropertiesMap();

		if ($activityName && $workflowTemplate)
		{
			$documentFieldsResolver = new DocumentFieldsResolver();
			$globalConstants = \Bitrix\Bizproc\Workflow\Type\GlobalConst::getAll($documentType);
			$globalVariables = \Bitrix\Bizproc\Workflow\Type\GlobalVar::getAll($documentType);

			$buildAnalyzer = static fn(array $documentFields): ActivityUsageAnalyzer =>
				(new ActivityUsageAnalyzer($workflowTemplate))
					->setParameters($workflowParameters)
					->setVariables($workflowVariables)
					->setConstants($workflowConstants)
					->setGlobalConstants($globalConstants)
					->setGlobalVariables($globalVariables)
					->setDocumentFields($documentFields)
					->setReturnDocumentFieldsResolver($documentFieldsResolver->getFields(...))
			;

			$templateFields = $documentFieldsResolver->getFields($documentType);
			$analyzedFields = $this->resolveAnalyzedDocumentFields(
				$templateFields,
				$documentFieldsResolver,
				$activity,
				$documentType,
			);

			$brokenLinks = $buildAnalyzer($analyzedFields)->analyzeUsages($activityName);

			$ownDocumentLinks =
				$analyzedFields === $templateFields
					? []
					: $this->collectNodeOwnDocumentLinks($activity)
			;
			if ($ownDocumentLinks)
			{
				$brokenLinks = $this->mergeNodeOwnBrokenLinks(
					$brokenLinks,
					$buildAnalyzer($templateFields)->analyzeUsages($activityName),
					$ownDocumentLinks,
				);
			}
		}

		$projectContext = [
			'properties' => [],
			'constants' => [],
		];
		if (ActivityControlsBuilder::hasProjectControls($propertiesMap))
		{
			$projectContext = array_merge(
				$projectContext,
				$trustedProjectContext instanceof \Closure ? $trustedProjectContext() : $trustedProjectContext,
			);
		}

		$controlsBuilder = new ActivityControlsBuilder(
			$configurator,
			$activity,
			new ActivityControlsProjectContext(
				userId: $this->userId,
				workflowConstants: $projectContext['constants'],
				trustedPropertyValues: $projectContext['properties'],
			),
		);
		$controls = $controlsBuilder->build();

		if ($hiddenDefaultControls)
		{
			$controls = array_values(array_filter(
				$controls,
				static fn($control) => !in_array(
					$control->toArray()['property']['FieldName'] ?? '',
					$hiddenDefaultControls,
					true,
				),
			));
		}

		$result['controls'] = $controls;
		$result['brokenLinks'] = $brokenLinks;

		return $result;
	}

	/**
	 * The settings form of a node saved with a deprecated trigger class is built from the class that
	 * replaces it, otherwise the owner of such a node would not see the controls the new class added and
	 * could only re-save it blindly. Nothing is written into the template here: the type of the node changes
	 * on save (see {@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}).
	 */
	private function applyTriggerUpgrade(array $activity, array $documentType): array
	{
		$properties = is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [];

		$upgradeResolver = new TriggerUpgradeResolver();
		$upgrade = $upgradeResolver->resolveUpgradedType(
			(string)($activity['Type'] ?? ''),
			$upgradeResolver->resolveNodeDocumentType([$properties['Document'] ?? null], $documentType),
		);

		$activity['Type'] = $upgrade['type'];
		$activity['Properties'] = $upgradeResolver->applyUpgradeProperties($properties, $upgrade['properties']);

		return $activity;
	}

	/**
	 * Fields the usage analyzer resolves `{=Document:*}` references against: those of the document of the
	 * template plus those of the document the node itself works in. A node with a document of its own - a
	 * complex node fixing one, a trigger naming the document of its event - builds the forms of its nested
	 * actions in that document, so the references picked there name fields the template document does not
	 * have. A node without one resolves to the template document and is analyzed exactly as before.
	 *
	 * The merged set is not the answer for every reference of such a node, only for the ones the runtime
	 * reads in that document too ({@see self::collectNodeOwnDocumentLinks()}).
	 *
	 * The merged set belongs to the analyzer alone: the properties map of the form and the authorization of
	 * the saved project context are built from the document type of the template and stay that way.
	 *
	 * @param array $templateFields Fields of the document of the template, the set an unchanged answer is
	 *     the very same array as - which is how the caller tells the two apart.
	 * @param array $activity Node as it is analyzed, that is after {@see self::applyTriggerUpgrade()}: the
	 *     document a trigger publishes follows its current class.
	 */
	private function resolveAnalyzedDocumentFields(
		array $templateFields,
		DocumentFieldsResolver $documentFieldsResolver,
		array $activity,
		array $documentType,
	): array
	{
		$publishedDocumentType = Container::instance()->getComplexActivityService()->getPublishedDocumentTypeForNode(
			(string)($activity['Type'] ?? ''),
			is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [],
		);
		$nodeDocumentType = \Bitrix\BizprocDesigner\Internal\Service\Container::getNodeFilterAvailability()
			->resolveEffectiveDocumentType($publishedDocumentType, $documentType)
		;

		$isDocumentOfTemplate =
			$nodeDocumentType === null
			|| array_map(strval(...), array_values($nodeDocumentType))
				=== array_map(strval(...), array_values($documentType))
		;
		if ($isDocumentOfTemplate)
		{
			return $templateFields;
		}

		// The same right that gates the action catalog of that document type, and a refusal only leaves the
		// set as it was: fields of a document the user may not author workflows on are never disclosed.
		if (!($this->canCreateWorkflowOnDocumentType)($this->userId, $nodeDocumentType))
		{
			return $templateFields;
		}

		return $templateFields + $documentFieldsResolver->getFields($nodeDocumentType);
	}

	/**
	 * References of the node the runtime resolves in the document of the TEMPLATE although the node has a
	 * document of its own, as the links the analyzer prints for them.
	 *
	 * The document of the node reaches only what the node saves as an activity of its own: a nested action of a
	 * rule or of a relation is saved with a `Document` key of that type, which the loader of the template turns
	 * into a document context of its own ({@see \CBPActivity::setDocumentContext()}), while a condition of those
	 * very containers becomes no activity at all and is counted apart ({@see self::collectConditionLinks()}).
	 * The condition of a trigger is the other half, decided before the process starts, on a stub workflow bound
	 * to the document of the event
	 * ({@see \Bitrix\Bizproc\Activity\Mixins\ApplyRulesChecker::checkNodeCondition()}) - the boundary
	 * the value of a condition is already normalised along
	 * ({@see \Bitrix\BizprocDesigner\Internal\Trait\ConditionValueResolver::resolveConditionDocumentType()}).
	 *
	 * Everything else the node itself holds - its title, the condition of a node that is not a trigger, any
	 * property of its form - is read off the root of the running process
	 * ({@see \Bitrix\Bizproc\Runtime\Mixins\ActivityRuntimePropertyGetter::getRuntimeDocumentField()}), where
	 * `Document` is the document of the template. Counting those by the merged set would silence a warning the
	 * user needs.
	 *
	 * Only `Document` references are collected because only they can differ between the two runs: the analyzer
	 * is given the same parameters, variables, constants and return-document resolver both times.
	 *
	 * @return array<string, true> Links as {@see ActivityUsageAnalyzer::analyzeUsages()} spells them, as a set.
	 *     A link is only ever used to pick out of what that method returned, so a set that came out empty or
	 *     spelled otherwise leaves the summary exactly as the merged run counted it.
	 */
	private function collectNodeOwnDocumentLinks(array $activity): array
	{
		$activityInstance = $this->createUsageActivityInstance($activity);
		if ($activityInstance === null)
		{
			return [];
		}

		$properties = is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [];

		$nodeDocumentProperties = [
			ComplexActivityService::RULES_PARAM,
			UnifiedPanelDescriptorProvider::RELATIONS_PROPERTY,
		];
		if (Container::instance()->getActivitySearcherService()->isTriggerActivity((string)($activity['Type'] ?? '')))
		{
			$nodeDocumentProperties[] = UnifiedPanelDescriptorProvider::CONDITION_PROPERTY;
		}

		$links = [];
		foreach (array_diff(array_keys($properties), $nodeDocumentProperties) as $propertyName)
		{
			foreach ($activityInstance->collectPropertyUsages((string)$propertyName) as $usage)
			{
				if (!is_array($usage) || ($usage[0] ?? null) !== SourceType::DocumentField)
				{
					continue;
				}

				$link = '{=' . SourceType::DocumentField . ':' . $usage[1] . '}';
				$links[$link] = true;
			}
		}

		try
		{
			foreach ([ComplexActivityService::RULES_PARAM, UnifiedPanelDescriptorProvider::RELATIONS_PROPERTY] as $name)
			{
				$this->collectConditionLinks($properties[$name] ?? null, $links);
			}
		}
		catch (\Throwable)
		{
			// A container shaped otherwise than the panel saves leaves the summary as the merged run counted it.
		}

		return $links;
	}

	/**
	 * Links of the conditions standing inside the rules and the relations of a node, the one thing those two
	 * containers do not answer for by the document of the node. A nested action is saved with a `Document` key
	 * of that document, the `IfElseActivity` a condition becomes is not
	 * ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}), and a context
	 * is never inherited from the parent ({@see \CBPActivity::getDocumentId()}): both halves of such a
	 * condition are read off the root of the running process ({@see \CBPMixedCondition::evaluate()}), where
	 * `Document` is the document of the template - while the control the value is picked in offers the fields
	 * of the node document.
	 *
	 * The whole expression is read rather than one key of it: which key carries the value is up to the
	 * control that saved it.
	 *
	 * @param array<string, true> $links Set the links found are added to.
	 */
	private function collectConditionLinks(mixed $value, array &$links): void
	{
		if (!is_array($value))
		{
			return;
		}

		if (
			is_string($value['type'] ?? null)
			&& str_starts_with($value['type'], self::CONDITION_CONSTRUCTION_PREFIX)
		)
		{
			$this->collectDocumentLinks($value['expression'] ?? null, $links);

			return;
		}

		foreach ($value as $item)
		{
			$this->collectConditionLinks($item, $links);
		}
	}

	/** Every `{=Document:*}` reference of a value of the payload, however deep it is nested. */
	private function collectDocumentLinks(mixed $value, array &$links): void
	{
		if (is_array($value))
		{
			foreach ($value as $item)
			{
				$this->collectDocumentLinks($item, $links);
			}

			return;
		}

		if (!is_string($value))
		{
			return;
		}

		foreach (\CBPActivity::findExpressions($value) as $expression)
		{
			$usage = SourceType::getObjectSourceType($expression['object'] ?? '', $expression['field'] ?? '');
			if (is_array($usage) && ($usage[0] ?? null) === SourceType::DocumentField)
			{
				$links['{=' . SourceType::DocumentField . ':' . $usage[1] . '}'] = true;
			}
		}
	}

	/**
	 * The summary of the node once the two runs are put together: the merged run answers for the references
	 * the runtime resolves in the document of the node, the template run for the rest.
	 *
	 * Ordered by the template run, the one reading every reference of the node, so that a node whose
	 * references are all its own is reported in the very order it was before the second run existed.
	 *
	 * @param array<string, true> $ownDocumentLinks {@see self::collectNodeOwnDocumentLinks()}
	 */
	private function mergeNodeOwnBrokenLinks(
		array $mergedRunLinks,
		array $templateRunLinks,
		array $ownDocumentLinks,
	): array
	{
		$brokenLinks = [];
		foreach ($templateRunLinks as $link)
		{
			if (isset($mergedRunLinks[$link]) || isset($ownDocumentLinks[$link]))
			{
				$brokenLinks[$link] = $link;
			}
		}

		return $brokenLinks + $mergedRunLinks;
	}

	/**
	 * The node as the analyzer instantiates it ({@see ActivityUsageAnalyzer}), so that the usages read off it
	 * are the very ones the analyzer classified. Two details of that are load-bearing here.
	 *
	 * The data of the node is deliberately not handed to {@see \CBPActivity::createInstance()}: a node given
	 * none is completed with the service properties of the unified panel, and without them
	 * `initializeFromArray()` would drop the rules and the condition the panel saved - the very properties this
	 * reading is about.
	 *
	 * The node is hung on a root activity because a property its class does not declare sends
	 * `getRawProperty()` to the read-only data of the root, and a node standing alone is its own root: asking
	 * such a node about a property it does not declare - a trigger about the document of its event, say -
	 * would call a method only a composite activity has.
	 */
	private function createUsageActivityInstance(array $activity): ?\CBPActivity
	{
		$searcher = Container::instance()->getActivitySearcherService();

		try
		{
			$searcher->includeActivityFile('SequentialWorkflowActivity');
			$rootActivity = \CBPActivity::createInstance('SequentialWorkflowActivity', 'Template');

			$searcher->includeActivityFile((string)($activity['Type'] ?? ''));
			$activityInstance = \CBPActivity::createInstance(
				(string)($activity['Type'] ?? ''),
				(string)($activity['Name'] ?? ''),
			);
			if ($rootActivity === null || $activityInstance === null)
			{
				return null;
			}

			$rootActivity->fixUpParentChildRelationship($activityInstance);
			$activityInstance->initializeFromArray($activity['Properties'] ?? []);

			return $activityInstance;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/** A document type the CreateWorkflow provider cannot answer for is refused, not waved through. */
	private static function canCreateWorkflowOnDocumentType(int $userId, array $documentType): bool
	{
		try
		{
			return (bool)\CBPDocument::CanUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateWorkflow,
				$userId,
				$documentType,
			);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * Default controls the caller asked not to receive. ActivityControlsBuilder opens every form with
	 * the service controls of a standalone activity window - title, id, comment - and a panel that
	 * edits those elsewhere has no place for them. Field names are matched exactly: a property of the
	 * activity may legitimately be called `Title`, and only the lowercase `title` is the service one.
	 *
	 * @return string[]
	 */
	private function resolveHiddenDefaultControls(array $options): array
	{
		$hidden = [];

		if ((bool)($options['hideTitle'] ?? false))
		{
			$hidden[] = 'title';
		}

		if ((bool)($options['hideEditorComment'] ?? false))
		{
			$hidden[] = 'activity_editor_comment';
		}

		return $hidden;
	}
}
