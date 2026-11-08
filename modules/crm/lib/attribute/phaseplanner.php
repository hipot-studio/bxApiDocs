<?php
namespace Bitrix\Crm\Attribute;

use Bitrix\Crm\PhaseSemantics;

/**
 * The rules behind every change of the phase-dependent field attributes: rebuilding the ranges of a
 * settings scope when a phase changes its weight, cloning a range onto a new failure phase, and the
 * surgery a phase deletion does to the ranges around it.
 *
 * Nothing here reads or writes: every rule takes arrays and returns a plan of what to update, add and
 * delete ({@see self::emptyPlan()}). The writing side is
 * {@see \Bitrix\Crm\Attribute\FieldAttributeManager}.
 *
 * The order of phases is an ordered map, phase id => phase fields, sorted the way the runtime sorts it
 * ({@see \Bitrix\Crm\Attribute\FieldAttributeManager::sortPhasesBySortAscAndIdAsc()}). Rows are the
 * records of `b_crm_field_attr` as they come from the table.
 *
 * The rules hold while no two phases of the resulting state share a SORT: an order is read here by
 * position, while the runtime reads a range by SORT with non-strict bounds
 * ({@see \Bitrix\Crm\Attribute\FieldAttributeManager::checkPhaseCondition()}). The UI inputs keep the
 * weights apart, but crm.status.add and crm.status.update pass an arbitrary SORT straight to
 * \CCrmStatus::Add() ({@see \CCrmStatusRestProxy::innerAdd()}); a tie made that way leaves the two
 * readings one phase apart, and the next rebuild writes the set down without that phase.
 */
final class PhasePlanner
{
	/**
	 * Rebuilds the ranges of a whole settings scope over a new order of phases.
	 *
	 * @param array $oldOrder order of phases before the change
	 * @param array $newOrder order of phases after the change
	 * @param array $rows flat list of the rows of the settings scope
	 */
	public static function planReorderScope(array $oldOrder, array $newOrder, array $rows): array
	{
		$plan = self::emptyPlan();

		foreach (self::groupRows(self::withoutAllPhasesRows($rows)) as $group)
		{
			$plan = self::mergePlans($plan, self::planReorder($oldOrder, $newOrder, $group));
		}

		return $plan;
	}

	/**
	 * The same rebuild for a single group of rows: restores the set of phases the field is required on,
	 * applies the new order to it and lays the set out into ranges anew.
	 *
	 * A group whose rows cannot be read against the old order - a range pointing at an unknown phase or
	 * an inverted one - is left alone as a whole.
	 */
	public static function planReorder(array $oldOrder, array $newOrder, array $rows): array
	{
		$plan = self::emptyPlan();

		$oldPhaseIds = self::phaseIdsOf($oldOrder);
		if ($oldPhaseIds === self::phaseIdsOf($newOrder))
		{
			return $plan;
		}

		$rows = self::sortRowsById(self::withoutAllPhasesRows($rows));
		$positions = array_flip($oldPhaseIds);

		foreach ($rows as $row)
		{
			$start = self::startPhaseOf($row);
			$finish = self::finishPhaseOf($row);
			if (
				!isset($positions[$start])
				|| !isset($positions[$finish])
				|| $positions[$start] > $positions[$finish]
			)
			{
				return $plan;
			}
		}

		$required = [];
		foreach ($rows as $row)
		{
			$from = $positions[self::startPhaseOf($row)];
			$to = $positions[self::finishPhaseOf($row)];
			for ($i = $from; $i <= $to; $i++)
			{
				$required[$oldPhaseIds[$i]] = true;
			}
		}

		$ranges = [];
		$run = null;
		foreach (self::phaseIdsOf($newOrder) as $phaseId)
		{
			if (isset($required[$phaseId]))
			{
				$run = ($run === null) ? [$phaseId, $phaseId] : [$run[0], $phaseId];
			}
			elseif ($run !== null)
			{
				$ranges[] = $run;
				$run = null;
			}
		}
		if ($run !== null)
		{
			$ranges[] = $run;
		}

		// Existing rows are reused range by range, so that a rebuild does not multiply the records.
		foreach ($ranges as $index => $range)
		{
			if (!isset($rows[$index]))
			{
				$plan['add'][] = self::addition($rows[0], $range[0], $range[1]);

				continue;
			}

			if (
				self::startPhaseOf($rows[$index]) !== $range[0]
				|| self::finishPhaseOf($rows[$index]) !== $range[1]
			)
			{
				$plan['update'][] = self::modification($rows[$index], $range[0], $range[1]);
			}
		}

		for ($index = count($ranges), $count = count($rows); $index < $count; $index++)
		{
			$plan['delete'][] = self::idOf($rows[$index]);
		}

		return $plan;
	}

	/**
	 * The phase a new one inherits its ranges from, or null when it inherits nothing. Answered without
	 * the rows, so that the caller can leave before it reads them.
	 */
	public static function resolveFailureCloneSource(string $newPhaseId, array $order): ?string
	{
		$phaseIds = self::phaseIdsOf($order);
		$position = array_search($newPhaseId, $phaseIds, true);
		if ($position === false || $position === 0)
		{
			return null;
		}

		$previousPhaseId = $phaseIds[$position - 1];
		$previousSemantics = self::semanticsOf($order, $previousPhaseId);
		if (
			$previousSemantics !== PhaseSemantics::FAILURE
			|| self::semanticsOf($order, $newPhaseId) !== $previousSemantics
		)
		{
			return null;
		}

		return $previousPhaseId;
	}

	/**
	 * Clones onto the new phase every range that ends on the phase it inherits from.
	 */
	public static function planCreation(string $sourcePhaseId, string $newPhaseId, array $rows): array
	{
		$plan = self::emptyPlan();

		foreach (self::sortRowsById(self::withoutAllPhasesRows($rows)) as $row)
		{
			if (self::finishPhaseOf($row) === $sourcePhaseId)
			{
				$plan['add'][] = self::addition($row, $newPhaseId, $newPhaseId);
			}
		}

		return $plan;
	}

	/**
	 * Takes the phase out of the ranges that end on it: a range of that phase alone goes away, a wider
	 * one steps back onto the neighbour. No neighbour on the needed side - the range goes away too, and
	 * that is what an empty order of phases makes of every affected row.
	 */
	public static function planDeletion(string $phaseId, array $order, array $rows): array
	{
		[$previousPhaseId, $nextPhaseId] = self::neighborsOf($phaseId, $order);

		$plan = self::emptyPlan();
		foreach (self::sortRowsById(self::withoutAllPhasesRows($rows)) as $row)
		{
			$start = self::startPhaseOf($row);
			$finish = self::finishPhaseOf($row);
			$isStart = ($start === $phaseId);
			$isFinish = ($finish === $phaseId);
			if (!$isStart && !$isFinish)
			{
				continue;
			}

			if (
				$start === $finish
				|| ($isStart && $nextPhaseId === null)
				|| ($isFinish && $previousPhaseId === null)
			)
			{
				$plan['delete'][] = self::idOf($row);
			}
			elseif ($isStart)
			{
				$plan['update'][] = self::modification($row, $nextPhaseId, $finish);
			}
			else
			{
				$plan['update'][] = self::modification($row, $start, $previousPhaseId);
			}
		}

		return $plan;
	}

	/**
	 * @return array{0: string|null, 1: string|null} the phases right before and right after this one
	 */
	public static function neighborsOf(string $phaseId, array $order): array
	{
		$phaseIds = self::phaseIdsOf($order);
		$position = array_search($phaseId, $phaseIds, true);
		if ($position === false)
		{
			return [null, null];
		}

		return [
			($position > 0) ? $phaseIds[$position - 1] : null,
			$phaseIds[$position + 1] ?? null,
		];
	}

	private static function emptyPlan(): array
	{
		return [
			'update' => [],
			'add' => [],
			'delete' => [],
		];
	}

	private static function mergePlans(array $plan, array $addend): array
	{
		return [
			'update' => array_merge($plan['update'], $addend['update']),
			'add' => array_merge($plan['add'], $addend['add']),
			'delete' => array_merge($plan['delete'], $addend['delete']),
		];
	}

	private static function modification(array $row, string $startPhaseId, string $finishPhaseId): array
	{
		return [
			'ID' => self::idOf($row),
			'START_PHASE' => $startPhaseId,
			'FINISH_PHASE' => $finishPhaseId,
		];
	}

	private static function addition(array $row, string $startPhaseId, string $finishPhaseId): array
	{
		return [
			'ROW' => $row,
			'START_PHASE' => $startPhaseId,
			'FINISH_PHASE' => $finishPhaseId,
		];
	}

	/**
	 * Rows required on every phase carry no range at all: no rule here reads or rewrites them.
	 */
	private static function withoutAllPhasesRows(array $rows): array
	{
		return array_filter(
			$rows,
			static fn(array $row): bool => !(
				self::startPhaseOf($row) === ''
				&& self::finishPhaseOf($row) === ''
				&& (int)($row['PHASE_GROUP_TYPE_ID'] ?? 0) === FieldAttributePhaseGroupType::ALL
			)
		);
	}

	/**
	 * Ranges are laid out within a group and never across: the settings of the pipeline and those of the
	 * failure phases stay apart even when their phases end up side by side.
	 */
	private static function groupRows(array $rows): array
	{
		$groups = [];
		foreach ($rows as $row)
		{
			$key = implode(
				':',
				[
					(string)($row['FIELD_NAME'] ?? ''),
					(string)($row['TYPE_ID'] ?? ''),
					(int)($row['PHASE_GROUP_TYPE_ID'] ?? 0),
					(string)($row['IS_CUSTOM_FIELD'] ?? ''),
				]
			);
			$groups[$key][] = $row;
		}

		return $groups;
	}

	/**
	 * The plan must not depend on the order the rows came in.
	 */
	private static function sortRowsById(array $rows): array
	{
		usort($rows, static fn(array $a, array $b): int => self::idOf($a) <=> self::idOf($b));

		return $rows;
	}

	private static function phaseIdsOf(array $order): array
	{
		return array_map('strval', array_keys($order));
	}

	private static function semanticsOf(array $order, string $phaseId): string
	{
		return (string)($order[$phaseId]['SEMANTICS'] ?? '');
	}

	private static function idOf(array $row): int
	{
		return (int)($row['ID'] ?? 0);
	}

	private static function startPhaseOf(array $row): string
	{
		return (string)($row['START_PHASE'] ?? '');
	}

	private static function finishPhaseOf(array $row): string
	{
		return (string)($row['FINISH_PHASE'] ?? '');
	}
}
