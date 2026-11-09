<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\Main\Web\Json;

final class DashboardLayout
{
	public const MAX_NODES = 1000;
	public const MAX_DEPTH = 10;
	private const LEAF_TYPES = ['CHART', 'HEADER', 'MARKDOWN', 'DIVIDER'];
	private ?array $parentIndex = null;

	private function __construct(private array $positions)
	{
	}

	public static function fromPositions(array $positions): self
	{
		if ($positions === [])
		{
			$positions = [
				'DASHBOARD_VERSION_KEY' => 'v2',
				'ROOT_ID' => [
					'id' => 'ROOT_ID',
					'type' => 'ROOT',
					'children' => ['GRID_ID'],
					'parents' => [],
				],
				'GRID_ID' => [
					'id' => 'GRID_ID',
					'type' => 'GRID',
					'children' => [],
					'parents' => ['ROOT_ID'],
				],
			];
		}

		return new self($positions);
	}

	/** @return list<array{limit: string, value: int}> */
	public function limitProblems(): array
	{
		$count = count($this->positions) - (array_key_exists('DASHBOARD_VERSION_KEY', $this->positions) ? 1 : 0);
		if ($count > self::MAX_NODES)
		{
			return [
				[
					'limit' => 'nodes',
					'value' => $count,
				],
			];
		}
		$nodes = $this->nodes();
		$depths = [];
		$stack = [['ROOT_ID', 0, []]];
		while ($stack !== [])
		{
			[$id, $depth, $path] = array_pop($stack);
			if (isset($path[$id]) || !isset($nodes[$id]))
			{
				continue;
			}
			if ($depth > self::MAX_DEPTH)
			{
				return [
					[
						'limit' => 'depth',
						'value' => $depth,
					],
				];
			}
			if (isset($depths[$id]) && $depths[$id] >= $depth)
			{
				continue;
			}
			$depths[$id] = $depth;
			$path[$id] = true;
			foreach ($this->children($id) as $child)
			{
				$stack[] = [$child, $depth + 1, $path];
			}
		}

		return [];
	}

	/** @return list<array{code: string, componentId: string}> */
	public function structuralProblems(): array
	{
		$this->checkLimits();
		$problems = [];
		if (($this->positions['DASHBOARD_VERSION_KEY'] ?? null) !== 'v2')
		{
			$problems[] = $this->problem('version', 'DASHBOARD_VERSION_KEY');
		}
		$nodes = $this->nodes();
		$standInGrid = $this->isDetachedGridStandIn();
		foreach (['ROOT_ID', 'GRID_ID'] as $id)
		{
			if (!isset($nodes[$id]))
			{
				$problems[] = $this->problem('missing_node', $id);
			}
		}
		foreach ($this->positions as $id => $node)
		{
			if ($id === 'DASHBOARD_VERSION_KEY')
			{
				continue;
			}
			$id = (string)$id;
			if (!is_array($node))
			{
				$problems[] = $this->problem('invalid_node', $id);

				continue;
			}
			if (($node['id'] ?? null) !== $id)
			{
				$problems[] = $this->problem('id_mismatch', $id);
			}
			$children = array_key_exists('children', $node) ? $node['children'] : [];
			if (!is_array($children) || !array_is_list($children))
			{
				$problems[] = $this->problem('children_not_list', $id);
			}
			else
			{
				$seen = [];
				foreach ($children as $child)
				{
					if (!is_string($child) || !isset($nodes[$child]))
					{
						$problems[] = $this->problem('unknown_child', $id);
					}
					if (is_string($child) && isset($seen[$child]))
					{
						$problems[] = $this->problem('duplicate_child', $id);
					}
					if (is_string($child))
					{
						$seen[$child] = true;
					}
				}
			}
			if (
				!in_array($id, ['ROOT_ID', 'HEADER_ID'], true)
				&& !($id === 'GRID_ID' && $standInGrid)
				&& count($this->owners()[$id] ?? []) !== 1
			)
			{
				$problems[] = $this->problem('parent_count', $id);
			}
			$type = $node['type'] ?? null;
			if (in_array($type, self::LEAF_TYPES, true) && $children !== [])
			{
				$problems[] = $this->problem('leaf_children', $id);
			}
			$chartId = $node['meta']['chartId'] ?? null;
			if ($type === 'CHART' && (!is_int($chartId) || $chartId <= 0))
			{
				$problems[] = $this->problem('chart_id', $id);
			}
		}
		$visited = [];
		$active = [];
		$this->walk('ROOT_ID', $visited, $active, $problems);
		$reachable = $visited;
		foreach (array_keys($nodes) as $id)
		{
			if ($id !== 'HEADER_ID' && !($id === 'GRID_ID' && $standInGrid) && !isset($reachable[$id]))
			{
				$problems[] = $this->problem('unreachable', (string)$id);
			}
			$this->walk((string)$id, $visited, $active, $problems);
		}

		return $this->uniqueProblems($problems);
	}

	/** @return list<string> Stable identities used to detect new problems after each operation. */
	public function problems(): array
	{
		return array_map(static fn(array $problem): string => $problem['code'] . ':' . $problem['componentId'],
			$this->warningProblems());
	}

	/** @return list<array{code: string, componentId: string}> */
	public function warningProblems(): array
	{
		$problems = $this->structuralProblems();
		$standInGrid = $this->isDetachedGridStandIn();
		foreach ($this->nodes() as $id => $node)
		{
			if (
				!in_array($id, ['ROOT_ID', 'HEADER_ID'], true)
				&& ($node['parents'] ?? []) !== $this->ancestors($id)
				&& !($id === 'GRID_ID' && $standInGrid && ($node['parents'] ?? []) === ['ROOT_ID'])
			)
			{
				$problems[] = $this->problem('parent_path', $id);
			}
			if (($node['type'] ?? null) === 'ROW' && $this->children($id) === [])
			{
				$problems[] = $this->problem('empty_row', $id);
			}
			$text = $node['meta']['text'] ?? null;
			if (($node['type'] ?? null) === 'HEADER' && $id !== 'HEADER_ID' && (!is_string($text) || trim($text) === ''))
			{
				$problems[] = $this->problem('empty_header', $id);
			}
		}

		return $this->uniqueProblems($problems);
	}

	/**
	 * @param array<string, array{width?: int, height?: int}> $packageSizes Final values explicitly set by the batch.
	 * @param list<string> $grownRows Rows whose width sum increased during the batch.
	 * @return list<array{code: string, componentId: string}>
	 */
	public function sizeProblems(array $packageSizes, array $grownRows): array
	{
		$problems = [];
		foreach ($packageSizes as $id => $sizes)
		{
			foreach ([
				'width' => [1, 12],
				'height' => [10, 200],
			] as $key => [$min, $max])
			{
				if (array_key_exists($key, $sizes) && (!is_int($sizes[$key]) || $sizes[$key] < $min || $sizes[$key] > $max))
				{
					$problems[] = $this->problem($key, $id);
				}
			}
		}
		$widths = $this->rowWidths();
		foreach (array_unique($grownRows) as $id)
		{
			if (($widths[$id] ?? 0) > 12)
			{
				$problems[] = $this->problem('row_overflow', $id);
			}
		}

		return $problems;
	}

	public function version(array $nativeFilters): string
	{
		$positions = $this->positions;
		foreach ($positions as &$node)
		{
			if (
				is_array($node)
				&& ($node['type'] ?? null) === 'CHART'
				&& is_array($node['meta'] ?? null)
			)
			{
				unset($node['meta']['uuid']);
			}
		}
		unset($node);
		$filters = [];
		foreach ($nativeFilters as $filter)
		{
			$scope = $filter['chartsInScope'] ?? [];
			sort($scope, SORT_NUMERIC);
			$filters[] = [
				'id' => $filter['id'] ?? '',
				'chartsInScope' => $scope,
			];
		}
		usort($filters, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

		return substr(hash('sha256', Json::encode(self::canonical([
			'positions' => $positions,
			'filters' => $filters,
		]))), 0, 16);
	}

	public function view(): array
	{
		$this->checkLimits();
		$view = [
			'charts' => [],
			'rows' => [],
			'tabs' => [],
			'headers' => [],
			'others' => [],
		];
		foreach ($this->orderedNodes() as $id => $node)
		{
			$id = (string)$id;
			$type = $node['type'] ?? '';
			if (in_array($id, ['ROOT_ID', 'GRID_ID', 'HEADER_ID'], true))
			{
				continue;
			}
			$parent = $this->parentOf($id);
			$base = [
				'componentId' => $id,
				'parentId' => $parent,
			];
			$meta = is_array($node['meta'] ?? null) ? $node['meta'] : [];
			$index = $parent === null ? null : array_search($id, $this->children($parent), true);
			switch ($type)
			{
				case 'CHART':
					$tabs = [];
					foreach ($this->ancestors($id) as $ancestor)
					{
						if (($this->positions[$ancestor]['type'] ?? null) === 'TAB')
						{
							$tabs[] = [
								'componentId' => $ancestor,
								'title' => $this->positions[$ancestor]['meta']['text'] ?? null,
							];
						}
					}
					$view['charts'][] = $base + [
						'chartId' => $meta['chartId'] ?? null,
						'title' => $meta['sliceNameOverride'] ?? $meta['sliceName'] ?? null,
						'width' => $meta['width'] ?? null,
						'height' => $meta['height'] ?? null,
						'index' => $index,
						'tabPath' => $tabs,
					];

					break;
				case 'ROW':
					$view['rows'][] = $base + ['index' => $index];

					break;
				case 'TAB':
					$view['tabs'][] = $base + ['title' => $meta['text'] ?? null];

					break;
				case 'HEADER':
					$view['headers'][] = $base + ['text' => $meta['text'] ?? null, 'headerSize' => $meta['headerSize'] ?? null];

					break;
				default:
					$view['others'][] = $base + ['type' => $type, 'width' => $meta['width'] ?? null];

					break;
			}
		}

		return $view;
	}

	public function toPositions(): array
	{
		return $this->positions;
	}

	public function nodes(): array
	{
		$nodes = $this->positions;
		unset($nodes['DASHBOARD_VERSION_KEY']);

		return array_filter($nodes, static fn(mixed $value): bool => is_array($value));
	}

	public function node(string $id): array
	{
		if (!isset($this->positions[$id]) || !is_array($this->positions[$id]))
		{
			throw new LayoutOperationError('node_not_found', 'Component ' . $id . ' does not exist.');
		}

		return $this->positions[$id];
	}

	public function setNode(string $id, array $node): void
	{
		if (($this->positions[$id]['children'] ?? []) !== ($node['children'] ?? []))
		{
			$this->parentIndex = null;
		}
		$this->positions[$id] = $node;
	}

	public function removeNode(string $id): void
	{
		unset($this->positions[$id]);
		$this->parentIndex = null;
	}

	public function children(string $id): array
	{
		$children = $this->positions[$id]['children'] ?? [];

		return is_array($children) ? array_values(array_filter($children, 'is_string')) : [];
	}

	public function parentOf(string $id): ?string
	{
		$owners = $this->owners()[$id] ?? [];

		return count($owners) === 1 ? $owners[0] : null;
	}

	public function getDefaultRowParent(): string
	{
		if ($this->isValidContainer('ROOT_ID', 'ROOT') && $this->children('ROOT_ID') === ['GRID_ID'])
		{
			return 'GRID_ID';
		}
		$tabs = $this->getTopLevelTabs();
		if ($tabs === null)
		{
			throw new LayoutOperationError('invalid_parent', 'The layout must have a GRID or valid top-level TAB.');
		}

		return $tabs[0];
	}

	public function isReachable(string $id): bool
	{
		$pending = ['ROOT_ID'];
		$seen = [];
		while ($pending !== [])
		{
			$current = array_pop($pending);
			if (isset($seen[$current]) || !is_array($this->positions[$current] ?? null))
			{
				continue;
			}
			if ($current === $id)
			{
				return true;
			}
			$seen[$current] = true;
			array_push($pending, ...$this->children($current));
		}

		return false;
	}

	/** @return list<string> The actual path from ROOT_ID to the immediate parent. */
	public function ancestors(string $id): array
	{
		$path = [];
		$seen = [$id => true];
		while (($parent = $this->parentOf($id)) !== null && !isset($seen[$parent]))
		{
			$path[] = $parent;
			$seen[$parent] = true;
			$id = $parent;
		}

		return array_reverse($path);
	}

	public function insertChild(string $parentId, string $id, ?int $index = null): void
	{
		$parent = $this->node($parentId);
		$children = $this->children($parentId);
		array_splice($children, max(0, min($index ?? count($children), count($children))), 0, [$id]);
		$parent['children'] = $children;
		$this->setNode($parentId, $parent);
	}

	public function detach(string $id): ?string
	{
		$parentId = $this->parentOf($id);
		if ($parentId !== null)
		{
			$parent = $this->node($parentId);
			$parent['children'] = array_values(array_filter(
				$this->children($parentId),
				static fn(string $child): bool => $child !== $id,
			));
			$this->setNode($parentId, $parent);
		}

		return $parentId;
	}

	public function repairSubtreeParents(string $id): void
	{
		$pending = [$id];
		$seen = [];
		while ($pending !== [])
		{
			$current = array_pop($pending);
			if (isset($seen[$current]))
			{
				continue;
			}
			$seen[$current] = true;
			$node = $this->node($current);
			if ($current !== 'ROOT_ID')
			{
				$node['parents'] = $this->ancestors($current);
				$this->setNode($current, $node);
			}
			array_push($pending, ...$this->children($current));
		}
	}

	public function cleanupEmptyRow(string $id): void
	{
		if (($this->positions[$id]['type'] ?? null) === 'ROW' && $this->children($id) === [])
		{
			$this->detach($id);
			$this->removeNode($id);
		}
	}

	/** @return array<string, int|float> */
	public function rowWidths(): array
	{
		$widths = [];
		foreach ($this->nodes() as $id => $node)
		{
			if (($node['type'] ?? null) !== 'ROW')
			{
				continue;
			}
			$widths[$id] = 0;
			foreach ($this->children($id) as $child)
			{
				$width = $this->positions[$child]['meta']['width'] ?? 0;
				if (is_int($width) || is_float($width))
				{
					$widths[$id] += $width;
				}
			}
		}

		return $widths;
	}

	public function resolveChart(array $op, string $prefix = ''): string
	{
		$componentKey = $prefix === '' ? 'componentId' : $prefix . 'ComponentId';
		$chartKey = $prefix === '' ? 'chartId' : $prefix . 'ChartId';
		if (isset($op[$componentKey]))
		{
			$id = $op[$componentKey];
			if (($this->node($id)['type'] ?? null) !== 'CHART')
			{
				throw new LayoutOperationError('chart_not_found', 'The component is not a chart.');
			}

			return $id;
		}
		$matches = [];
		foreach ($this->nodes() as $id => $node)
		{
			if (($node['type'] ?? null) === 'CHART' && ($node['meta']['chartId'] ?? null) === ($op[$chartKey] ?? null))
			{
				$matches[] = $id;
			}
		}
		if (count($matches) !== 1)
		{
			throw new LayoutOperationError('chart_not_found', 'The chart address must resolve to exactly one component.');
		}

		return $matches[0];
	}

	private function isDetachedGridStandIn(): bool
	{
		return $this->isValidContainer('GRID_ID', 'GRID')
			&& $this->children('GRID_ID') === []
			&& ($this->owners()['GRID_ID'] ?? []) === []
			&& $this->getTopLevelTabs() !== null;
	}

	/** @return list<string>|null */
	private function getTopLevelTabs(): ?array
	{
		if (
			!$this->isValidContainer('ROOT_ID', 'ROOT')
			|| count($this->children('ROOT_ID')) !== 1
			|| ($this->owners()['ROOT_ID'] ?? []) !== []
		)
		{
			return null;
		}
		$tabsId = $this->children('ROOT_ID')[0];
		if (!$this->isValidContainer($tabsId, 'TABS') || $this->parentOf($tabsId) !== 'ROOT_ID')
		{
			return null;
		}
		$tabs = $this->children($tabsId);
		if ($tabs === [])
		{
			return null;
		}
		foreach ($tabs as $id)
		{
			if (!$this->isValidContainer($id, 'TAB') || $this->parentOf($id) !== $tabsId)
			{
				return null;
			}
		}

		return $tabs;
	}

	private function isValidContainer(string $id, string $type): bool
	{
		$node = $this->positions[$id] ?? null;
		if (!is_array($node) || ($node['id'] ?? null) !== $id || ($node['type'] ?? null) !== $type)
		{
			return false;
		}
		$children = array_key_exists('children', $node) ? $node['children'] : [];
		if (!is_array($children) || !array_is_list($children))
		{
			return false;
		}
		$seen = [];
		foreach ($children as $child)
		{
			if (!is_string($child) || isset($seen[$child]) || !is_array($this->positions[$child] ?? null))
			{
				return false;
			}
			$seen[$child] = true;
		}

		return true;
	}

	private function orderedNodes(): array
	{
		$nodes = $this->nodes();
		$ordered = [];
		$pending = ['ROOT_ID'];
		while ($pending !== [])
		{
			$id = array_pop($pending);
			if (isset($ordered[$id]) || !isset($nodes[$id]))
			{
				continue;
			}
			$ordered[$id] = $nodes[$id];
			array_push($pending, ...array_reverse($this->children($id)));
		}
		$remaining = array_diff_key($nodes, $ordered);
		ksort($remaining, SORT_STRING);

		return $ordered + $remaining;
	}

	private function owners(): array
	{
		if ($this->parentIndex === null)
		{
			$this->parentIndex = [];
			foreach ($this->nodes() as $id => $node)
			{
				foreach ($this->children($id) as $child)
				{
					$this->parentIndex[$child][] = $id;
				}
			}
		}

		return $this->parentIndex;
	}

	private function walk(string $id, array &$visited, array &$active, array &$problems): void
	{
		if (isset($active[$id]))
		{
			$problems[] = $this->problem('cycle', $id);

			return;
		}
		if (isset($visited[$id]) || !isset($this->positions[$id]) || !is_array($this->positions[$id]))
		{
			return;
		}
		$visited[$id] = true;
		$active[$id] = true;
		foreach ($this->children($id) as $child)
		{
			$this->walk($child, $visited, $active, $problems);
		}
		unset($active[$id]);
	}

	private function checkLimits(): void
	{
		if ($this->limitProblems() !== [])
		{
			throw new LayoutOperationError('layout_limit', 'The layout exceeds its node or depth limit.');
		}
	}

	private function problem(string $code, string $id): array
	{
		return [
			'code' => $code,
			'componentId' => $id,
		];
	}

	private function uniqueProblems(array $problems): array
	{
		$unique = [];
		foreach ($problems as $problem)
		{
			$unique[$problem['code'] . ':' . $problem['componentId']] = $problem;
		}

		ksort($unique, SORT_STRING);

		return array_values($unique);
	}

	private static function arrays(mixed $value): mixed
	{
		if (is_object($value))
		{
			$value = (array)$value;
		}
		if (is_array($value))
		{
			return array_map(self::arrays(...), $value);
		}

		return $value;
	}

	private static function canonical(mixed $value): mixed
	{
		$value = self::arrays($value);
		if (is_array($value))
		{
			if (!array_is_list($value))
			{
				ksort($value, SORT_STRING);
			}
			$value = array_map(self::canonical(...), $value);
		}

		return $value;
	}
}
