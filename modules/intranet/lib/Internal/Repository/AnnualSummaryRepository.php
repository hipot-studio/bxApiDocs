<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Intranet\Internal\Entity\AnnualSummary;
use Bitrix\Intranet\Internal\Model\AnnualSummaryTable;
use Bitrix\Intranet\Internal\Model\EO_AnnualSummary;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;

class AnnualSummaryRepository
{
	private static array $data = [];
	
	public function __construct(
		private readonly int $userId,
	) {
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function store(AnnualSummary\Collection $collection): void
	{
		$annualSummaryData = $this->getByName('annual_summary_25');
		if ($annualSummaryData)
		{
			$annualSummaryData->setValue(Json::encode($collection->toArray()))->save();
		}
		else
		{
			AnnualSummaryTable::createObject()
				->setUserId($this->userId)
				->setName('annual_summary_25')
				->setValue(Json::encode($collection->toArray()))
				->save()
			;
		}

		self::$data[$this->userId] = [];
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByName(string $name): ?EO_AnnualSummary
	{
		return AnnualSummaryTable::query()
			->where('USER_ID', $this->userId)
			->where('NAME', $name)
			->setCacheTtl(86400 * 30) // 30 days
			->fetchObject()
		;
	}
	
	private function fetch(): array
	{
		try
		{
			if (empty(self::$data[$this->userId]))
			{
				self::$data[$this->userId] = Json::decode($this->getByName('annual_summary_25')?->getValue() ?? '');
			}

			return self::$data[$this->userId];
		}
		catch (\Exception)
		{
			return [];
		}
	}

	public function getOption(string $name, $default = null): mixed
	{
		return $this->getByName($name)?->getValue() ?? $default;
	}

	public function getSerializedOption(string $name, $default = null): mixed
	{
		$option = $this->getOption($name);

		return $option ? unserialize($option, ['allowed_classes' => false]) : $default;
	}
	
	public function load(): AnnualSummary\Collection
	{
		return AnnualSummary\Collection::createByArray($this->fetch());
	}
	
	public function has(): bool
	{
		return !empty($this->fetch());
	}
}
