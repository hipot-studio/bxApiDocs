<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Result\User;

use Bitrix\Main\Result;

final class TransferToIntranetUserResult extends Result
{
	public function __construct(
		public readonly int $userId,
		public readonly string $status,
	)
	{
		parent::__construct();
	}

	public function toArray(): array
	{
		return ['userId' => $this->userId, 'status' => $this->status, 'errors' => $this->getErrors()];
	}
}
