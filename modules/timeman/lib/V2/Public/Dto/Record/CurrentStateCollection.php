<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Record;

use Bitrix\Timeman\V2\Public\Dto\AbstractCollection;

final class CurrentStateCollection extends AbstractCollection
{
	public function getByUserId(int $userId): ?CurrentState
	{
		return $this->find(
			static fn (CurrentState $state): bool => $state->userId === $userId,
		);
	}

	protected static function getItemClass(): string
	{
		return CurrentState::class;
	}
}
