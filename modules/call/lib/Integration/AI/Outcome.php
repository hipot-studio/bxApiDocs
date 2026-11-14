<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Main\ORM;
use Bitrix\Main\Web\Json;
use Bitrix\Call\Model\EO_CallOutcome;
use Bitrix\Call\Integration\AI\Outcome\Property;
use Bitrix\Call\Model\EO_CallOutcomeProperty_Collection;
use Bitrix\Call\Model\CallOutcomePropertyTable;
use Bitrix\Call\Model\CallOutcomeTable;


class Outcome extends EO_CallOutcome
{
	private ?EO_CallOutcomeProperty_Collection $propertyCollection = null;

	private Outcome\AISenseContent|string|null $senseContent = null;

	private bool $senseContentBuilt = false;

	/**
	 * Returns class name for specific sense type.
	 * @return class-string<Outcome\AISenseContent>|null
	 */
	public function getSenseContentClass(): ?string
	{
		return match ($this->getType())
		{
			SenseType::TRANSCRIBE->value => Outcome\Transcription::class,
			SenseType::SUMMARY->value => Outcome\Summary::class,
			SenseType::OVERVIEW->value => Outcome\Overview::class,
			SenseType::INSIGHTS->value => Outcome\Insights::class,
			SenseType::EVALUATION->value => Outcome\Evaluation::class,
			default => null
		};
	}

	/**
	 * Returns specific sense content.
	 * @return Outcome\AISenseContent|string|null
	 */
	public function getSenseContent(): Outcome\AISenseContent|string|null
	{
		if (!$this->senseContentBuilt)
		{
			$this->senseContentBuilt = true;
			$class = $this->getSenseContentClass();
			$this->senseContent = $class ? new $class($this) : $this->getContent();
		}

		return $this->senseContent;
	}

	public function setProperty(string $code, mixed $value): self
	{
		if (empty($value))
		{
			return $this;
		}

		if ($this->propertyCollection === null)
		{
			$this->propertyCollection = new EO_CallOutcomeProperty_Collection;
		}

		$prop = new Property;
		$prop
			->setOutcome($this)
			->setCode($code)
			->setContent(!is_string($value) ? Json::encode($value) : $value)
		;

		$this->propertyCollection->add($prop);

		return $this;
	}

	public function getProperty(string $code): ?Property
	{
		foreach ($this->getProps() as $prop)
		{
			if ($prop->getCode() == $code)
			{
				return $prop;
			}
		}

		return null;
	}

	public function saveProps(): ORM\Data\Result|ORM\Data\AddResult|ORM\Data\UpdateResult
	{
		if ($this->propertyCollection !== null)
		{
			return $this->propertyCollection->save();
		}

		return new ORM\Data\Result;
	}

	public function getProps(): EO_CallOutcomeProperty_Collection
	{
		if ($this->propertyCollection === null)
		{
			if ($this->getId() > 0)
			{
				$this->propertyCollection = CallOutcomePropertyTable::query()
					->setSelect(['*'])
					->where('OUTCOME_ID', $this->getId())
					->fetchCollection()
				;
			}
			else
			{
				$this->propertyCollection = new EO_CallOutcomeProperty_Collection;
			}
		}

		return $this->propertyCollection;
	}

	public function drop(): ORM\Data\Result
	{
		$props = $this->getProps();
		foreach ($props as $prop)
		{
			$prop->delete();
		}

		return parent::delete();
	}

	public function fillProps(EO_CallOutcomeProperty_Collection $propertyCollection): self
	{
		$this->propertyCollection = $propertyCollection;
		return $this;
	}

	public function appendProps(Property $property): self
	{
		if ($this->propertyCollection === null)
		{
			$this->propertyCollection = new EO_CallOutcomeProperty_Collection;
		}

		$this->propertyCollection->add($property);

		return $this;
	}

	/**
	 * @param array $jsonData
	 * @return self
	 */
	public function fillFromJson(array $jsonData): self
	{
		foreach ($jsonData as $code => $value)
		{
			$this->setProperty($code, $value);
		}

		return $this;
	}

	public function toRestFormat(): array
	{
		return [
			'outcomeId' => $this->getId(),
			'trackId' => $this->getId(),
			'type' => $this->getType(),
			'callId' => $this->getCallId(),
			'content' => $this->getContent(),
		];
	}

	public function toArray(): array
	{
		return [
			'OUTCOME_ID' => $this->getId(),
			'TRACK_ID' => $this->getId(),
			'TYPE' => $this->getType(),
			'CALL_ID' => $this->getCallId(),
			'CONTENT' => $this->getContent(),
		];
	}

	public static function getOutcomeForCall(int $callId, SenseType $senseType): ?self
	{
		return CallOutcomeTable::getList([
			'filter' => [
				'=CALL_ID' => $callId,
				'=TYPE' => $senseType->value
			],
			'order' => ['ID' => 'DESC'],
			'limit' => 1
		])?->fetchObject();
	}

	/**
	 * Id of the newest result of the call, 0 for a call that has none.
	 *
	 * A boundary of a generation of its own, read beside the one of the tasks and never in place
	 * of it: a result carries no id of the task that produced it, and `OUTCOME_ID` of a task names
	 * the result the task was BUILT FROM, which is older than the result it will leave behind
	 * {@see CallAIService::restartWithPurge()}.
	 */
	public static function getLatestOutcomeId(int $callId): int
	{
		$row = CallOutcomeTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		return (int)($row['ID'] ?? 0);
	}

	/**
	 * Ids of the results of one type of the call that are not newer than the boundary of the
	 * generation.
	 *
	 * Ids and not objects: the one caller erases them {@see CallAIService::purgeGeneration()} and
	 * an id is all a deletion needs, while an object of a result carries the whole longText of the
	 * content it is about to lose - read out of the base, built into an entity and thrown away,
	 * once per row of the history of the call, inside the lock of a restart the operator is
	 * waiting on.
	 *
	 * @return int[] oldest first
	 */
	public static function getOutcomeIdsOfSenseUpTo(int $callId, SenseType $senseType, int $boundaryId): array
	{
		if ($boundaryId <= 0)
		{
			return [];
		}

		$ids = [];
		$rows = CallOutcomeTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->where('TYPE', $senseType->value)
			->where('ID', '<=', $boundaryId)
			->setOrder(['ID' => 'ASC'])
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}
}