<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access;

use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Error;
use Bitrix\Socialnetwork\Permission\AccessErrorInterface;

trait AccessUserErrorTrait
{
	private ?Error $userError = null;

	public function getUserError(): ?Error
	{
		return $this->userError;
	}

	protected function clearUserError(): void
	{
		$this->userError = null;
	}

	protected function setUserError(?Error $error): void
	{
		$this->userError = $error;
	}

	private function resolveUserError(AccessibleController $accessController): void
	{
		$error = match (true)
		{
			$accessController instanceof UserErrorInterface => $accessController->getUserErrors()[0] ?? null,
			$accessController instanceof AccessErrorInterface => $accessController->getErrors()[0] ?? null,
			default => null,
		};

		if (!$error instanceof Error)
		{
			return;
		}

		$this->setUserError($error);
	}
}
