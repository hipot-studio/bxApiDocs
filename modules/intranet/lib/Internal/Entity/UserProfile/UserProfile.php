<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserProfile;

use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\UserRole;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserFieldSectionCollection;
use Bitrix\Intranet\Internal\Entity\UserBaseInfo;
use Bitrix\Main\Entity\EntityInterface;

class UserProfile implements EntityInterface
{
	public function __construct(
		public readonly UserBaseInfo $baseInfo,
		public readonly UserFieldSectionCollection $fieldSectionCollection,
	)
	{}

	public function getId(): int
	{
		return $this->baseInfo->getId();
	}
}
