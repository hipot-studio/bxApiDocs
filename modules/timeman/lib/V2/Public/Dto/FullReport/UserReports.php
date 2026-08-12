<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\FullReport;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Public\Dto\User\User;

final class UserReports implements Arrayable
{
	public function __construct(
		public readonly int $userId,
		public readonly ?User $user,
		public readonly FullReportCollection $reports,
	)
	{
	}

	public function getId(): int
	{
		return $this->userId;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'user' => $this->user?->toArray(),
			'reports' => $this->reports->toArray(),
		];
	}
}
