<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Intranet\Internal\Entity\AnnualSummary;
use Bitrix\Intranet\Internal\Model\AnnualSummaryTable;
use Bitrix\Intranet\Internal\Model\EO_AnnualSummary;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryInterface;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class AnnualSummaryRepository
{
	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByName(int $userId, string $name): SummaryInterface
	{
		$model = AnnualSummaryTable::query()
			->where('USER_ID', $userId)
			->where('NAME', $name)
			->setCacheTtl(86400 * 30) // 30 days
			->fetchObject()
		;
		
		return $this->convertToEntity($model);
	}

	public function has(int $userId): bool
	{
		// TODO: add logic in the future
		return false;
	}

	/**
	 * @throws SystemException
	 */
	public function save(SummaryInterface $entity): void
	{
		$helper = Application::getConnection()->getSqlHelper();
		
		$sql = $helper->prepareMerge(
			AnnualSummaryTable::getTableName(),
			['USER_ID', 'NAME'],
			[
				'USER_ID' => $entity->getUserId(),
				'NAME' => $entity->getId(),
				'TOTAL' => $entity->getTotal(),
			],
			['TOTAL' => $entity->getTotal()],
		);
		Application::getConnection()->query($sql[0]);
	}

	public function saveCollection(EntityCollection $collection): void
	{
		$rows = [];
		foreach ($collection->getIterator() as $entity)
		{
			$rows[] = [
				'USER_ID' => $entity->getUserId(),
				'NAME' => $entity->getId(),
				'TOTAL' => $entity->getTotal(),
			];
		}
		$helper = Application::getConnection()->getSqlHelper();
		$sql = $helper->prepareMergeMultiple(
			AnnualSummaryTable::getTableName(),
			['USER_ID', 'NAME'],
			$rows,
		);
		if (isset($sql[0]))
		{
			Application::getConnection()->query($sql[0]);
		}
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findByIdAndUserId(string $id, int $userId): ?SummaryInterface
	{
		$model = AnnualSummaryTable::query()
			->where('USER_ID', $userId)
			->where('NAME', $id)
			->setCacheTtl(86400 * 30) // 30 days
			->fetchObject()
		;

		if (!$model)
		{
			return null;
		}

		return $this->convertToEntity($model);
	}

	private function convertToOrm(SummaryInterface $entity): EO_AnnualSummary
	{
		return AnnualSummaryTable::createObject()
			->setUserId($entity->getUserId())
			->setName($entity->getId())
			->setTotal($entity->getTotal())
		;
	}

	private function convertToEntity(EO_AnnualSummary $model): SummaryInterface
	{
		return new AnnualSummary\Summary($model->getUserId(), $model->getName(), $model->getTotal());
	}
}
