<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Service\User\UserRegistrationDataService;

class UserRegistrationDataProvider
{
	public function __construct(
		private readonly UserRegistrationDataService $userRegistrationDataService,
	)
	{
	}

	public static function createByDefault(): self
	{
		return new self(
			new UserRegistrationDataService(new \Bitrix\Intranet\Repository\UserRepository()),
		);
	}

	/**
	 * @return array{email: ?string, phone: ?string}|null
	 */
	public function getProfileContactsByUserId(int $userId): ?array
	{
		return $this->userRegistrationDataService->getProfileContactsByUserId($userId);
	}
}
