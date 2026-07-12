<?php
namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration\AI\Outcome;
use Bitrix\Call\Model\CallOutcomeTable;
use Bitrix\Call\Model\CallOutcomePropertyTable;
use Bitrix\Call\Model\EO_CallOutcome_Collection;


class OutcomeCollection extends EO_CallOutcome_Collection
{
	/** @var array<string, Outcome>|null Lazy index: "{callId}:{type}" => Outcome */
	private ?array $callTypeIndex = null;

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

	public function getOutcomeByCallIdAndType(int $callId, string $type): ?Outcome
	{
		if ($this->callTypeIndex === null)
		{
			$this->buildCallTypeIndex();
		}

		return $this->callTypeIndex[$callId . ':' . $type] ?? null;
	}

	private function buildCallTypeIndex(): void
	{
		$this->callTypeIndex = [];
		foreach ($this as $outcome)
		{
			$key = $outcome->getCallId() . ':' . $outcome->getType();
			$this->callTypeIndex[$key] ??= $outcome;
		}
	}
}