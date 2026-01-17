<?php

namespace Bitrix\Bizproc\Internal\Service\StorageField;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Public\Provider\Params\StorageField\StorageFieldFilter;

class FieldCodeService
{
	private array $cache = [];
	private StorageFieldRepositoryInterface $fieldRepository;

	public function __construct()
	{
		$this->fieldRepository = Container::getStorageFieldRepository();
	}

	public function getFieldCodes(int $storageTypeId): array
	{
		if (isset($this->cache[$storageTypeId]))
		{
			return $this->cache[$storageTypeId];
		}

		$fieldCodes = [];

		if (isset($this->fieldRepository))
		{
			$fields = $this->fieldRepository->getList(filter: new StorageFieldFilter(['STORAGE_ID' => $storageTypeId]));

			foreach ($fields as $field)
			{
				$fieldCodes[] = $field->getCode();
			}

			$this->cache[$storageTypeId] = $fieldCodes;
		}

		return $fieldCodes;
	}
}
