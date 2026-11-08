<?php

declare(strict_types= 1);

namespace Bitrix\Intranet\Public\Dto\User\Absence;

final readonly class ActiveVacation
{
	public function __construct(
		public int $absenceId,
		public string $dateTo,
	)
	{
	}
}
