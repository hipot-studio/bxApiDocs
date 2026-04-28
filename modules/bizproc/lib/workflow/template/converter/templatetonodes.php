<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\NodePorts;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\DI\ServiceLocator;

final class TemplateToNodes
{
	public function __construct(
		private readonly array $template
	)
	{}

	public function convert(): array
	{
		$template = $this->convertTemplate($this->template);
		$root = $template[0];
		$blocks = $this->createBlocks($root[NodesToTemplate::ELEMENT_CHILDREN], null);
		$connections = $this->createConnections(
			$root[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS]
		);

		return [$blocks, $connections];
	}

	private function convertTemplate(array $template): array
	{
		$type = $template[0]['Type'];

		if ($type === NodesToTemplate::ROOT_NODE_TYPE)
		{
			return $template;
		}

		if ($type === 'SequentialWorkflowActivity')
		{
			return (new SequentialToNodeWorkflow($template))
				->setStartTrigger('ManualStartTrigger')
				->convert()
			;
		}

		if ($type === 'StateMachineWorkflowActivity')
		{
			return (new StateMachineToNodeWorkflow($template))->convert();
		}

		return [];
	}


	/**
	 * @param array $activities
	 * @return array|array[]
	 */
	private function createBlocks(array $activities, ?array $documentType): array
	{
		/** @var Searcher $searcher */
		$searcher = ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');

		$defaultActivities =
			$searcher->searchByType(
				[ActivityType::NODE->value, ActivityType::TRIGGER->value],
				$documentType
			)
				->filter(static fn(ActivityDescription $description) => !$description->getExcluded())
				->sort()
		;

		$iconMap = [];
		/* @var ActivityDescription $activity*/
		foreach ($defaultActivities as $activity)
		{
			$class  = $activity->getClass();
			if (!$class)
			{
				continue;
			}

			if ($activity->getPresets())
			{
				foreach ($activity->getPresets() as $preset)
				{
					$presetActivity = $activity->applyPreset($preset);
					$id = $class . '_' .  $preset['ID'];
					$iconMap[$id] = [
						'CODE' => $presetActivity->getIcon(),
						'COLOR' => $presetActivity->getColorIndex(),
					];
				}
			}
			else
			{
				$iconMap[$class] = [
					'CODE' => $activity->getIcon(),
					'COLOR' => $activity->getColorIndex(),
				];
			}
		}

		return array_map(
			static function ($child) use ($iconMap) {
				$node = $child['Node'];
				unset($child['Node']);

				$nodeType = $node['type'] ?? 'simple';
				if (str_ends_with($child['Type'], 'Trigger'))
				{
					$nodeType = 'trigger';
				}

				$activityType = $child['Type'] ?? null;
				if (isset($child['PresetId']))
				{
					$activityType .= '_' . $child['PresetId'];
				}

				$icon = $activityType && isset($iconMap[$activityType]['CODE']) ? $iconMap[$activityType]['CODE'] : null;
				$color = $activityType && isset($iconMap[$activityType]['COLOR']) ? $iconMap[$activityType]['COLOR'] : null;

				return [
					'id' => $node['id'],
					'type' => $nodeType,
					'position' => $node['position'],
					'dimensions' => $node['dimensions'],
					'ports' => NodePorts::fromArray($node['ports'])->toArray(), // normalize ports structure
					'activity' => $child,
					'node' => [
						'type' => $nodeType,
						'title' => $node['node']['title'],
						'colorIndex' => $color,
						'frameColorName' => $node['node']['frameColorName'] ?? null,
						'icon' => $icon,
						'updated' => $node['node']['updated'] ?? null,
						'published' => $node['node']['published'] ?? null,
					],
				];
			},
			$this->transformBlockActivities($activities)
		);
	}

	/**
	 * @param mixed $links
	 * @return array|array[]
	 */
	private function createConnections(mixed $links): array
	{
		return array_map(
			static function (array $link) {
				[$sourceBlockId, $targetBlockId, $createdAt] = array_pad($link, 3, null);

				$sourcePortId = 'o0';
				$targetPortId = 'i0';

				if (str_contains($sourceBlockId, ':'))
				{
					[$sourceBlockId, $sourcePortId] = explode(':', $sourceBlockId);
				}

				if (str_contains($targetBlockId, ':'))
				{
					[$targetBlockId, $targetPortId] = explode(':', $targetBlockId);
				}

				$type = null;
				if ($sourcePortId[0] === 'a' && $targetPortId[0] === 't')
				{
					$type = 'aux';
				}

				return [
					'id' => "{$sourceBlockId}_{$targetBlockId}_{$sourcePortId}_{$targetPortId}",
					'sourceBlockId' => $sourceBlockId,
					'sourcePortId' => $sourcePortId,
					'targetBlockId' => $targetBlockId,
					'targetPortId' => $targetPortId,
					'type' => $type,
					'createdAt' => $createdAt,
				];
			},
			$links,
		);
	}

	private function transformBlockActivities(array $activities): array
	{
		foreach ($activities as &$activity)
		{
			$activity['ReturnProperties'] = $this->getActivityReturnProperties($activity);
		}

		return $activities;
	}

	private function getActivityReturnProperties(array|string $activityOrCode): array
	{
		$props = \CBPRuntime::getRuntime()->getActivityReturnProperties($activityOrCode);
		foreach ($props as $id => &$prop)
		{
			$prop['Id'] = $id;
		}

		return array_values($props);
	}
}