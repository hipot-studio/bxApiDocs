<?php declare(strict_types=1);

namespace Bitrix\AI\Limiter\Repository;

use Bitrix\AI\Model\CounterField;
use Bitrix\AI\Model\CounterTable;
use Bitrix\Main\Type\Date;

/**
 * @property string ID
 * @property string NAME
 * @property string VALUE
 */
class CounterRepository extends BaseRepository
{
	public function getTableName(): string
	{
		return CounterTable::getTableName();
	}

	public function getFieldEnum(): string
	{
		return CounterField::class;
	}

	/**
	 * Return info about last request in baas with increment limit
	 */
	public function getLastDate(): array|false
	{
		return CounterTable::query()
			->setSelect([
				$this->VALUE
			])
			->where($this->NAME, CounterTable::COUNTER_LAST_REQUEST_IN_BAAS)
			->fetch()
		;
	}

	/**
	 * Insert or update date last request in baas with increment limit
	 */
	public function updateLastRequest(): void
	{
		$name = CounterTable::COUNTER_LAST_REQUEST_IN_BAAS;
		$date = (new Date())->toString();

		CounterTable::merge(
			[$this->NAME => $name, $this->VALUE => $date],
			[$this->VALUE => $date]
		);
	}
}
