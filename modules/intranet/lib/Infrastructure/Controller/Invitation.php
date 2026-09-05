<?php

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\UserType;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Exception\InvitationFailedException;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\ActiveUserInvitation;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InvitationDeliveryLimit;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InviteLimitControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\UserInvitedExtranet;
use Bitrix\Intranet\Infrastructure\Controller\AutoWire\DepartmentParameterTrait;
use Bitrix\Intranet\Public\Facade\Invitation\IntranetInvitationFacade;
use Bitrix\Intranet\Public\Service\InvitationService;
use Bitrix\Intranet\Public\Service\RegistrationService;
use Bitrix\Intranet\Public\Type\BaseInvitation;
use Bitrix\Intranet\Public\Type\Collection\InvitationCollection;
use Bitrix\Intranet\Public\Type\Collection\InvitationDeliveryCollection;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\ModuleManager;

class Invitation extends Controller
{
	use DepartmentParameterTrait;

	protected function getDefaultPreFilters()
	{
		$preFilters = parent::getDefaultPreFilters();
		$preFilters[] = new UserType(['employee', 'extranet']);
		$preFilters[] = new ActionFilter\InviteIntranetAccessControl();

		return $preFilters;
	}

	public function configureActions(): array
	{
		return [
			...parent::configureActions(),
			'inviteUsers' => [
				'+prefilters' => [
					new InviteLimitControl(),
					new ActiveUserInvitation(new UserRepository()),
					new UserInvitedExtranet(new UserRepository()),
				],
			],
			'inviteUsersWithDeliveryResult' => [
				'+prefilters' => [
					new InvitationDeliveryLimit(),
					new UserInvitedExtranet(new UserRepository()),
					new HttpMethod([
						HttpMethod::METHOD_POST,
					]),
				],
			],
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			$this->createDepartmentCollectionParameter(),
			new ExactParameter(
				InvitationCollection::class,
				'invitations',
				function($className, array $invitations) {
					$collection = new InvitationCollection();
					$isPhoneInvitationAvailable = ModuleManager::isModuleInstalled('bitrix24');

					foreach ($invitations as $invitation)
					{
						$email = $invitation['email'] ?? null;

						if ($email)
						{
							$emailInvitation = new EmailInvitation(
								$email,
								$invitation['name'] ?? null,
								$invitation['lastName'] ?? null,

							);
							$collection->add($emailInvitation);

							continue;
						}

						$phoneNumber = $invitation['phoneNumber'] ?? $invitation['phone'] ?? null;

						if ($phoneNumber && $isPhoneInvitationAvailable)
						{
							$phoneInvitation = new PhoneInvitation(
								$phoneNumber,
								$invitation['name'] ?? null,
								$invitation['lastName'] ?? null,
								$invitation['phoneCountry'] ?? null,
							);
							$collection->add($phoneInvitation);
						}
					}

					return $collection;
				}
			),
			new ExactParameter(
				InvitationDeliveryCollection::class,
				'deliveryInvitations',
				function($className, array $invitations) {
					return $this->createInvitationDeliveryCollection(
						$invitations,
						ModuleManager::isModuleInstalled('bitrix24'),
					);
				},
			),
		];
	}

	private function createInvitationDeliveryCollection(
		array $invitations,
		bool $isPhoneInvitationAvailable,
	): InvitationDeliveryCollection
	{
		$collection = new InvitationDeliveryCollection();
		foreach ($invitations as $invitation)
		{
			$clientId = is_array($invitation) && is_string($invitation['clientId'] ?? null)
				? $invitation['clientId']
				: ''
			;
			$collection->addWithClientId(
				$this->createInvitationFromRequestItem($invitation, $isPhoneInvitationAvailable),
				$clientId,
			);
		}

		return $collection;
	}

	private function createInvitationFromRequestItem(
		mixed $invitation,
		bool $isPhoneInvitationAvailable,
	): BaseInvitation
	{
		if (!is_array($invitation))
		{
			return new EmailInvitation('');
		}

		$name = is_string($invitation['name'] ?? null) ? $invitation['name'] : null;
		$lastName = is_string($invitation['lastName'] ?? null) ? $invitation['lastName'] : null;
		$email = $invitation['email'] ?? null;
		if (is_string($email) && $email !== '')
		{
			$languageId = is_string($invitation['languageId'] ?? null) ? $invitation['languageId'] : null;

			return new EmailInvitation($email, $name, $lastName, languageId: $languageId);
		}

		$phoneNumber = $invitation['phoneNumber'] ?? $invitation['phone'] ?? null;
		if (is_string($phoneNumber) && $phoneNumber !== '' && $isPhoneInvitationAvailable)
		{
			$phoneCountry = is_string($invitation['phoneCountry'] ?? null) ? $invitation['phoneCountry'] : null;

			return new PhoneInvitation($phoneNumber, $name, $lastName, $phoneCountry);
		}

		return new EmailInvitation('');
	}

	public function inviteUsersAction(
		InvitationCollection $invitations,
		?DepartmentCollection $departmentCollection,
	): ?array
	{
		$invitedUsers = $this->inviteUsers($invitations, $departmentCollection);

		if (is_array($invitedUsers))
		{
			$this->setDefaultUserGroups($invitedUsers);
		}

		return $invitedUsers;
	}

	public function inviteUsersWithDeliveryResultAction(
		InvitationDeliveryCollection $deliveryInvitations,
		?DepartmentCollection $departmentCollection = null,
	): array
	{
		$deliveryItems = $this->createDeliveryItems($deliveryInvitations);

		new IntranetInvitationFacade($departmentCollection);
		$invitationService = new InvitationService(
			new UserRepository(),
			RegistrationService::createByIntranet(),
		);
		$deliveryResult = $invitationService->inviteByCollectionWithDeliveryResult($deliveryItems);

		$users = [];
		foreach ($deliveryResult['users'] as $user)
		{
			$users[] = ['id' => $user->getId()];
		}
		$this->setDefaultUserGroups($users);

		return [
			'items' => $deliveryResult['items'],
			'quota' => $deliveryResult['quota'],
		];
	}

	private function createDeliveryItems(InvitationDeliveryCollection $invitations): array
	{
		$clientIds = $invitations->getClientIds();
		if ($invitations->count() !== count($clientIds))
		{
			throw new ArgumentException('Every invitation must have a matching client ID.');
		}

		$deliveryItems = [];
		$usedClientIds = [];
		foreach ($invitations as $index => $invitation)
		{
			if (!is_string($clientIds[$index] ?? null) || $clientIds[$index] === '')
			{
				throw new ArgumentException('Every invitation must have a non-empty client ID.');
			}
			if (isset($usedClientIds[$clientIds[$index]]))
			{
				throw new ArgumentException('Every invitation must have a unique client ID.');
			}
			$usedClientIds[$clientIds[$index]] = true;

			$deliveryItems[] = [
				'clientId' => $clientIds[$index],
				'invitation' => $invitation,
			];
		}

		return $deliveryItems;
	}

	private function inviteUsers(
		InvitationCollection $emailInvitations,
		?DepartmentCollection $departmentCollection,
	): ?array
	{
		try
		{
			$invitationFacade = new IntranetInvitationFacade($departmentCollection);
			$userCollection = $invitationFacade->inviteByCollection($emailInvitations);

			$response = [];
			foreach ($userCollection as $user)
			{
				$response[] = [
					'id' => $user->getId(),
					'login' => $user->getLogin(),
					'email' => $user->getEmail(),
					'authPhoneNumber' => $user->getAuthPhoneNumber(),
					'name' => $user->getName(),
					'lastName' => $user->getLastName(),
					'fullName' => $user->getFormattedName(),
					'invitationStatus' => $user->getInviteStatus()->value,
				];
			}

			return $response;
		}
		catch (InvitationFailedException $exception)
		{
			$this->addErrors($exception->getErrors()->toArray());

			return null;
		}
	}

	private function setDefaultUserGroups(array $users): void
	{
		$groupsIds = \CIntranetInviteDialog::getUserGroups(SITE_ID);

		foreach ($users as $user)
		{
			if (isset($user['id']))
			{
				\CUser::SetUserGroup($user['id'], $groupsIds);
			}
		}
	}
}
