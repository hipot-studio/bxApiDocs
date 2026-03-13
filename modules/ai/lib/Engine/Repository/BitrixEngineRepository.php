<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Repository;

use Bitrix\AI\Engine\Model\BitrixEngineTable;

class BitrixEngineRepository
{
	public function getActiveEngines(): array
	{
		return BitrixEngineTable::query()
			->setSelect([
				'CLASS', 'CATEGORY',
			])
			->setCacheTtl(86400)
			->where('IS_ACTIVE', '=', 1)
			->fetchAll()
		;
	}

	/**
	 * @return list<array{CLASS: string, CATEGORY: string, IS_ACTIVE: int}>
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getAll(): array
	{
		return BitrixEngineTable::query()
			->setSelect([
				'ID', 'CLASS', 'CATEGORY', 'IS_ACTIVE',
			])
			->fetchAll()
		;
	}

	/**
	 * @param list<array{CLASS: string, CATEGORY: string}> $providers
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function insertEngines(array $providers): void
	{
		if (empty($providers))
		{
			return;
		}

		$values = [];
		foreach ($providers as $provider)
		{
			if (
				!empty($provider['CATEGORY']) && is_string($provider['CATEGORY'])
				&& !empty($provider['CLASS']) && is_string($provider['CLASS'])
			)
			{
				$values[] = [
					'CLASS' => $provider['CLASS'],
					'CATEGORY' => $provider['CATEGORY'],
				];
			}
		}

		if (empty($values))
		{
			BitrixEngineTable::addMulti($values, true);
		}
	}

	/**
	 * @param int[] $providersIds
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Exception
	 */
	public function deactivateEngines(array $providersIds): void
	{
		if (empty($providersIds))
		{
			return;
		}

		BitrixEngineTable::updateMulti(
			$providersIds,
			['IS_ACTIVE' => false],
			true
		);
	}

	public function deactivateEnginesByClass(array $providersClasses): void
	{
		if (empty($providersClasses))
		{
			return;
		}

		$this->deactivateEngines(
			BitrixEngineTable::query()
				->setSelect(['ID'])
				->whereIn('CLASS', $providersClasses)
				->fetchAll()
		);
	}

	/**
	 * @param int[] $providersIds
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function activateEngines(array $providersIds): void
	{
		if (empty($providersIds))
		{
			return;
		}

		BitrixEngineTable::updateMulti(
			$providersIds,
			['IS_ACTIVE' => true],
			true
		);
	}
}
