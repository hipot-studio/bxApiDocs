<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasFragment;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasBranch;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasContainer;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasContainerLayoutAdapter;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasFrame;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasLayoutAdapter;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasLink;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasNode;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasNodeFactory;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\PortRef;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Internal\Helper\Activity\ActivityHelper;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Error;
use CBPDocumentEventType;

class SequentialToNodeWorkflow
{
	private const MANUAL_START_TRIGGER = 'ManualStartTrigger';
	private const EDIT_DOCUMENT_TRIGGER = 'EditDocumentTrigger';
	private const CREATE_DOCUMENT_TRIGGER = 'CreateDocumentTrigger';
	protected const COLUMN_GAP = 1;
	protected const COLUMN_SIZE = 375;
	protected const ROW_SIZE = 110;

	protected array $rootActivity;
	private bool $isSystem = false;
	private int $autoExecute = \CBPDocumentEventType::None;
	private array $anchors = [];
	private array $nodeFrames = [];
	private ?string $startTrigger = null;
	private ?CanvasLayoutAdapter $canvasLayoutAdapter = null;
	private ?CanvasNodeFactory $canvasNodeFactory = null;
	private ?array $documentType = null;

	public function __construct(array $template)
	{
		$rootActivity = $template[0] ?? $this->createEmptySequentialRootActivity();

		if (!$rootActivity || $rootActivity['Type'] !== 'SequentialWorkflowActivity')
		{
			throw new \CBPArgumentException("root activity needs to be a SequentialWorkflowActivity");
		}

		$this->rootActivity = $rootActivity;
	}

	public static function makeByTemplateId(int $templateId): static
	{
		$row = static::getTemplateRow($templateId);
		if (!$row)
		{
			throw new \CBPArgumentException("Template ID $templateId does not exist");
		}

		return static::makeByTemplateRow($row);
	}

	private static function getTemplateRow(int $templateId): ?array
	{
		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect([
				'TEMPLATE',
				'IS_SYSTEM',
				'AUTO_EXECUTE',
				'NAME',
				'DESCRIPTION',
				'PARAMETERS',
				'VARIABLES',
				'CONSTANTS',
				'MODULE_ID',
				'ENTITY',
				'DOCUMENT_TYPE',
			])
			->exec()
			->fetch()
		;

		return $row ?: null;
	}

	private static function makeByTemplateRow(array $row): static
	{
		$instance = new static($row['TEMPLATE']);
		$instance->setIsSystem($row['IS_SYSTEM'] === 'Y');
		$instance->setAutoExecute((int)$row['AUTO_EXECUTE']);
		$instance->setDocumentType([$row['MODULE_ID'], $row['ENTITY'], $row['DOCUMENT_TYPE']]);

		return $instance;
	}

	public static function migrateTemplate(int $templateId): Result
	{
		$result = new Result();
		try
		{
			$instance = static::makeByTemplateId($templateId);
			$template = $instance->convert();

			\CBPWorkflowTemplateLoader::update(
				$templateId,
				[
					'TEMPLATE' => $template,
					'AUTO_EXECUTE' => $instance->getRemainingAutoExecute(),
				],
				true,
			);
		}
		catch (\Exception $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	public static function addByTemplateId(int $sourceId): Result
	{
		$result = new Result();
		try
		{
			$row = static::getTemplateRow($sourceId);
			if (!$row)
			{
				throw new \CBPArgumentException("Template ID $sourceId does not exist");
			}

			$instance = static::makeByTemplateRow($row);
			$template = $instance->convert();

			unset($row['ID']);
			$row['NAME'] .= ' (Nodes)';
			$row['TEMPLATE'] = $template;
			$row['DOCUMENT_TYPE'] = Workflow::getComplexType();
			$row['AUTO_EXECUTE'] = 0;

			// a conversion carries a process over into another shape and publishes nothing
			$newId = \CBPWorkflowTemplateLoader::add($row, true, markFirstPublication: false);

			$result->setData(['id' => $newId]);
		}
		catch (\Exception $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	public function setIsSystem(bool $flag): self
	{
		$this->isSystem = $flag;

		return $this;
	}

	public function setAutoExecute(int $type): self
	{
		$this->autoExecute = $type;

		return $this;
	}

	public function setDocumentType(?array $documentType): self
	{
		$this->documentType = $documentType;

		return $this;
	}

	public function convert(): array
	{
		$conversion = $this->convertAndExtractLayout();

		return $conversion['template'];
	}

	public function convertAndExtractLayout(): array
	{
		$layout = $this->buildLayout();
		$this->anchors = $layout->anchors;
		$this->nodeFrames = $layout->nodeFrames;

		return [
			'template' => [$this->createRootActivity($layout->links, $layout->children)],
			'layout' => $layout,
		];
	}

	/**
	 * @param string|null $startTrigger
	 * @return $this
	 */
	public function setStartTrigger(?string $startTrigger): self
	{
		$this->startTrigger = $startTrigger;

		return $this;
	}

	private function createEmptySequentialRootActivity(): array
	{
		return [
			'Type' => 'SequentialWorkflowActivity',
			'Name' => NodesToTemplate::ROOT_NODE_NAME,
			'Children' => [],
		];
	}

	/**
	 * @return array{0: string, 1: Layout\LayoutResult}
	 */
	protected function createTriggers(): array
	{
		$merge = $this->createMergeNode();
		$triggerContainer = CanvasContainer::create('triggers')
			->arrangeVertically()
			->defineRowGap(0)
		;

		$triggers = [];

		if (!is_null($this->startTrigger))
		{
			$triggers[] = [
				'type' => $this->startTrigger,
				'descriptor' => null,
			];
		}

		if ($this->autoExecute & CBPDocumentEventType::Create)
		{
			$descriptor = $this->resolveCreateStartTriggerDescriptor();
			$triggers[] = [
				'type' => $descriptor?->triggerType ?? self::CREATE_DOCUMENT_TRIGGER,
				'descriptor' => $descriptor,
			];
		}

		if ($this->autoExecute & CBPDocumentEventType::Edit)
		{
			$descriptor = $this->resolveEditStartTriggerDescriptor();
			$triggers[] = [
				'type' => $descriptor?->triggerType ?? self::EDIT_DOCUMENT_TRIGGER,
				'descriptor' => $descriptor,
			];
		}

		foreach ($triggers as $triggerConfig)
		{
			$triggerContainer->appendNode($this->createCanvasNode($this->createTrigger($triggerConfig['type'], $triggerConfig['descriptor'])));
		}

		$mergePoint = new Layout\GridPoint(empty($triggers) ? 0 : count($triggers), 2.5);
		$layout = Layout\LayoutResult::createFromNode($merge, $mergePoint)
			->appendLayout(
				(new CanvasContainerLayoutAdapter($this->getCanvasLayoutAdapter()))->convertContainerToLayout(
					$triggerContainer,
					$merge['Name'],
					new Layout\GridPoint(0, $mergePoint->column - 1),
					false
				)
			)
		;
		foreach ($triggerContainer->findNodeIdsRecursively() as $triggerNodeId)
		{
			$layout->addLink($triggerNodeId, $merge['Name']);
		}

		return [$merge['Name'], $layout];
	}

	protected function createCanvasNode(array $activity): CanvasNode
	{
		return $this->getCanvasNodeFactory()->createFromActivity(
			$activity,
			$this->resolveCanvasNodeSpan($activity)
		);
	}

	protected function resolveCanvasNodeSpan(array $activity): array
	{
		return $this->getCanvasNodeFactory()->resolveSpanFromActivity($activity);
	}

	protected function getCanvasNodeFactory(): CanvasNodeFactory
	{
		$this->canvasNodeFactory ??= new CanvasNodeFactory(static::COLUMN_SIZE, static::ROW_SIZE);

		return $this->canvasNodeFactory;
	}

	protected function buildLayout(): Layout\LayoutResult
	{
		[$startName, $startLayout] = $this->createTriggers();
		$rootLayout = $this->getCanvasLayoutAdapter()->convertFragmentToLayout(
			$this->buildChildrenFragment($this->rootActivity['Children']),
			$startName,
			$startLayout->findAnchor($startName)->moveBy(0, 1)
		);
		$layout = $startLayout->appendLayout($rootLayout);
		[$layout->links, $layout->children] = $this->optimizeChildren(
			$layout->links,
			$layout->children
		);

		return $layout;
	}

	protected function buildChildrenFragment(array $children): CanvasFragment
	{
		$fragment = CanvasFragment::create();

		foreach ($children as $child)
		{
			$fragment->appendFragmentAfterExit($this->buildChildFragment($child));
		}

		return $fragment;
	}

	protected function buildChildFragment(array $child): CanvasFragment
	{
		$isSimpleChild = $this->isSimpleChild($child);
		if ($isSimpleChild)
		{
			return CanvasFragment::createFromActivities([$child]);
		}

		$this->syncActivatedState($child);

		switch ($child['Type'])
		{
			case 'EmptyBlockActivity':
				return $this->buildEmptyBlockFragment($child);
			case 'WhileActivity':
			case 'ForEachActivity':
				return $this->buildIterableFragment($child);
			case 'IfElseActivity':
				return $this->buildIfElseFragment($child);
			case 'ParallelActivity':
			case 'ListenActivity':
				return $this->buildBranchableFragment($child);
			case 'ApproveActivity':
			case 'RequestInformationOptionalActivity':
				return $this->buildYesNoFragment($child);
		}

		throw new \CBPArgumentException("Unsupported child type $child[Type]");
	}

	private function resolveCreateStartTriggerDescriptor(): ?TriggerDescriptorDto
	{
		if (!$this->documentType)
		{
			return null;
		}

		$moduleSettings = \CBPRuntime::getRuntime()->getDocumentService()->getStarterModuleSettings($this->documentType);

		return $moduleSettings?->getCreateDocumentTrigger();
	}

	private function resolveEditStartTriggerDescriptor(): ?TriggerDescriptorDto
	{
		if (!$this->documentType)
		{
			return null;
		}

		$moduleSettings = \CBPRuntime::getRuntime()->getDocumentService()->getStarterModuleSettings($this->documentType);

		return $moduleSettings?->getEditDocumentTrigger();
	}

	private function getRemainingAutoExecute(): int
	{
		$remainingAutoExecute = $this->autoExecute;
		$createTriggerDescriptor = $this->resolveCreateStartTriggerDescriptor();
		$editTriggerDescriptor = $this->resolveEditStartTriggerDescriptor();

		if (
			$createTriggerDescriptor instanceof TriggerDescriptorDto
			&& $createTriggerDescriptor->triggerType !== ''
		)
		{
			$remainingAutoExecute &= ~CBPDocumentEventType::Create;
		}

		if (
			$editTriggerDescriptor instanceof TriggerDescriptorDto
			&& $editTriggerDescriptor->triggerType !== ''
		)
		{
			$remainingAutoExecute &= ~CBPDocumentEventType::Edit;
		}

		return $remainingAutoExecute;
	}

	private function createTrigger(string $type, ?TriggerDescriptorDto $descriptor = null): array
	{
		$activityDescription = \CBPRuntime::getRuntime()->getActivityDescription($type) ?? [];

		$name = ActivityHelper::generateName();

		$title = $descriptor?->title ?? ($activityDescription['NAME'] ?? null);
		$icon = $descriptor?->icon ?? ($activityDescription['NODE_ICON'] ?? null);
		$colorIndex = $activityDescription['COLOR_INDEX'] ?? null;
		$properties = $descriptor?->properties ?? [];
		$properties['Title'] = $title;
		$properties['Icon'] = $icon;
		$properties['ColorIndex'] = $colorIndex;

		$trigger = [
			'Type' => $type,
			'Name' => $name,
			'Properties' => $properties,
		];

		if ($descriptor?->presetId !== null)
		{
			$trigger['PresetId'] = $descriptor->presetId;
		}

		return $trigger;
	}

	protected function optimizeChildren(array $links, array $children): array
	{
		// remove all merge blocks, replace links
		$mergeNames = [];

		foreach ($children as $i => $child)
		{
			if ($child['Type'] === 'Merge')
			{
				$mergeNames[] = $child['Name'];
				unset($children[$i]);
			}
		}

		if ($mergeNames)
		{
			foreach ($mergeNames as $mergeName)
			{
				$outputNames = [];
				$inputNames = [];

				foreach ($links as $j => [$outputName, $inputName])
				{
					if ($this->extractNodeName($outputName) === $mergeName)
					{
						$inputNames[] = $inputName;
						unset($links[$j]);
					}
					if ($this->extractNodeName($inputName) === $mergeName)
					{
						$outputNames[] = $outputName;
						unset($links[$j]);
					}
				}

				array_push($links, ...$this->createLinks($outputNames, $inputNames));
			}
		}

		return [array_values($links), array_values($children)];
	}

	private function extractNodeName(string $nodeRef): string
	{
		return explode(':', $nodeRef, 2)[0];
	}

	protected function createRootActivity(array $links, array $children): array
	{
		foreach ($children as &$child)
		{
			$child['Node'] = $this->makeNodeSettings($child);
		}
		unset($child);

		return NodesToTemplate::createNodeRootActivity($links, $children);
	}

	protected function makeNodeSettings(array $activity): array
	{
		$point = $this->anchors[$activity['Name']] ?? new Layout\GridPoint(0, 0);
		$frame = $this->nodeFrames[$activity['Name']] ?? Layout\GridFrame::createFromPoint($point);
		$ports = [];
		$runtime = \CBPRuntime::getRuntime();
		$nodeType = ActivityNodeType::SIMPLE;

		if (!$runtime->isTriggerActivity((string)$activity['Type']))
		{
			$ports[] = [
				'type' => 'input',
				'id' => 'i0',
			];
		}
		else
		{
			$nodeType = ActivityNodeType::TRIGGER;
		}

		if (
			$activity['Type'] === 'WhileActivity'
			|| $activity['Type'] === 'ForEachActivity'
			|| $activity['Type'] === 'ApproveActivity'
			|| $activity['Type'] === 'RequestInformationOptionalActivity'
			|| $activity['Type'] === 'IfElseBranchActivity'
		)
		{
			$nodeType = ActivityNodeType::OPERATORS;
			$ports = $runtime->getActivityDescription($activity['Type'])['NODE_SETTINGS']['ports'] ?? $ports;
		}
		else
		{
			$ports[] = [
				'type' => 'output',
				'id' => 'o0',
			];
		}

		$node = [
			'id' => $activity['Name'],
			'type' => $nodeType->value,
			'position' => [
				'x' => $point->column * static::COLUMN_SIZE,
				'y' => $point->row * static::ROW_SIZE,
			],
			'dimensions' => [
				'width' => null,
				'height' => null,
			],
			'node' => [
				'title' => $activity['Properties']['Title'] ?? $activity['Type'],
				'icon' => $activity['Properties']['Icon'] ?? null,
				'colorIndex' => $activity['Properties']['ColorIndex'] ?? null,
			],
			'ports' => $ports,
		];

		if ($activity['Type'] === 'EmptyBlockActivity')
		{
			$node['type'] = ActivityNodeType::FRAME->value;
			$node['position']['x'] = $frame->left * static::COLUMN_SIZE - 25;
			$node['position']['y'] = $frame->top * static::ROW_SIZE - 25;
			$node['dimensions']['width'] = $frame->calculateWidth() * static::COLUMN_SIZE;
			$node['dimensions']['height'] = $frame->calculateHeight() * static::ROW_SIZE;
			$node['node']['frameColorName'] = 'orange';
			$node['node']['title'] = 'frame';
			$node['ports'] = [];
		}

		return $node;
	}

	protected function createLink(string $outputName, string $inputName): array
	{
		return CanvasLink::createBetween($outputName, $inputName)->convertToArray();
	}

	private function createLinks(array $outputNames, array $inputNames): array
	{
		$links = [];
		foreach ($outputNames as $outputName)
		{
			foreach ($inputNames as $inputName)
			{
				$links[] = $this->createLink($outputName, $inputName);
			}
		}

		return $links;
	}

	protected function createMergeNode($mergeFlow = false): array
	{
		$title = null;
		if ($mergeFlow)
		{
			$title = \CBPRuntime::getRuntime()->getActivityDescription('MergeFlowNode')['NAME'] ?? null;
		}

		return [
			'Type' => $mergeFlow ? 'MergeFlowNode' : 'Merge',
			'Name' => 'Merge_' . uniqid('', true),
			'Properties' => ['Title' => $title],
		];
	}

	private function syncActivatedState(array &$activity): void
	{
		if (($activity['Activated'] ?? 'Y') === 'N')
		{
			$activity['Children'] = array_map(
				static function ($child)
				{
					$child['Activated'] = 'N';
					return $child;
				},
				$activity['Children']
			);
		}
	}

	protected function convertChildren(string $outputName, Layout\GridPoint $outputPoint, array $children): Layout\LayoutResult
	{
		$parentOutputName = $outputName;
		$parentOutputPoint = $outputPoint;
		$result = Layout\LayoutResult::createFromAnchor($outputName, $outputPoint);
		$pendingSimpleFragment = null;
		$pendingSimpleEntryName = null;
		$pendingSimpleEntryPoint = null;

		foreach ($children as $child)
		{
			$isSimpleChild = $this->isSimpleChild($child);
			if ($isSimpleChild)
			{
				if (!$pendingSimpleFragment)
				{
					$pendingSimpleFragment = CanvasFragment::create()
						->defineExitPort(PortRef::createFromNodeId($parentOutputName, 'o0'))
					;
					$pendingSimpleEntryName = $parentOutputName;
					$pendingSimpleEntryPoint = $parentOutputPoint;
				}

				$pendingSimpleFragment->appendActivity($child);

				continue;
			}

			if ($pendingSimpleFragment)
			{
				$childLayout = $this->convertSimpleFragment(
					$pendingSimpleFragment,
					$pendingSimpleEntryName,
					$pendingSimpleEntryPoint
				);
				$result->appendLayout($childLayout);
				$parentOutputName = $childLayout->exitId;
				$parentOutputPoint = $childLayout->findAnchor($childLayout->exitId);
				$pendingSimpleFragment = null;
				$pendingSimpleEntryName = null;
				$pendingSimpleEntryPoint = null;
			}

			$childLayout = $this->convertChild($parentOutputName, $parentOutputPoint, $child, false);
			$result->appendLayout($childLayout);
			$parentOutputName = $childLayout->exitId;
			$parentOutputPoint = $childLayout->findAnchor($childLayout->exitId);
		}

		if ($pendingSimpleFragment)
		{
			$childLayout = $this->convertSimpleFragment(
				$pendingSimpleFragment,
				$pendingSimpleEntryName,
				$pendingSimpleEntryPoint
			);
			$result->appendLayout($childLayout);
			$parentOutputName = $childLayout->exitId;
			$parentOutputPoint = $childLayout->findAnchor($childLayout->exitId);
		}

		$result->exitId = $parentOutputName;

		return $result;
	}

	protected function convertChild(
		string $outputName,
		Layout\GridPoint $outputPoint,
		array $child,
		?bool $isSimpleChild = null
	): Layout\LayoutResult
	{
		/*
		 * RequestInformationActivity is not a real CompositeActivity.
		 * It extends CBPCompositeActivity only for its child – RequestInformationOptionalActivity.
		 */

		$isSimpleChild ??= $this->isSimpleChild($child);
		if ($isSimpleChild)
		{
			return $this->convertSimpleChild($outputName, $outputPoint, $child);
		}

		$this->syncActivatedState($child);

		switch ($child['Type'])
		{
			case 'EmptyBlockActivity':
				return $this->convertEmptyBlockActivity($outputName, $outputPoint, $child);
			case 'WhileActivity':
			case 'ForEachActivity':
				return $this->convertIterableActivity($outputName, $outputPoint, $child);
			case 'IfElseActivity':
			case 'ParallelActivity':
			case 'ListenActivity':
				return $this->convertBranchableActivity($outputName, $outputPoint, $child);
			case 'ApproveActivity':
			case 'RequestInformationOptionalActivity':
				return $this->convertYesNoActivity($outputName, $outputPoint, $child);
		}

		throw new \CBPArgumentException("Unsupported child type $child[Type]");
	}

	protected function isSimpleChild(array $child): bool
	{
		\CBPActivity::includeActivityFile($child['Type']);
		$instance = \CBPActivity::createInstance($child['Type'], $child['Name']);

		return
			$child['Type'] === 'RequestInformationActivity'
			|| !($instance instanceof \CBPCompositeActivity)
		;
	}

	private function convertSimpleChild(string $outputName, Layout\GridPoint $outputPoint, array $child): Layout\LayoutResult
	{
		$fragment = CanvasFragment::create()
			->defineExitPort(PortRef::createFromNodeId($outputName, 'o0'))
			->appendActivity($child)
		;

		return $this->convertSimpleFragment($fragment, $outputName, $outputPoint);
	}

	private function convertSimpleFragment(
		CanvasFragment $fragment,
		string $outputName,
		Layout\GridPoint $outputPoint
	): Layout\LayoutResult
	{
		return $this->getCanvasLayoutAdapter()->convertLinearFragmentToLayout($fragment, $outputName, $outputPoint);
	}

	protected function getCanvasLayoutAdapter(): CanvasLayoutAdapter
	{
		$this->canvasLayoutAdapter ??= new CanvasLayoutAdapter();

		return $this->canvasLayoutAdapter;
	}

	private function buildEmptyBlockFragment(array $activity): CanvasFragment
	{
		$children = $activity['Children'][0]['Children'] ?? null;

		if (empty($children))
		{
			return CanvasFragment::create();
		}

		$fragment = $this->buildChildrenFragment($children);
		$frame = CanvasFrame::create(
			$activity['Name'],
			$activity['Properties']['Title'] ?? null
		);
		$frame->wrapNodes($fragment->findNodeIds());
		$fragment->addFrame($frame);

		return $fragment;
	}

	private function buildIterableFragment(array $activity): CanvasFragment
	{
		$children = $activity['Children'][0]['Children'] ?? [];
		unset($activity['Children']);

		$activityNode = $this->createCanvasNode($activity);
		$branch = CanvasBranch::createForNode($activity['Name'])
			->addPath('o0', $this->buildChildrenFragment($children))
		;

		return CanvasFragment::create()
			->addNode($activityNode)
			->addBranch($branch)
			->defineExitPort($activityNode->createOutputPortRef('o1'))
		;
	}

	private function buildIfElseFragment(array $activity): CanvasFragment
	{
		$branches = $activity['Children'] ?? [];
		$fragment = CanvasFragment::create();
		$mergeNode = $this->createCanvasNode($this->createMergeNode());
		$rootContainer = CanvasContainer::create($activity['Name'] . '_ifelse')
			->arrangeHorizontally()
			->defineColumnGap(0)
		;

		foreach ($branches as $index => $branchActivity)
		{
			$branchChildren = $branchActivity['Children'] ?? [];
			unset($branchActivity['Children']);

			$branchNode = $this->createCanvasNode($branchActivity);
			$branch = CanvasBranch::createForNode($branchNode->findId())
				->defineMergeNode($mergeNode)
				->addPath('o0', $this->buildChildrenFragment($branchChildren))
			;

			$fragment->addNode($branchNode);
			$fragment->addBranch($branch);
			$rootContainer->appendContainer(
				CanvasContainer::create($branchNode->findId() . '_slot')
					->defineColumnOffset($index * static::COLUMN_GAP)
					->appendNode($branchNode)
			);
		}

		$fragment->addNode($mergeNode);
		$rootContainer->appendNode($mergeNode);
		$fragment->defineRootContainer($rootContainer);
		$fragment->defineExitPort($mergeNode->createOutputPortRef());

		return $fragment;
	}

	private function buildBranchableFragment(array $activity): CanvasFragment
	{
		$mergeFlow = $activity['Type'] === 'ParallelActivity';
		$branches = $activity['Children'] ?? [];

		$merge = $this->createMergeNode();
		$branch = CanvasBranch::createForNode($merge['Name'])
			->defineMergeNode($this->createCanvasNode($this->createMergeNode($mergeFlow)))
		;

		foreach ($branches as $i => $activityBranch)
		{
			$branchChildren = $activityBranch['Children'] ?? [];
			$branch->addPath("o{$i}", $this->buildChildrenFragment($branchChildren));
		}

		unset($activity['Children']);
		if ($mergeFlow)
		{
			$activity['Type'] = 'Merge';
		}

		$activityNode = $this->createCanvasNode($activity);
		$mergeNode = $this->createCanvasNode($merge);

		return CanvasFragment::create()
			->addNode($activityNode)
			->addNode($mergeNode)
			->addBranch($branch)
			->defineExitPort($branch->findMergeNode()->createOutputPortRef())
		;
	}

	private function buildYesNoFragment(array $activity): CanvasFragment
	{
		$yesBranch = $activity['Children'][0] ?? null;
		$noBranch = $activity['Children'][1] ?? null;
		unset($activity['Children']);

		$branch = CanvasBranch::createForNode($activity['Name'])
			->defineMergeNode($this->createCanvasNode($this->createMergeNode()))
			->addPath('o0', $this->buildChildrenFragment($yesBranch['Children'] ?? []))
			->addPath('o1', $this->buildChildrenFragment($noBranch['Children'] ?? []))
		;

		return CanvasFragment::create()
			->addNode($this->createCanvasNode($activity))
			->addBranch($branch)
			->defineExitPort($branch->findMergeNode()->createOutputPortRef())
		;
	}

	private function convertEmptyBlockActivity(string $outputName, Layout\GridPoint $outputPoint, array $activity): Layout\LayoutResult
	{
		$children = $activity['Children'][0]['Children'] ?? null;

		if (empty($children))
		{
			return Layout\LayoutResult::createFromAnchor($outputName, $outputPoint);
		}

		$innerLayout = $this->convertChildren($outputName, $outputPoint, $children);
		$visibleChildNames = array_map(
			static fn(array $child) => $child['Name'],
			array_filter(
				$innerLayout->children,
				static fn(array $child) => $child['Type'] !== 'Merge'
			)
		);
		$frameFragment = CanvasFragment::createFromActivities(
			array_filter(
				$innerLayout->children,
				static fn(array $child) => in_array($child['Name'], $visibleChildNames, true)
			)
		);
		$frame = $frameFragment->wrapInFrame(
			$activity['Name'],
			$activity['Properties']['Title'] ?? null
		);
		$layout = $this->getCanvasLayoutAdapter()->appendFrameToLayout($innerLayout, $frame);

		if (!empty($layout->nodeFrames[$activity['Name']]))
		{
			$layout->anchors[$activity['Name']] = new Layout\GridPoint(
				$layout->nodeFrames[$activity['Name']]->top,
				$layout->nodeFrames[$activity['Name']]->left
			);
		}

		return $layout;
	}

	private function convertIterableActivity(string $outputName, Layout\GridPoint $outputPoint, array $activity): Layout\LayoutResult
	{
		$children = $activity['Children'][0]['Children'] ?? null;
		$bodyInputId = $activity['Name'] . ':o0';
		$bodyInputPoint = $outputPoint->moveBy(2, 0);
		$bodyLayout = $this->convertChildren($bodyInputId, $bodyInputPoint, $children ?? []);
		$loopPortName = $activity['Type'] === 'WhileActivity' ? ':i0' : ':i1';

		unset($activity['Children']);

		return $this->getCanvasLayoutAdapter()->convertLoopNodeToLayout(
			$outputName,
			$outputPoint,
			CanvasNode::createFromActivity($activity),
			$bodyLayout,
			'o0',
			substr($loopPortName, 1),
			'o1'
		);
	}

	private function convertBranchableActivity(string $outputName, Layout\GridPoint $outputPoint, array $activity): Layout\LayoutResult
	{
		$mergeFlow = $activity['Type'] === 'ParallelActivity';
		$isListen = $activity['Type'] === 'ListenActivity';
		$listenChildren = [];
		$branches = $activity['Children'] ?? null;

		if (empty($branches))
		{
			return Layout\LayoutResult::createFromAnchor($outputName, $outputPoint);
		}

		$canvasBranch = CanvasBranch::createForNode($activity['Name']);
		$branchLayoutsByPortId = [];

		foreach ($branches as $i => $activityBranch)
		{
			$branchChildren = $activityBranch['Children'] ?? [];
			if ($isListen)
			{
				$listenChildren[] = array_shift($branchChildren);
			}
			$portId = "o{$i}";
			$canvasBranch->addPath($portId);
			$branchLayout = $this->convertChildren($activity['Name'] . ":{$portId}", new Layout\GridPoint(1, 0), $branchChildren);
			$branchLayoutsByPortId[$portId] = $branchLayout;
		}

		unset($activity['Children']);

		$merge = $this->createMergeNode($mergeFlow);

		if ($mergeFlow)
		{
			$activity['Type'] = 'Merge';
		}

		return $this->getCanvasLayoutAdapter()->convertBranchNodeToLayout(
			$outputName,
			$outputPoint,
			CanvasNode::createFromActivity($activity),
			$canvasBranch,
			$branchLayoutsByPortId,
			$merge,
			!$mergeFlow,
			$mergeFlow
		);
	}

	private function convertYesNoActivity(string $outputName, Layout\GridPoint $outputPoint, array $activity): Layout\LayoutResult
	{
		$yesBranch = $activity['Children'][0] ?? null;
		$noBranch = $activity['Children'][1] ?? null;
		unset($activity['Children']);
		$canvasBranch = CanvasBranch::createForNode($activity['Name']);
		$branchLayoutsByPortId = [];

		foreach ([$yesBranch, $noBranch] as $i => $activityBranch)
		{
			$portId = "o{$i}";
			$canvasBranch->addPath($portId);
			$branchLayoutsByPortId[$portId] = $this->convertChildren(
				$activity['Name'] . ":{$portId}",
				new Layout\GridPoint(1, 0),
				$activityBranch['Children'] ?? []
			);
		}

		$merge = $this->createMergeNode();

		return $this->getCanvasLayoutAdapter()->convertBranchNodeToLayout(
			$outputName,
			$outputPoint,
			CanvasNode::createFromActivity($activity),
			$canvasBranch,
			$branchLayoutsByPortId,
			$merge
		);
	}
}
