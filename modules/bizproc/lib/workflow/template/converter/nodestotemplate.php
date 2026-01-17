<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

final class NodesToTemplate
{
	public const ROOT_NODE_TYPE = 'NodeWorkflowActivity';
	public const ROOT_NODE_NAME = 'Template';
	public const ELEMENT_TYPE = 'Type';
	public const ELEMENT_NAME = 'Name';
	public const ELEMENT_PROPERTIES = 'Properties';
	public const ELEMENT_CHILDREN = 'Children';
	public const PROPERTY_TITLE = 'Title';
	public const PROPERTY_LINKS = 'Links';
	public const LINK_DELIMITER = ':';
	private array $blocks;
	private array $connections;

	public static function createNodeRootActivity(array $links, array $children): array
	{
		$title = \CBPRuntime::getRuntime()->getActivityDescription(self::ROOT_NODE_TYPE)['NAME'] ?? null;

		return [
			self::ELEMENT_TYPE => self::ROOT_NODE_TYPE,
			self::ELEMENT_NAME => self::ROOT_NODE_NAME,
			self::ELEMENT_PROPERTIES => [
				self::PROPERTY_TITLE => $title,
				self::PROPERTY_LINKS => $links,
			],
			self::ELEMENT_CHILDREN => $children,
		];
	}

	public function __construct(array $blocks, array $connections)
	{
		$this->blocks = $blocks;
		$this->connections = $connections;
	}

	public function convert(): array
	{
		$children = array_values(array_filter(array_map(
			fn($child) => $this->convertNodeToActivity($child),
			$this->blocks,
		)));
		$links = $this->createLinks($this->connections);

		return [self::createNodeRootActivity($links, $children)];
	}

	private function convertNodeToActivity(array $node): ?array
	{
		if (!isset($node['activity']))
		{
			return null;
		}
		$activity = $node['activity'];
		unset(
			$node['activity'],
			$node['content'],
			$node['updated'],
			$node['published'],
			$activity['ReturnProperties']
		);
		$activity['Node'] = $node;

		//Todo: tempo
		if ($node['type'] === 'frame')
		{
			$activity['Type'] = 'EmptyBlockActivity';
		}

		return $activity;
	}

	private function createLinks(array $connections): array
	{
		return array_map(
			static function($connection)
			{
				return [
					$connection['sourceBlockId'] . self::LINK_DELIMITER . $connection['sourcePortId'],
					$connection['targetBlockId'] . self::LINK_DELIMITER . $connection['targetPortId'],
				];
			},
			$connections,
		);
	}
}
