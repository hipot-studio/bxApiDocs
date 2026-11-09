<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

final class LayoutBatch
{
	public const MAX_OPERATIONS = 50;
	public const MAX_ADDITIONS = 10;

	private readonly \Closure $applyOperation;

	/** @param (\Closure(DashboardLayout, array, \Closure): void)|null $applyOperation */
	public function __construct(?\Closure $applyOperation = null)
	{
		$this->applyOperation = $applyOperation ?? static function (
			DashboardLayout $layout,
			array $operation,
			\Closure $newId,
		): void {
			$name = $operation['operation'];
			$isHeader = str_contains($name, 'header')
				|| in_array($name, ['delete_empty_rows', 'repair_parent_paths'], true);
			$handler = $isHeader ? new HeaderOperations() : new ChartOperations();
			$handler->apply($layout, $operation, $newId);
		};
	}

	public function validate(array $ops): Result
	{
		if (count($ops) < 1 || count($ops) > self::MAX_OPERATIONS)
		{
			return self::limit(self::MAX_OPERATIONS, count($ops));
		}
		if (!array_is_list($ops))
		{
			return self::refuse(0, '', 'invalid_operations');
		}

		$normalized = [];
		$added = [];
		foreach ($ops as $index => $op)
		{
			$name = is_array($op) && is_string($op['operation'] ?? null) ? $op['operation'] : '';
			try
			{
				if (!is_array($op))
				{
					return self::refuse($index, $name, 'invalid_operation');
				}
				$normalized[] = OperationSchema::normalize($op);
			}
			catch (LayoutOperationError $exception)
			{
				return self::refuse($index, $name, $exception->reason);
			}
			if ($normalized[$index]['operation'] === 'add_chart_to_new_row')
			{
				$added[] = $normalized[$index]['chartId'];
			}
		}
		if (count($added) > self::MAX_ADDITIONS)
		{
			return self::limit(self::MAX_ADDITIONS, count($added));
		}

		return (new Result())->setData(['operations' => $normalized, 'addedChartIds' => $added]);
	}

	/** @param list<array> $ops */
	public function run(
		DashboardLayout $layout,
		array $ops,
		string $baseVersion,
		?\Closure $beforeSizes = null,
	): Result
	{
		$validated = $this->validate($ops);
		if (!$validated->isSuccess())
		{
			return $validated;
		}
		$limits = $layout->limitProblems();
		if ($limits !== [])
		{
			return self::layoutLimit($limits[0]);
		}
		$structural = $layout->structuralProblems();
		if ($structural !== [])
		{
			return Refusal::error(FailureCode::DomainRule, 'The dashboard layout is structurally broken.')
				->setData([
					'rule' => 'broken_layout',
					'problems' => array_map(static fn(array $p): string => $p['code'] . ':' . $p['componentId'], $structural),
				])
			;
		}

		$copy = clone $layout;
		$sizes = [];
		$sizeOwners = [];
		$grownRows = [];
		$initialWidths = $layout->rowWidths();
		$lines = [];
		$changes = 0;
		foreach ($validated->getData()['operations'] as $index => $op)
		{
			$before = $copy->problems();
			$widths = $copy->rowWidths();
			$version = $copy->version([]);
			$counter = 0;
			$newId = static function (string $type) use ($copy, $baseVersion, $index, &$counter): string {
				do
				{
					$id = $type . '-' . substr(hash('sha256', $baseVersion . '|' . $index . '|' . $counter++), 0, 10);
				}
				while (array_key_exists($id, $copy->toPositions()));

				return $id;
			};
			try
			{
				($this->applyOperation)($copy, $op, $newId);
				$limits = $copy->limitProblems();
				if ($limits !== [])
				{
					return self::layoutLimit($limits[0]);
				}
				if ($copy->structuralProblems() !== [])
				{
					return self::refuse($index, $op['operation'], 'broken_layout');
				}
				$newProblems = array_values(array_diff($copy->problems(), $before));
				if ($newProblems !== [])
				{
					return self::refuse($index, $op['operation'], explode(':', $newProblems[0], 2)[0]);
				}
				if (in_array($op['operation'], ['add_chart_to_new_row', 'resize_chart'], true))
				{
					$id = $copy->resolveChart($op);
					foreach (['width', 'height'] as $key)
					{
						if (isset($op[$key]))
						{
							$sizes[$id][$key] = $op[$key];
							$sizeOwners[$id][$key] = $index;
						}
					}
				}
			}
			catch (LayoutOperationError $exception)
			{
				return self::refuse($index, $op['operation'], $exception->reason);
			}
			catch (\Throwable)
			{
				return self::refuse($index, $op['operation'], 'operation_failed');
			}
			foreach ($copy->rowWidths() as $row => $width)
			{
				if ($width > ($widths[$row] ?? 0))
				{
					$grownRows[$row] = $index;
				}
			}
			if ($copy->version([]) !== $version)
			{
				$changes++;
				$lines[] = 'Applied ' . $op['operation'] . '.';
			}
		}
		if ($beforeSizes !== null)
		{
			$checked = $beforeSizes($copy);
			if (!$checked->isSuccess())
			{
				return $checked;
			}
		}
		$finalWidths = $copy->rowWidths();
		$grownRows = array_filter(
			$grownRows,
			static fn(string $row): bool => ($finalWidths[$row] ?? 0) > ($initialWidths[$row] ?? 0),
			ARRAY_FILTER_USE_KEY,
		);
		foreach ($copy->sizeProblems($sizes, array_keys($grownRows)) as $problem)
		{
			$id = $problem['componentId'];
			$reason = $problem['code'];
			$index = $reason === 'row_overflow' ? $grownRows[$id] : $sizeOwners[$id][$reason];

			return self::refuse($index, $ops[$index]['operation'], $reason);
		}

		return (new Result())->setData([
			'layout' => $copy,
			'changed' => $copy->version([]) !== $layout->version([]),
			'addedChartIds' => $validated->getData()['addedChartIds'],
			'summary' => ['lines' => $lines, 'changes' => $changes],
		]);
	}

	public static function layoutLimit(array $problem): Result
	{
		return Refusal::error(FailureCode::DomainRule, 'The layout exceeds the ' . $problem['limit'] . ' limit.')
			->setData($problem)
		;
	}

	private static function limit(int $limit, int $value): Result
	{
		return Refusal::error(FailureCode::DomainRule, 'The operation batch exceeds its item limit.')
			->setData(['limit' => $limit, 'value' => $value])
		;
	}

	private static function refuse(int $index, string $operation, string $reason): Result
	{
		return Refusal::error(FailureCode::InvalidArgument, "operations[{$index}] {$operation}: {$reason}.")
			->setData(['index' => $index, 'operation' => $operation, 'reason' => $reason])
		;
	}
}
