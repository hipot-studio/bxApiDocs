<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

use Bitrix\Rest\Internal\Access\User\UserAction;
use Bitrix\Rest\Internal\Access\User\UserAccessController;

class UserAccessChecker
{
	private UserAccessController $controller;

	public function __construct(private readonly int $userId)
	{
		$this->controller = UserAccessController::getInstance($this->userId);
	}

	public function canAuthorize(): bool
	{
		return $this->controller->check(UserAction::Authorize);
	}
}
