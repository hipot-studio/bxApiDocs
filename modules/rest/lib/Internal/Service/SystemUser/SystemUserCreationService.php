<?php

namespace Bitrix\Rest\Internal\Service\SystemUser;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Event;
use Bitrix\Rest\Internal\Entity\SystemUser;
use Bitrix\Rest\Internal\Entity\User;
use Bitrix\Rest\Internal\Exceptions\Service\SystemUser\UserNotFoundException;
use Bitrix\Rest\Internal\Entity\SystemUser\AccountType;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Integration\HumanResources\MemberService;
use Bitrix\Rest\Internal\Repository\SystemUser\SystemUserRepository;
use Bitrix\Rest\Internal\Repository\User\UserRepository;

final class SystemUserCreationService
{
	public function __construct(
		private readonly SystemUserRepository $systemUserRepository,
		private readonly UserRepository $userRepository,
		private readonly UserCredentialsGenerator $userCredentialsGenerator,
		private readonly MemberService $memberService,
	)
	{
	}

	public function createForApplication(int $originalUserId, string $applicationName): SystemUser
	{
		return $this->createSystemUser(
			originalUserId: $originalUserId,
			resourceType: ResourceType::APPLICATION,
			applicationName: $applicationName
		);
	}

	public function createForWebhook(int $originalUserId): SystemUser
	{
		return $this->createSystemUser(
			originalUserId: $originalUserId,
			resourceType: ResourceType::WEBHOOK,
			applicationName: null
		);
	}

	private function createSystemUser(
		int $originalUserId,
		ResourceType $resourceType,
		?string $applicationName,
		AccountType $accountType = AccountType::AUTO,
	): SystemUser
	{
		/** @var SystemUser|null $systemUser */
		$systemUser = $this->systemUserRepository->getByResourceIdAndResourceType($originalUserId, $resourceType);
		if ($systemUser !== null)
		{
			return $systemUser;
		}

		if ($applicationName !== null)
		{
			$applicationName = htmlspecialcharsbx($applicationName);
		}

		/** @var User|null $originalUser */
		$originalUser = $this->userRepository->getById($originalUserId);
		if ($originalUser === null)
		{
			throw new UserNotFoundException();
		}

		$groupIds = $originalUser->getGroupIds() ?: [];

		$newUser = new User(
			active: true,
			login: $this->userCredentialsGenerator->generateLogin(),
			email: $this->userCredentialsGenerator->generateEmail(),
			name: $resourceType === ResourceType::APPLICATION ? $applicationName : $originalUser->getName(),
			lastName: $resourceType === ResourceType::APPLICATION ? '' : $originalUser->getLastName(),
			password: $this->userCredentialsGenerator->generatePasswordByGroupsIds($groupIds),
			timeZone: $originalUser->getTimeZone(),
			languageId: $originalUser->getLanguageId(),
			groupIds: $groupIds,
			adminNotes: 'Created as copy of user with ID ' . $originalUserId .
				($resourceType === ResourceType::APPLICATION ? ' for "' . $applicationName .'" application' : ' for webhooks'),
			externalAuthId: 'rest_system',
		);

		$this->userRepository->save($newUser);

		\CEventLog::Log(
			\CEventLog::SEVERITY_SECURITY,
			'USER_REGISTER',
			'rest',
			$newUser->getId(),
			json_encode([
				'originalUserId' => $originalUserId,
				'newUserId' => $newUser->getId(),
			])
		);

		try {
			$systemUser = new SystemUser(null, $newUser->getId(), $accountType, $resourceType, $originalUserId);
			$this->systemUserRepository->save($systemUser);

			$event = new \Bitrix\Main\Event('rest', 'onSystemUserCreated', [
				'originalUserId' => $originalUserId,
				'newUserId' => $newUser->getId(),
				'resourceType' => $resourceType->value,
				'applicationName' => $applicationName,
			]);
			$event->send();

			$this->memberService->clone($originalUserId, $newUser->getId());
		}
		catch (DuplicateEntryException)
		{
			$systemUser = $this->systemUserRepository->getByResourceIdAndResourceType($newUser->getId(), $resourceType);
		}

		return $systemUser;
	}
}