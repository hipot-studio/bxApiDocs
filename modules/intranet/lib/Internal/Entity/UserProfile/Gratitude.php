<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserProfile;

use Bitrix\Intranet\Internal\Entity\UserBaseInfo;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class Gratitude implements EntityInterface
{
	public function __construct(
		public readonly int $postId,
		public readonly int $gratitudeTypeId,
		public readonly UserBaseInfo $author,
		public readonly string $title,
		public readonly DateTime $dateTimeCreate,
	)
	{}

	public function getId(): int
	{
		return $this->postId;
	}
}
