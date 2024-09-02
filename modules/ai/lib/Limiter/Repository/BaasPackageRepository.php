<?php declare(strict_types=1);

namespace Bitrix\AI\Limiter\Repository;

use Bitrix\AI\Limiter\Model\BaasPackageField;
use Bitrix\AI\Limiter\Model\BaasPackageTable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\ORM\Data\AddResult;

/**
 * @property string ID
 * @property string DATE_START
 * @property string DATE_EXPIRED
 */
class BaasPackageRepository extends BaseRepository
{
	public function getTableName(): string
	{
		return BaasPackageTable::getTableName();
	}

	public function getFieldEnum(): string
	{
		return BaasPackageField::class;
	}

	/**
	 * Add new package
	 */
	public function addPackage(Date $startDate, Date $expiredDate): AddResult
	{
		return BaasPackageTable::add([
			$this->DATE_START => $startDate,
			$this->DATE_EXPIRED => $expiredDate,
		]);
	}

	/**
	 * Return info about max date expired
	 */
	public function getLatestPackageByExpiration(): array
	{
		$data = BaasPackageTable::query()
			->setSelect([
				$this->DATE_EXPIRED
			])
			->setOrder([$this->DATE_EXPIRED => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		if (!is_array($data) || empty($data))
		{
			return [];
		}

		return $data;
	}
}
