<?php declare(strict_types=1);

namespace Bitrix\AI\Integration\Intranet;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

class DepartmentService
{
	public function __construct(
		protected DepartmentRepository $departmentRepository
	)
	{
	}

	protected static function getStructureIBlockId(): int
	{
		return (int)Option::get('intranet', 'iblock_structure', 0);
	}

	protected function hasIntranet(): bool
	{
		try
		{
			return Loader::includeModule('intranet');
		}
		catch (LoaderException $exception)
		{
			return false;
		}
	}

	/**
	 * Returns the subset of the passed ids whose structure section is active.
	 *
	 * @param int[] $departmentIds
	 * @return list<int>
	 */
	public function getExistingDepartmentIds(array $departmentIds): array
	{
		$ids = $this->normalizeIds($departmentIds);
		if (empty($ids))
		{
			return [];
		}

		if (!$this->hasIntranet())
		{
			return [];
		}

		$structureIBlockId = static::getStructureIBlockId();
		if ($structureIBlockId <= 0)
		{
			return [];
		}

		return $this->departmentRepository->getExistingIds($ids, $structureIBlockId);
	}

	/**
	 * @param int[] $ids
	 * @return list<int>
	 */
	private function normalizeIds(array $ids): array
	{
		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$result[$id] = $id;
			}
		}

		return array_values($result);
	}
}
