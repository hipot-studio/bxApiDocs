<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dismiss\Provider;

use Bitrix\Mobile\Internal\Dismiss\Service\DismissService;
use Bitrix\Mobile\Internal\Dismiss\Storage\DismissStorage;

/**
 * The list of elements a user has dismissed, for the controllers of the module. Reading only:
 * a code is closed through {@see \Bitrix\Mobile\Internal\Dismiss\Command\DismissCommand}.
 */
final class DismissProvider
{
	public function __construct(
		private readonly DismissStorage $storage,
	)
	{
	}

	/**
	 * @param int|null $userId owner of the list, the current user by default
	 * @return string[]
	 */
	public function getDismissedCodes(?int $userId = null): array
	{
		return $this->serviceFor($userId)->getDismissedCodes();
	}

	/**
	 * @return array{codes: string[], revision: int}
	 */
	public function getDismissedState(?int $userId = null): array
	{
		return $this->serviceFor($userId)->getDismissedState();
	}

	public function isDismissed(string $code, ?int $userId = null): bool
	{
		return $this->serviceFor($userId)->isDismissed($code);
	}

	public function isVisible(string $code, ?int $userId = null): bool
	{
		return $this->serviceFor($userId)->isVisible($code);
	}

	private function serviceFor(?int $userId): DismissService
	{
		return new DismissService($this->storage, $userId);
	}
}
