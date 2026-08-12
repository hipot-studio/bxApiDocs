<?php
namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration\AI\Outcome;
use Bitrix\Call\Model\CallOutcomeTable;
use Bitrix\Call\Model\CallOutcomePropertyTable;
use Bitrix\Call\Model\EO_CallOutcome_Collection;


class OutcomeCollection extends EO_CallOutcome_Collection
{
	public static function getOutcomesByCallId(int $callId, array $outcomeTypes = []): static
	{
		return static::getOutcomesByCallIds([$callId], $outcomeTypes);
	}

	/**
	 * Fetch outcomes with their properties for multiple calls in two queries.
	 *
	 * @param int[] $callIds
	 * @param string[] $outcomeTypes
	 * @return static
	 */
	public static function getOutcomesByCallIds(array $callIds, array $outcomeTypes = []): static
	{
		if (empty($callIds))
		{
			return new static();
		}

		$outcomeQuery = CallOutcomeTable::query()
			->setSelect(['*'])
			->whereIn('CALL_ID', $callIds)
			->setOrder(['ID' => 'DESC'])
		;
		if ($outcomeTypes)
		{
			$outcomeQuery->whereIn('TYPE', $outcomeTypes);
		}
		$outcomeCollection = $outcomeQuery->exec()->fetchCollection();
		if ($outcomeIds = $outcomeCollection->getIdList())
		{
			$properties = CallOutcomePropertyTable::query()
				->setSelect(['*'])
				->whereIn('OUTCOME_ID', $outcomeIds)
				->setOrder(['OUTCOME_ID' => 'ASC', 'ID' => 'ASC'])
				->exec()
			;
			while ($property = $properties->fetchObject())
			{
				$outcomeCollection->getByPrimary($property->getOutcomeId())->appendProps($property);
			}
		}

		return $outcomeCollection;
	}

	public function getOutcomeByType(string $type): ?Outcome
	{
		foreach ($this as $outcome)
		{
			if ($outcome->getType() == $type)
			{
				return $outcome;
			}
		}
		return null;
	}

	/**
	 * The newest outcome of the given type that carries meaningful content.
	 *
	 * The collection is ordered ID DESC (see getOutcomesByCallIds), so the first contentful
	 * match in iteration is the newest one: an empty latest retry never shadows an older
	 * contentful result. Uses the default hasContent() so a calendar-only Evaluation still
	 * counts as content for read/display consumers.
	 *
	 * Precondition: the collection must belong to a single call. This method groups by TYPE
	 * only and does not scope by CALL_ID; for multi-call collections, group by CALL_ID first.
	 */
	public function getLatestContentfulByType(string $type): ?Outcome
	{
		foreach ($this as $outcome)
		{
			if ((string)$outcome->getType() !== $type)
			{
				continue;
			}
			$sense = $outcome->getSenseContent();
			if ($sense instanceof AISenseContent && $sense->hasContent())
			{
				return $outcome;// the first contentful outcome is the newest
			}
		}

		return null;
	}

	/**
	 * The newest contentful outcome for every type, keyed by type.
	 *
	 * Same invariant as getLatestContentfulByType applied in bulk: iterating the ID DESC
	 * collection, the first contentful outcome per type wins and an empty later retry cannot
	 * override it.
	 *
	 * Precondition: the collection must belong to a single call. This method groups by TYPE
	 * only and does not scope by CALL_ID; for multi-call collections, group by CALL_ID first.
	 *
	 * @return array<string, Outcome>
	 */
	public function mapLatestContentfulByType(): array
	{
		$result = [];
		foreach ($this as $outcome)
		{
			$type = (string)$outcome->getType();
			if (isset($result[$type]))
			{
				continue;
			}
			$sense = $outcome->getSenseContent();
			if ($sense instanceof AISenseContent && $sense->hasContent())
			{
				$result[$type] = $outcome;
			}
		}

		return $result;
	}
}
