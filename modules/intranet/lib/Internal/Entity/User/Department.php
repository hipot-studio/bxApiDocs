<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User;

use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfoCollection;
use Bitrix\Main\Entity\EntityInterface;

class Department implements EntityInterface
{
	public function __construct(
		public int $id,
		public int $headUserId,
		public string $name,
		public BaseInfoCollection $subordinateUsers,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}
}