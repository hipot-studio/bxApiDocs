<?php

namespace Bitrix\Rest\Internal\Service\SystemUser;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Rest\Internal\Entity\SystemUser;
use Bitrix\Rest\Internal\Entity\User;
use Bitrix\Rest\Internal\Exceptions\Service\SystemUser\UserNotFoundException;
use Bitrix\Rest\Internal\Entity\SystemUser\AccountType;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Integration\HumanResources\MemberService;
use Bitrix\Rest\Internal\Repository\SystemUser\SystemUserRepository;
use Bitrix\Rest\Internal\Repository\User\UserRepository;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Public\Event\SystemUser\SystemUserActivatedEvent;
use Bitrix\Rest\Public\Event\SystemUser\SystemUserCreatedEvent;
use Bitrix\Rest\Public\Event\SystemUser\SystemUserDeactivatedEvent;

final class SystemUserCreationService
{
	public function __construct(
		private readonly SystemUserRepository $systemUserRepository,
		private readonly UserRepository $userRepository,
		private readonly UserCredentialsGenerator $userCredentialsGenerator,
		private readonly MemberService $memberService,
		private readonly SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),
	)
	{
	}

	public function createForApplication(int $originalUserId, int $applicationId, string $applicationName): SystemUser
	{
		$applicationName = htmlspecialcharsbx($applicationName);

		return $this->createSystemUser(
			originalUserId: $originalUserId,
			resourceType: ResourceType::APPLICATION,
			resourceId: $applicationId,
			name: $applicationName,
			lastName: '',
		);
	}

	public function createForWebhook(int $originalUserId): SystemUser
	{
		return $this->createSystemUser(
			originalUserId: $originalUserId,
			resourceType: ResourceType::WEBHOOK,
			resourceId: $originalUserId,
		);
	}

	private function createSystemUser(
		int $originalUserId,
		ResourceType $resourceType,
		int $resourceId,
		AccountType $accountType = AccountType::AUTO,
		?string $name = null,
		?string $lastName = null,
		?string $notes = null,
	): SystemUser
	{
		/** @var SystemUser|null $systemUser */
		$systemUser = $this->systemUserRepository->getByResourceIdAndResourceType($resourceId, $resourceType);
		$originalUser = $this->getOriginalUser($originalUserId);
		if ($systemUser !== null)
		{
			$this->activateSystemUser($systemUser, $originalUser);

			return $systemUser;
		}

		$groupIds = $originalUser->getGroupIds() ?: [];

		$newUser = new User(
			active: true,
			login: $this->userCredentialsGenerator->generateLogin(),
			email: $this->userCredentialsGenerator->generateEmail(),
			name: is_null($name) ? $originalUser->getName() : $name,
			lastName: is_null($lastName) ? $originalUser->getLastName() : $lastName,
			password: $this->userCredentialsGenerator->generatePasswordByGroupsIds($groupIds),
			timeZone: $originalUser->getTimeZone(),
			languageId: $originalUser->getLanguageId(),
			groupIds: $groupIds,
			adminNotes: $notes,
			externalAuthId: 'rest_system',
		);

		$this->userRepository->save($newUser);

		$this->securityAuditLogger->logSystemUserRegistered(
			newUserId: (int)$newUser->getId(),
			originalUserId: $originalUserId,
			resourceId: $resourceId,
			resourceType: $resourceType->value,
		);

		try {
			$systemUser = new SystemUser(null, $newUser->getId(), $accountType, $resourceType, $resourceId);
			$this->systemUserRepository->save($systemUser);

			$event = new SystemUserCreatedEvent($systemUser, $originalUser);
			$event->send();

			$this->memberService->clone($originalUserId, $newUser->getId());
		}
		catch (DuplicateEntryException)
		{
			$systemUser = $this->systemUserRepository->getByResourceIdAndResourceType($newUser->getId(), $resourceType);
		}

		return $systemUser;
	}

	private function deactivateSystemUser(SystemUser $systemUser): void
	{
		$user = new User(
			id: $systemUser->getUserId(),
			active: false,
		);

		$this->userRepository->save($user);

		$this->securityAuditLogger->logSystemUserDeactivated(
			systemUserId: (int)$systemUser->getUserId(),
			resourceId: (int)$systemUser->getResourceId(),
			resourceType: $systemUser->getResourceType()?->value ?? '',
		);
	}

	public function deactivateForApplication(int $applicationId): void
	{
		$systemUser = $this->systemUserRepository->getByResourceIdAndResourceType($applicationId, ResourceType::APPLICATION);
		if (!$systemUser)
		{
			return;
		}

		$this->deactivateSystemUser($systemUser);

		$event = new SystemUserDeactivatedEvent($systemUser);
		$event->send();
	}

	private function activateSystemUser(SystemUser $systemUser, ?User $originalUser = null): void
	{
		$user = new User(
			id: $systemUser->getUserId(),
			active: true,
			timeZone: $originalUser?->getTimeZone(),
			languageId: $originalUser?->getLanguageId(),
			groupIds: $originalUser?->getGroupIds(),
		);

		$this->userRepository->save($user);

		$this->securityAuditLogger->logSystemUserActivated(
			systemUserId: (int)$systemUser->getUserId(),
			resourceId: (int)$systemUser->getResourceId(),
			resourceType: $systemUser->getResourceType()?->value ?? '',
			originalUserId: $originalUser?->getId(),
		);

		$event = new SystemUserActivatedEvent($systemUser, $originalUser);
		$event->send();
	}

	private function getOriginalUser(int $originalUserId): User
	{
		/** @var User|null $originalUser */
		$originalUser = $this->userRepository->getById($originalUserId);
		if ($originalUser === null)
		{
			throw new UserNotFoundException();
		}

		return $originalUser;
	}
}
