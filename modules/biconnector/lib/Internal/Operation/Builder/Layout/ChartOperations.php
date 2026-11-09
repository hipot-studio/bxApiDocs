<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

final class ChartOperations
{
	public function apply(DashboardLayout $layout, array $op, \Closure $newId): void
	{
		$op = OperationSchema::normalize($op);
		$name = $op['operation'];
		if ($name === 'add_chart_to_new_row')
		{
			$this->add($layout, $op, $newId);

			return;
		}
		if ($name === 'swap_charts')
		{
			$this->swap($layout, $op);

			return;
		}
		$id = $layout->resolveChart($op);
		switch ($name)
		{
			case 'move_chart_before':
			case 'move_chart_after':
				$reference = $layout->resolveChart($op, 'reference');
				if ($id === $reference)
				{
					throw new LayoutOperationError('same_chart', 'A chart cannot be moved relative to itself.');
				}
				$parent = $layout->parentOf($reference);
				if ($parent === null)
				{
					throw new LayoutOperationError('chart_not_in_row', 'The reference chart must have a parent row.');
				}
				$index = array_search($reference, $layout->children($parent), true);
				$this->move($layout, $id, $parent, $index + ($name === 'move_chart_after' ? 1 : 0));

				break;
			case 'move_chart_to_row':
				$this->requireType($layout, $op['rowId'], ['ROW']);
				$this->move($layout, $id, $op['rowId'], $op['index'] ?? null);

				break;
			case 'move_chart_to_new_row':
				$parent = $op['parentId'] ?? $layout->getDefaultRowParent();
				$row = $this->newRow($layout, $parent, $op['index'] ?? null, $newId);
				$this->move($layout, $id, $row, 0);

				break;
			case 'move_chart_to_tab':
				$this->requireType($layout, $op['tabId'], ['TAB']);
				if (isset($op['rowId']))
				{
					$this->requireType($layout, $op['rowId'], ['ROW']);
					if (!in_array($op['tabId'], $layout->ancestors($op['rowId']), true))
					{
						throw new LayoutOperationError('row_not_in_tab', 'The row is not in the requested tab.');
					}
					$this->move($layout, $id, $op['rowId'], $op['index'] ?? null);
				}
				else
				{
					$row = $this->newRow($layout, $op['tabId'], $op['index'] ?? null, $newId);
					$this->move($layout, $id, $row, 0);
				}

				break;
			case 'resize_chart':
				$node = $layout->node($id);
				foreach (['width', 'height'] as $key)
				{
					if (isset($op[$key]))
					{
						$node['meta'][$key] = $op[$key];
					}
				}
				$layout->setNode($id, $node);

				break;
			case 'rename_chart_title':
				$node = $layout->node($id);
				$node['meta']['sliceNameOverride'] = $op['title'];
				$layout->setNode($id, $node);

				break;
			default:
				throw new LayoutOperationError('unknown_operation', 'Unsupported chart operation: ' . $name . '.');
		}
	}

	private function add(DashboardLayout $layout, array $op, \Closure $newId): void
	{
		foreach ($layout->nodes() as $node)
		{
			if (($node['type'] ?? null) === 'CHART' && ($node['meta']['chartId'] ?? null) === $op['chartId'])
			{
				throw new LayoutOperationError('chart_already_in_layout', 'The chart is already in this dashboard.');
			}
		}
		$parent = $op['parentId'] ?? $layout->getDefaultRowParent();
		$row = $this->newRow($layout, $parent, $op['index'] ?? null, $newId);
		$id = $newId('CHART');
		$layout->setNode($id, [
			'id' => $id,
			'type' => 'CHART',
			'children' => [],
			'parents' => array_merge($layout->ancestors($row), [$row]),
			'meta' => [
				'chartId' => $op['chartId'],
				'sliceName' => $op['title'],
				'width' => $op['width'],
				'height' => $op['height'],
			],
		]);
		$layout->insertChild($row, $id);
	}

	private function newRow(DashboardLayout $layout, string $parent, ?int $index, \Closure $newId): string
	{
		$this->requireType($layout, $parent, ['GRID', 'TAB']);
		if (!$layout->isReachable($parent))
		{
			throw new LayoutOperationError('invalid_parent', 'The destination must be reachable from ROOT_ID.');
		}
		$id = $newId('ROW');
		$layout->setNode($id, [
			'id' => $id,
			'type' => 'ROW',
			'children' => [],
			'parents' => array_merge($layout->ancestors($parent), [$parent]),
			'meta' => ['background' => 'BACKGROUND_TRANSPARENT'],
		]);
		$layout->insertChild($parent, $id, $index);

		return $id;
	}

	private function move(DashboardLayout $layout, string $id, string $parent, ?int $index): void
	{
		$this->requireType($layout, $parent, ['ROW']);
		$oldParent = $layout->parentOf($id);
		if ($oldParent === null)
		{
			throw new LayoutOperationError('parent_not_found', 'The chart must have exactly one parent.');
		}
		$oldIndex = array_search($id, $layout->children($oldParent), true);
		$index = max(0, min($index ?? count($layout->children($parent)), count($layout->children($parent))));
		if ($oldParent === $parent && $oldIndex < $index)
		{
			$index--;
		}
		$layout->detach($id);
		$layout->insertChild($parent, $id, $index);
		$layout->repairSubtreeParents($id);
		$layout->cleanupEmptyRow($oldParent);
	}

	private function swap(DashboardLayout $layout, array $op): void
	{
		$first = $layout->resolveChart($op, 'first');
		$second = $layout->resolveChart($op, 'second');
		if ($first === $second)
		{
			return;
		}
		$firstParent = $layout->parentOf($first);
		$secondParent = $layout->parentOf($second);
		if ($firstParent === null || $secondParent === null)
		{
			throw new LayoutOperationError('parent_not_found', 'Both charts must have a parent row.');
		}
		$this->requireType($layout, $firstParent, ['ROW']);
		$this->requireType($layout, $secondParent, ['ROW']);
		$firstNode = $layout->node($firstParent);
		$secondNode = $layout->node($secondParent);
		$firstIndex = array_search($first, $firstNode['children'], true);
		$secondIndex = array_search($second, $secondNode['children'], true);
		$firstNode['children'][$firstIndex] = $second;
		if ($firstParent === $secondParent)
		{
			$firstNode['children'][$secondIndex] = $first;
			$layout->setNode($firstParent, $firstNode);
		}
		else
		{
			$secondNode['children'][$secondIndex] = $first;
			$layout->setNode($firstParent, $firstNode);
			$layout->setNode($secondParent, $secondNode);
		}
		$layout->repairSubtreeParents($first);
		$layout->repairSubtreeParents($second);
	}

	private function requireType(DashboardLayout $layout, string $id, array $types): void
	{
		if (!in_array($layout->node($id)['type'] ?? null, $types, true))
		{
			throw new LayoutOperationError('invalid_parent', 'The destination must be ' . implode(' or ', $types) . '.');
		}
	}
}
