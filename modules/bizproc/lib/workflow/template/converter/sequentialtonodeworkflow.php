<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Internal\Helper\Activity\ActivityHelper;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Error;
use CBPDocumentEventType;

final class SequentialToNodeWorkflow
{
	private const MANUAL_START_TRIGGER = 'ManualStartTrigger';
	private const EDIT_DOCUMENT_TRIGGER = 'EditDocumentTrigger';
	private const CREATE_DOCUMENT_TRIGGER = 'CreateDocumentTrigger';
	private array $rootActivity;
	private bool $isSystem = false;
	private int $autoExecute = \CBPDocumentEventType::None;
	private array $positions = [];
	private ?string $startTrigger = null;

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
		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(['TEMPLATE', 'IS_SYSTEM', 'AUTO_EXECUTE'])
			->exec()
			->fetch()
		;

		if (!$row)
		{
			throw new \CBPArgumentException("Template ID $templateId does not exist");
		}

		$instance = new static($row['TEMPLATE']);
		$instance->setIsSystem($row['IS_SYSTEM'] === 'Y');
		$instance->setAutoExecute((int)$row['AUTO_EXECUTE']);

		return $instance;
	}

	public static function migrateTemplate(int $templateId): Result
	{
		$result = new Result();
		try
		{
			$instance = static::makeByTemplateId($templateId);
			$template = $instance->convert();

			\CBPWorkflowTemplateLoader::update($templateId, ['TEMPLATE' => $template], true);
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

	public function convert(): array
	{
		[$startName, $startLinks, $startChildren] = $this->createTriggers();

		[$outputName, $rootLinks, $rootChildren] = $this->convertChildren($startName, $this->rootActivity['Children']);
		[$links, $children] = $this->optimizeChildren(
			[...$startLinks, ...$rootLinks],
			[...$startChildren, ...$rootChildren]
		);

		return [$this->createRootActivity($links, $children)];
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

	private function createTriggers(): array
	{
		$merge = $this->createMergeNode();
		$this->setPosition($merge['Name'], 5, 1);

		$children = [$merge];
		$links = [];

		$triggers = [];

		if (is_null($this->startTrigger))
		{
			if ($this->autoExecute & CBPDocumentEventType::Create)
			{
				$triggers[] = self::CREATE_DOCUMENT_TRIGGER;
			}
			if ($this->autoExecute & CBPDocumentEventType::Edit)
			{
				$triggers[] = self::EDIT_DOCUMENT_TRIGGER;
			}
		}
		else
		{
			$triggers[] = $this->startTrigger;
		}


		foreach ($triggers as $triggerType)
		{
			$trigger = $this->createTrigger($triggerType);
			$children[] = $trigger;
			$links[] = $this->createLink($trigger['Name'], $merge['Name']);

			$this->copyPosition($merge['Name'], $trigger['Name']);
			$this->movePositionDown($merge['Name']);
		}

		$this->movePositionRight($merge['Name']);

		return [$merge['Name'], $links, $children];
	}

	private function createTrigger(string $type): array
	{
		$activityDescription = \CBPRuntime::getRuntime()->getActivityDescription($type);

		$name = ActivityHelper::generateName();

		$title = $activityDescription['NAME'] ?? null;
		$icon = $activityDescription['NODE_ICON'] ?? null;
		$colorIndex = $activityDescription['COLOR_INDEX'] ?? null;

		return [
			'Type' => $type,
			'Name' => $name,
			'Properties' => [
				'Title' => $title,
				'Icon' => $icon,
				'ColorIndex' => $colorIndex,
			],
		];
	}

	private function optimizeChildren(array $links, array $children): array
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
					if (str_starts_with($outputName, $mergeName))
					{
						$inputNames[] = $inputName;
						unset($links[$j]);
					}
					if (str_starts_with($inputName, $mergeName))
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

	private function createRootActivity(array $links, array $children): array
	{
		foreach ($children as &$child)
		{
			$child['Node'] = $this->makeNodeSettings($child);
		}
		unset($child);

		return NodesToTemplate::createNodeRootActivity($links, $children);
	}

	private function makeNodeSettings(array $activity): array
	{
		$row = $this->getRow($activity['Name']);
		$column = $this->getColumn($activity['Name']);

		$ports = [
			'input' => [],
			'output' => [],
		];
		if (!str_ends_with($activity['Type'], 'Trigger'))
		{
			$ports['input'][] = [
				'id' => 'i0',
				'position' => 1,
			];
		}

		if ($activity['Type'] === 'WhileActivity' || $activity['Type'] === 'ForEachActivity')
		{
			array_push(
				$ports['output'],
				[
					'id' => 'o0',
					'position' => 1,
					'title' => 'выход',
				],
				[
					'id' => 'o1',
					'position' => 0,
					'title' => 'итерация',
				],
			);
		}
		elseif ($activity['Type'] === 'IfElseActivity')
		{
			foreach ($activity['Properties']['Conditions'] as $i => $condition)
			{
				$ports['output'][] = [
					'id' => "o{$i}",
					'position' => $i,
					'title' => $condition['Title'],
				];
			}
		}
		elseif ($activity['Type'] === 'ApproveActivity' || $activity['Type'] === 'RequestInformationOptionalActivity')
		{
			$titleYes = $activity['Type'] === 'ApproveActivity' ? 'Да' : 'Ок';
			$titleNo = $activity['Type'] === 'ApproveActivity' ? 'Нет' : 'Отмена';
			array_push(
				$ports['output'],
				[
					'id' => 'o0',
					'position' => 0,
					'title' => $titleYes,
				],
				[
					'id' => 'o1',
					'position' => 1,
					'title' => $titleNo,
				]
			);
		}
		else
		{
			$ports['output'][] = [
				'id' => 'o0',
				'position' => 1,
			];
		}

		return [
			'id' => $activity['Name'],
			'position' => [
				'x' => $column * 500,
				'y' => $row * 70,
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
	}
	private function setPosition(string $name, ?float $row = null, ?float $column = null): void
	{
		$this->positions[$name] ??= [0, 0];
		if (isset($row))
		{
			$this->positions[$name][0] = $row;
		}
		if (isset($column))
		{
			$this->positions[$name][1] = $column;
		}
	}

	private function setChildPositionNextColumn(string $parentName, string $childName): void
	{
		$this->setPosition($childName, $this->getRow($parentName), $this->getColumn($parentName) + 1);
	}

	private function setChildPositionNextRow(string $parentName, string $childName): void
	{
		$this->setPosition($childName, $this->getRow($parentName) + 1, $this->getColumn($parentName));
	}

	private function movePositionDown(string $name): void
	{
		$this->setPosition($name, row: $this->getRow($name) + 1);
	}

	private function movePositionRight(string $name): void
	{
		$this->setPosition($name, column: $this->getColumn($name) + 1);
	}

	private function copyPosition(string $parentName, string $childName): void
	{
		$this->setPosition($childName, $this->getRow($parentName), $this->getColumn($parentName));
	}

	private function getTopRightPosition(array $parentNames): array
	{
		$pos = array_intersect_key($this->positions, array_flip($parentNames));

		return [min(array_column($pos, 0)), max(array_column($pos, 1))];
	}

	private function getBottomLeftPosition(array $parentNames): array
	{
		$pos = array_intersect_key($this->positions, array_flip($parentNames));

		return [max(array_column($pos, 0)), min(array_column($pos, 1))];
	}

	private function getRow(string $name): int
	{
		return $this->positions[$name][0] ?? 0;
	}

	private function getColumn(string $name): int
	{
		return $this->positions[$name][1] ?? 0;
	}

	private function createLink(string $outputName, string $inputName): array
	{
		return [$outputName, $inputName];
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

	private function createMergeNode($mergeFlow = false): array
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

	private function convertChildren(string $outputName, array $children): array
	{
		$newLinks = [];
		$newChildren = [];

		$parentOutputName = $outputName;
		foreach ($children as $child)
		{
			[$childOutputName, $links, $children] = $this->convertChild($parentOutputName, $child);
			$parentOutputName = $childOutputName;

			if ($links)
			{
				array_push($newLinks, ...$links);
			}
			if ($children)
			{
				array_push($newChildren, ...$children);
			}
		}

		return [$parentOutputName, $newLinks, $newChildren];
	}

	private function convertChild(string $outputName, array $child): array
	{
		\CBPActivity::includeActivityFile($child['Type']);
		$instance = \CBPActivity::createInstance($child['Type'], $child['Name']);

		/*
		 * RequestInformationActivity is not a real CompositeActivity.
		 * It extends CBPCompositeActivity only for its child – RequestInformationOptionalActivity.
		 */

		if (
			$child['Type'] === 'RequestInformationActivity'
			|| !($instance instanceof \CBPCompositeActivity))
		{
			$this->setChildPositionNextRow($outputName, $child['Name']);

			return [
				$child['Name'],
				[$this->createLink($outputName, $child['Name'])],
				[$child],
			];
		}

		$this->syncActivatedState($child);

		switch ($child['Type'])
		{
			case 'EmptyBlockActivity':
				return $this->convertEmptyBlockActivity($outputName, $child);
			case 'WhileActivity':
			case 'ForEachActivity':
				return $this->convertIterableActivity($outputName, $child);
			case 'IfElseActivity':
			case 'ParallelActivity':
			case 'ListenActivity':
				return $this->convertBranchableActivity($outputName, $child);
			case 'ApproveActivity':
			case 'RequestInformationOptionalActivity':
				return $this->convertYesNoActivity($outputName, $child);
		}

		throw new \CBPArgumentException("Unsupported child type $child[Type]");
	}

	private function convertEmptyBlockActivity(string $outputName, array $activity): array
	{
		$children = $activity['Children'][0]['Children'] ?? null;

		if (empty($children))
		{
			return [$outputName, [], []];
		}

		return $this->convertChildren($outputName, $children);
	}

	private function convertIterableActivity(string $outputName, array $activity): array
	{
		$children = $activity['Children'][0]['Children'] ?? null;

		$this->setChildPositionNextRow($outputName, $activity['Name']);
		$this->copyPosition($activity['Name'], $activity['Name'] . ':o0');
		$this->setChildPositionNextRow($activity['Name'] . ':o0', $activity['Name'] . ':o0');

		[$childOutputName, $links, $children] = $this->convertChildren($activity['Name'] . ':o0', $children);

		// link parent
		$links[] = $this->createLink($outputName, $activity['Name']);
		// link true-loop
		$links[] = $this->createLink($childOutputName, $activity['Name']);

		unset($activity['Children']);

		$this->setPosition(
			$activity['Name'] . ':o1',
			...$this->getBottomLeftPosition([$activity['Name'], ...array_column($children, 'Name')])
		);

		$this->setPosition(
			$activity['Name'] . ':o1',
			column: $this->getColumn($activity['Name']),
		);

		return [$activity['Name'] . ':o1', $links, [$activity, ...$children]];
	}

	private function convertBranchableActivity(string $outputName, array $activity): array
	{
		$mergeFlow = $activity['Type'] === 'ParallelActivity';
		$singlePort = $activity['Type'] === 'ListenActivity';
		$branches = $activity['Children'] ?? null;

		if (empty($branches))
		{
			return [$outputName, [], []];
		}

		if ($mergeFlow)
		{
			$this->copyPosition($outputName, $activity['Name']);
		}
		else
		{
			$this->setChildPositionNextRow($outputName, $activity['Name']);
		}

		$parentOutputName = $activity['Name'];
		$allChildren = [];
		$allLinks = [$this->createLink($outputName, $parentOutputName)];
		$tails = [];
		$rows = 0;

		$cols = count($branches) + $this->countChildrenRows($branches);
		$firstColPos = ($cols) / 2;

		foreach ($branches as $i => $branch)
		{
			$branchPortId = $parentOutputName . ($singlePort ? '' : ":o{$i}");
			$this->setChildPositionNextRow($parentOutputName, $branchPortId);
			if ($i === 0)
			{
				$this->setPosition(
					$branchPortId,
					column: $this->getColumn($parentOutputName) - $firstColPos
				);
			}

			if ($rows > 0)
			{
				$this->setPosition(
					$branchPortId,
					column: $this->getColumn($branchPortId) + $rows
				);
			}
			$rows += $this->countChildrenRows($branch['Children'] ?? []) + 1;

			[$childOutputName, $links, $children] = $this->convertChildren($branchPortId, $branch['Children'] ?? []);

			array_push($allChildren, ...$children);
			array_push($allLinks, ...$links);

			$tails[] = $childOutputName;
		}

		if ($activity['Type'] === 'IfElseActivity')
		{
			$activity['Properties']['Conditions'] = array_column($branches, 'Properties');
		}
		unset($activity['Children']);

		$merge = $this->createMergeNode($mergeFlow);
		$this->setPosition($merge['Name'], ...$this->getBottomLeftPosition([$activity['Name'], ...$tails]));
		$this->setPosition(
			$merge['Name'],
			column: $this->getColumn($activity['Name'])
		);
		if ($mergeFlow)
		{
			$this->setChildPositionNextRow($merge['Name'], $merge['Name']);
		}

		$allChildren[] = $merge;
		array_push($allLinks, ...$this->createLinks($tails, [$merge['Name']]));

		if ($mergeFlow)
		{
			$activity['Type'] = 'Merge';
		}
		if ($singlePort)
		{
			$this->setPosition(
				$activity['Name'],
				row: $this->getRow($outputName),
			);
		}

		return [$merge['Name'], $allLinks, [$activity, ...$allChildren]];
	}

	private function countChildrenRows(array $children): int
	{
		$rows = 0;

		foreach ($children as $child)
		{
			if ($child['Type'] === 'ApproveActivity' || $child['Type'] === 'RequestInformationOptionalActivity')
			{
				$rows = 1;
			}
			elseif ($child['Type'] === 'IfElseActivity' || $child['Type'] === 'ListenActivity')
			{
				$rows = count($child['Children']) - 1;
			}
			elseif ($child['Type'] === 'ParallelActivity')
			{
				$rows = count(array_filter($child['Children'], fn($c) => !empty($c['Children']))) - 1;
			}

			if (!empty($child['Children']))
			{
				$rows += $this->countChildrenRows($child['Children']);
			}
		}

		return $rows;
	}

	private function convertYesNoActivity(string $outputName, array $activity): array
	{
		$yesBranch = $activity['Children'][0] ?? null;
		$noBranch = $activity['Children'][1] ?? null;
		$cols = count($activity['Children']) + $this->countChildrenRows($activity['Children']);
		$firstColPos = ($cols) / 2;

		unset($activity['Children']);

		$allChildren = [];
		$allLinks = [$this->createLink($outputName, $activity['Name'])];
		$tails = [];

		$this->setChildPositionNextRow($outputName, $activity['Name']);
		$rows = 0;
		$branches = [$yesBranch, $noBranch];

		foreach ($branches as $i => $branch)
		{
			$branchPortId = $activity['Name'] . ":o{$i}";
			$this->setChildPositionNextRow($activity['Name'], $branchPortId);
			if ($i === 0)
			{
				$this->setPosition(
					$branchPortId,
					column: $this->getColumn($activity['Name']) - $firstColPos
				);
			}

			if ($rows > 0)
			{
				$this->setPosition(
					$branchPortId,
					column: $this->getColumn($branchPortId) + $rows
				);
			}
			$rows += $this->countChildrenRows($branch['Children'] ?? []) + 1;

			[$childOutputName, $links, $children] = $this->convertChildren($branchPortId, $branch['Children'] ?? []);

			array_push($allChildren, ...$children);
			array_push($allLinks, ...$links);

			$tails[] = $childOutputName;
		}

		$merge = $this->createMergeNode();
		$this->setPosition($merge['Name'], ...$this->getBottomLeftPosition([$activity['Name'], ...$tails]));
		$this->setPosition(
			$merge['Name'],
			column: $this->getColumn($activity['Name'])
		);

		$allChildren[] = $merge;
		array_push($allLinks, ...$this->createLinks($tails, [$merge['Name']]));

		return [$merge['Name'], $allLinks, [$activity, ...$allChildren]];
	}
}
