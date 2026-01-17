<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;

class StorageTypeResult extends Result
{
	public function __construct(private ?StorageType $storageType = null)
	{
		parent::__construct();
	}

	public function getStorageType(): ?StorageType
	{
		return $this->storageType;
	}
}
