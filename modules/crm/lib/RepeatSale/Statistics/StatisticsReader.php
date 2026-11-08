<?php

namespace Bitrix\Crm\RepeatSale\Statistics;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Log\Entity\RepeatSaleLogTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

/**
 * Counts and amounts of the widget, grouped by the database.
 *
 * The widget shows the same period in several groupings at once - the whole period, every scenario,
 * both stage semantics - and every one of them is a count and a sum over the very same rows. So the
 * grouping is left to SQL: the answer is a few dozen rows no matter how long the log is, where reading
 * the log row by row grows with the portal.
 *
 * The join to the deal is what makes the numbers match the deal list the widget opens: a log row whose
 * deal is gone drops out, the period boundary applies to the date of the deal (a log row is written
 * after its deal), and the amount is the one converted to the account currency, the currency the widget
 * signs its sums with.
 *
 * The read is not cached: a write to the entity drops the ORM query cache of its table, so on a portal
 * where deals keep changing the request pays the query anyway - and uncached sums are never an hour
 * behind the deals they count.
 */
class StatisticsReader
{
	private const DEAL = 'DEAL';

	/** false once the table has been looked for and not found, so the check costs one query per request */
	private string|false|null $dealDataClass = null;

	/**
	 * Counts and amounts of the period per scenario and stage semantics.
	 *
	 * @return array<int, array{SEGMENT_ID: int, STAGE_SEMANTIC_ID: string, CNT: int, AMOUNT: float}>
	 */
	public function readSegmentTotals(DateTime $periodStart): array
	{
		$query = $this->makeDealQuery($periodStart);
		if ($query === null)
		{
			return [];
		}

		$rows = $query
			->addSelect('SEGMENT_ID')
			->addSelect('STAGE_SEMANTIC_ID')
			->addSelect(new ExpressionField('CNT', 'COUNT(%s)', 'ID'))
			->addSelect(new ExpressionField('AMOUNT', 'SUM(%s)', self::DEAL . '.' . $this->amountFieldName()))
			->fetchAll()
		;

		return array_map(
			static fn (array $row): array => [
				'SEGMENT_ID' => (int)$row['SEGMENT_ID'],
				'STAGE_SEMANTIC_ID' => (string)$row['STAGE_SEMANTIC_ID'],
				'CNT' => (int)$row['CNT'],
				'AMOUNT' => (float)$row['AMOUNT'],
			],
			$rows,
		);
	}

	/**
	 * Counts of the deals created since the given moment, per scenario - the today counter of the widget.
	 * A separate query keeps both reads plain aggregates, without a conditional sum inside the grouping.
	 *
	 * @return array<int, int> counts indexed by segment id
	 */
	public function readSegmentCreatedCounts(DateTime $since): array
	{
		$query = $this->makeDealQuery($since);
		if ($query === null)
		{
			return [];
		}

		$counts = [];
		$rows = $query
			->addSelect('SEGMENT_ID')
			->addSelect(new ExpressionField('CNT', 'COUNT(%s)', 'ID'))
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$counts[(int)$row['SEGMENT_ID']] = (int)$row['CNT'];
		}

		return $counts;
	}

	/**
	 * Log rows of existing deals created since the boundary. The inner join drops a row whose deal is
	 * gone, so a deleted deal needs no filtering of its own afterwards.
	 */
	private function makeDealQuery(DateTime $createdFrom): ?Query
	{
		$dataClass = $this->dealDataClass();
		if ($dataClass === null)
		{
			return null;
		}

		return RepeatSaleLogTable::query()
			->registerRuntimeField(
				new Reference(
					self::DEAL,
					$dataClass,
					Join::on('this.ENTITY_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Deal)
			// The log row is written after its deal, so reading the log from the same boundary loses no
			// deal of the period, and the boundary itself belongs to the deal.
			->where('CREATED_AT', '>=', $createdFrom)
			->where(self::DEAL . '.' . $this->createdFieldName(), '>=', $createdFrom)
		;
	}

	/**
	 * The deal table, or nothing when the portal has no deal factory or the table is missing - the
	 * statistics of the widget are not the place to fail over it, the counters simply stay empty.
	 *
	 * @return class-string|null
	 */
	private function dealDataClass(): ?string
	{
		if ($this->dealDataClass === null)
		{
			$this->dealDataClass = $this->resolveDealDataClass() ?? false;
		}

		return $this->dealDataClass === false ? null : $this->dealDataClass;
	}

	/** @return class-string|null */
	private function resolveDealDataClass(): ?string
	{
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if ($factory === null)
		{
			return null;
		}

		$dataClass = $factory->getDataClass();

		return Application::getConnection()->isTableExists($dataClass::getTableName()) ? $dataClass : null;
	}

	/** The amount already converted to the account currency, the one the widget signs its sums with. */
	private function amountFieldName(): string
	{
		return $this->dealFieldName(Item::FIELD_NAME_OPPORTUNITY_ACCOUNT);
	}

	private function createdFieldName(): string
	{
		return $this->dealFieldName(Item::FIELD_NAME_CREATED_TIME);
	}

	private function dealFieldName(string $itemFieldName): string
	{
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);

		return $factory?->getEntityFieldNameByMap($itemFieldName) ?? $itemFieldName;
	}
}
