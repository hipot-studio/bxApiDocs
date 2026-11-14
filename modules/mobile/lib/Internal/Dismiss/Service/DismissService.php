<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dismiss\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Mobile\Internal\Dismiss\DismissCode;
use Bitrix\Mobile\Internal\Dismiss\Storage\DismissStorage;

/**
 * The list of one user, shared by the reads of the provider and the writes of the command.
 */
class DismissService
{
	private readonly int $userId;
	private readonly DismissStorage $storage;

	/**
	 * @throws ArgumentException when there is no user to own the list: user 0 is a real option row
	 * shared by every call without an identity, not an empty list of its own.
	 */
	public function __construct(DismissStorage $storage, ?int $userId = null)
	{
		$ownerId = $userId ?? (int)CurrentUser::get()->getId();
		if ($ownerId <= 0)
		{
			throw new ArgumentException('Dismiss list requires an authenticated user.', 'userId');
		}

		$this->userId = $ownerId;
		$this->storage = $storage;
	}

	/**
	 * @return string[]
	 */
	public function getDismissedCodes(): array
	{
		return $this->storage->getCodes($this->userId);
	}

	/**
	 * @return array{codes: string[], revision: int}
	 */
	public function getDismissedState(): array
	{
		return $this->storage->getState($this->userId);
	}

	public function isDismissed(string $code): bool
	{
		$normalized = DismissCode::normalize($code);

		return $normalized !== null && in_array($normalized, $this->getDismissedCodes(), true);
	}

	public function isVisible(string $code): bool
	{
		return !$this->isDismissed($code);
	}

	/**
	 * @return string[] the whole list, the way the client of the action expects it back from a write
	 */
	public function dismiss(DismissCode $code): array
	{
		return $this->storage->addCode($this->userId, $code->getValue());
	}

	/**
	 * @return array{codes: string[], revision: int}
	 */
	public function dismissWithState(DismissCode $code): array
	{
		return $this->storage->addCodeState($this->userId, $code->getValue());
	}
}
