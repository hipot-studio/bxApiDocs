<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

final class HeaderOperations
{
	public function apply(DashboardLayout $layout, array $op, \Closure $newId): void
	{
		$op = OperationSchema::normalize($op);
		switch ($op['operation'])
		{
			case 'add_header_before_chart':
			case 'upsert_header_before_chart':
				$this->placeHeader($layout, $op, $newId);

				break;
			case 'update_header':
				$id = $this->resolveHeader($layout, $op);
				$node = $layout->node($id);
				foreach (['text', 'headerSize'] as $key)
				{
					if (isset($op[$key]))
					{
						$node['meta'][$key] = $op[$key];
					}
				}
				$layout->setNode($id, $node);

				break;
			case 'delete_header':
				$id = $this->resolveHeader($layout, $op);
				if ($layout->detach($id) === null)
				{
					throw new LayoutOperationError('parent_not_found', 'The header must have exactly one parent.');
				}
				$layout->removeNode($id);

				break;
			case 'delete_empty_rows':
				foreach (array_keys($layout->nodes()) as $id)
				{
					$layout->cleanupEmptyRow($id);
				}

				break;
			case 'repair_parent_paths':
				$layout->repairSubtreeParents('ROOT_ID');

				break;
			default:
				throw new LayoutOperationError('unknown_operation', 'Unsupported header operation: ' . $op['operation'] . '.');
		}
	}

	private function placeHeader(DashboardLayout $layout, array $op, \Closure $newId): void
	{
		$chart = $layout->resolveChart($op, 'reference');
		$row = $layout->parentOf($chart);
		if ($row === null || ($layout->node($row)['type'] ?? null) !== 'ROW')
		{
			throw new LayoutOperationError('chart_not_in_row', 'The chart must be directly inside a row.');
		}
		$container = $layout->parentOf($row);
		if ($container === null || !in_array($layout->node($container)['type'] ?? null, ['GRID', 'TAB'], true))
		{
			throw new LayoutOperationError('invalid_parent', 'The chart row must be directly inside GRID or TAB.');
		}
		$matches = $op['operation'] === 'upsert_header_before_chart' ? $this->matchingHeaders($layout, $op['text']) : [];
		if (count($matches) > 1)
		{
			throw new LayoutOperationError('ambiguous_header', 'The header text matches more than one header.');
		}
		if ($matches !== [])
		{
			$id = $matches[0];
			if ($layout->detach($id) === null)
			{
				throw new LayoutOperationError('parent_not_found', 'The header must have exactly one parent.');
			}
			$node = $layout->node($id);
			$node['meta']['headerSize'] = $op['headerSize'];
			$layout->setNode($id, $node);
		}
		else
		{
			$id = $newId('HEADER');
			$layout->setNode($id, [
				'id' => $id,
				'type' => 'HEADER',
				'children' => [],
				'parents' => [],
				'meta' => [
					'background' => 'BACKGROUND_TRANSPARENT',
					'headerSize' => $op['headerSize'],
					'text' => $op['text'],
				],
			]);
		}
		$index = array_search($row, $layout->children($container), true);
		$layout->insertChild($container, $id, $index);
		$layout->repairSubtreeParents($id);
	}

	private function resolveHeader(DashboardLayout $layout, array $op): string
	{
		if (isset($op['componentId']))
		{
			$id = $op['componentId'];
			if ($id === 'HEADER_ID' || ($layout->node($id)['type'] ?? null) !== 'HEADER')
			{
				throw new LayoutOperationError('header_not_found', 'The component must be a section header.');
			}

			return $id;
		}
		$matches = $this->matchingHeaders($layout, $op['currentText']);
		if (count($matches) !== 1)
		{
			throw new LayoutOperationError('header_not_found', 'The header address must resolve to exactly one section header.');
		}

		return $matches[0];
	}

	private function matchingHeaders(DashboardLayout $layout, string $text): array
	{
		$matches = [];
		$folded = mb_convert_case($text, MB_CASE_FOLD);
		foreach ($layout->nodes() as $id => $node)
		{
			$current = $node['meta']['text'] ?? null;
			if (
				$id !== 'HEADER_ID'
				&& ($node['type'] ?? null) === 'HEADER'
				&& is_string($current)
				&& mb_convert_case($current, MB_CASE_FOLD) === $folded
			)
			{
				$matches[] = $id;
			}
		}

		return $matches;
	}
}
