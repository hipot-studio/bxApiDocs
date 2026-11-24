<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Socialservices\NetworkClient;
use Bitrix\Main\Loader;
use Bitrix\Intranet\Contract\Repository\UserRepository;

class UserRegistrationDataService
{
	private const EXCLUDED_EXTERNAL_AUTH_IDS = ['bot', 'rest_system'];

	public function __construct(
		private readonly UserRepository $userRepository,
	)
	{
	}

	/**
	 * @return array{email: ?string, phone: ?string}|null
	 */
	public function getProfileContactsByUserId(int $userId): ?array
	{
		if (!$this->canRead())
		{
			return null;
		}

		try
		{
			if ($userId <= 0 || empty($this->filterNonTechnicalUserIds([$userId])))
			{
				return null;
			}
			$response = (new NetworkClient())->getProfileContacts(new User(id: $userId));
			$contacts = $response['result'] ?? null;
			if (!is_array($contacts))
			{
				return null;
			}
			$email = $contacts['EMAIL'] ?? null;
			$phone = $contacts['PHONE'] ?? null;
			$email = is_string($email) ? trim($email) : '';
			$phone = is_string($phone) ? trim($phone) : '';
			if ($email === '' && $phone === '')
			{
				return null;
			}

			return [
				'email' => $email !== '' ? $email : null,
				'phone' => $phone !== '' ? $phone : null,
			];
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function canRead(): bool
	{
		try
		{
			if (!Loader::includeModule('bitrix24'))
			{
				return false;
			}

			$currentUser = CurrentUser::get();

			return $currentUser->isAuthorized() && $currentUser->isAdmin();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * @param list<int> $userIds
	 * @return list<int>
	 */
	private function filterNonTechnicalUserIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			$userIds,
			static fn(mixed $userId): bool => is_int($userId) && $userId > 0,
		)));
		if (empty($userIds))
		{
			return [];
		}

		return $this->userRepository->findUsersByIds($userIds)
			->filter(static fn(User $user): bool => !in_array(
				$user->getExternalAuthId(), self::EXCLUDED_EXTERNAL_AUTH_IDS, true,
			))
			->getIds()
		;
	}
}
