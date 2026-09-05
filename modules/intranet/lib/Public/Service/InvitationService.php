<?php

namespace Bitrix\Intranet\Public\Service;

use Bitrix\Intranet\Command;
use Bitrix\Intranet\Contract\Repository\UserRepository;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Internal\Entity\Invitation;
use Bitrix\Intranet\Exception\ErrorCollectionException;
use Bitrix\Intranet\Public\Type\BaseInvitation;
use Bitrix\Intranet\Public\Type\Collection\InvitationCollection;
use Bitrix\Intranet\Entity\Type\InvitationsContainer;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Exception\InvitationFailedException;
use Bitrix\Intranet;
use Bitrix\Intranet\Public\Facade\Invitation\CollabInvitationFacade;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\ExternalAuthType;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Bitrix24\License;
use Bitrix\Bitrix24\LicenseScanner\Manager;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Error;
use Bitrix\SocialNetwork\Collab\Analytics\CollabAnalytics;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Item\Workgroup\Type;

class InvitationService
{
	private const INVITATION_DAILY_LIMIT_LOCK = 'intranet_invitation_daily_limit';

	private bool $isMassInviteStarted = false;

	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly RegistrationService $registrationService,
		private readonly ?\Closure $quotaSnapshotProvider = null,
	)
	{}

	/**
	 * @throws \Exception
	 */
	public function inviteByCollection(InvitationCollection $collection, ?int $currentUserId = null): UserCollection
	{
		$userCollection = new UserCollection();
		$errorCollection = new ErrorCollection();
		$this->isMassInviteStarted = true;
		foreach ($collection as $invitation)
		{
			try
			{
				$user = $this->invite($invitation, $currentUserId);
				$userCollection->add($user);
			}
			catch (ErrorCollectionException $exception)
			{
				$errorCollection->add($exception->getErrors()->getValues());
			}
			catch (\Exception $exception)
			{
				$errorCollection->setError(new Error($exception->getMessage(), $exception->getCode()));
			}
		}

		$event = new Event(
			'intranet',
			'onUserInvited',
			[
				'originatorId' => $currentUserId ?? CurrentUser::get()->getId(),
				'userId' =>$userCollection->getIds(), //is backward compatibility
				'invitedUsers' => $userCollection,
			],
		);
		$event->send();

		$this->isMassInviteStarted = false;

		if (!$errorCollection->isEmpty())
		{
			throw new InvitationFailedException($errorCollection);
		}

		return $userCollection;
	}

	/**
	 * @param array<int, array{clientId: string, invitation: BaseInvitation|null}> $items
	 * @return array{items: array<int, array{clientId: string, status: string, reason: string|null}>, users: UserCollection, quota: array{period: string, limit: int|null, used: int|null, remaining: int|null}}
	 */
	public function inviteByCollectionWithDeliveryResult(array $items, ?int $currentUserId = null): array
	{
		$this->isMassInviteStarted = true;

		try
		{
			$deliveryResult = $this->processDeliveryItems($items, $currentUserId);

			if (!$deliveryResult['users']->empty())
			{
				$event = new Event(
					'intranet',
					'onUserInvited',
					[
						'originatorId' => $currentUserId ?? CurrentUser::get()->getId(),
						'userId' => $deliveryResult['users']->getIds(), //is backward compatibility
						'invitedUsers' => $deliveryResult['users'],
					],
				);
				$event->send();
			}
		}
		finally
		{
			$this->isMassInviteStarted = false;
		}

		$quota = $this->getInvitationQuotaSnapshot();

		return [
			'items' => $deliveryResult['items'],
			'users' => $deliveryResult['users'],
			'quota' => $quota,
		];
	}

	/**
	 * @param array<int, array{clientId: string, invitation: BaseInvitation|null}> $items
	 * @return array{items: array<int, array{clientId: string, status: string, reason: string|null}>, users: UserCollection}
	 */
	private function processDeliveryItems(array $items, ?int $currentUserId): array
	{
		$userCollection = new UserCollection();
		$deliveryResultItems = [];
		$emailInvitationCount = 0;
		$maxEmailInvitationCount = $this->getMaxEmailInvitationCount();

		foreach ($items as $item)
		{
			$deliveryResult = $this->processDeliveryItemWithBatchLimit(
				$item,
				$currentUserId,
				$maxEmailInvitationCount,
				$emailInvitationCount,
			);
			$deliveryResultItems[] = $deliveryResult['item'];
			$this->addDeliveryResultUser($userCollection, $deliveryResult['user']);
		}

		return [
			'items' => $deliveryResultItems,
			'users' => $userCollection,
		];
	}

	/**
	 * @param array{clientId: string, invitation: BaseInvitation|null} $item
	 * @return array{item: array{clientId: string, status: string, reason: string|null}, user: User|null}
	 */
	private function processDeliveryItemWithBatchLimit(
		array $item,
		?int $currentUserId,
		int $maxEmailInvitationCount,
		int &$emailInvitationCount,
	): array
	{
		if ($this->isEmailBatchLimitReached($item['invitation'], $emailInvitationCount, $maxEmailInvitationCount))
		{
			return [
				'item' => [
					'clientId' => $item['clientId'],
					'status' => 'rejected',
					'reason' => 'limit',
				],
				'user' => null,
			];
		}

		return $this->processDeliveryItem($item, $currentUserId);
	}

	private function isEmailBatchLimitReached(
		?BaseInvitation $invitation,
		int &$emailInvitationCount,
		int $maxEmailInvitationCount,
	): bool
	{
		if (!$invitation instanceof EmailInvitation)
		{
			return false;
		}

		$emailInvitationCount++;

		return $emailInvitationCount > $maxEmailInvitationCount;
	}

	private function addDeliveryResultUser(UserCollection $userCollection, ?User $user): void
	{
		if ($user !== null)
		{
			$userCollection->add($user);
		}
	}

	/**
	 * @param array{clientId: string, invitation: BaseInvitation|null} $item
	 * @return array{item: array{clientId: string, status: string, reason: string|null}, user: User|null}
	 */
	private function processDeliveryItem(array $item, ?int $currentUserId): array
	{
		$invitation = $item['invitation'];
		if ($invitation === null || !$invitation->isValid())
		{
			return [
				'item' => [
					'clientId' => $item['clientId'],
					'status' => 'rejected',
					'reason' => 'validation',
				],
				'user' => null,
			];
		}

		if ($this->hasActiveUser($invitation))
		{
			return [
				'item' => [
					'clientId' => $item['clientId'],
					'status' => 'skipped',
					'reason' => 'already_active',
				],
				'user' => null,
			];
		}

		try
		{
			$user = $this->inviteWithQuotaControl($invitation, $currentUserId);
			if ($user === null)
			{
				return [
					'item' => [
						'clientId' => $item['clientId'],
						'status' => 'rejected',
						'reason' => 'limit',
					],
					'user' => null,
				];
			}

			return [
				'item' => [
					'clientId' => $item['clientId'],
					'status' => 'sent',
					'reason' => null,
				],
				'user' => $user,
			];
		}
		catch (ErrorCollectionException)
		{
			return [
				'item' => [
					'clientId' => $item['clientId'],
					'status' => 'rejected',
					'reason' => 'delivery_unavailable',
				],
				'user' => null,
			];
		}
		catch (\Exception)
		{
			return [
				'item' => [
					'clientId' => $item['clientId'],
					'status' => 'rejected',
					'reason' => 'unknown',
				],
				'user' => null,
			];
		}
	}

	private function hasActiveUser(BaseInvitation $invitation): bool
	{
		$users = $invitation->getType() === InvitationType::EMAIL
			? $this->userRepository->findUsersByLoginsAndEmails([$invitation->getLogin()])
			: $this->userRepository->findUsersByLoginsAndPhoneNumbers([$invitation->getLogin()]);

		return !$users->filter(
			static fn(User $user) => !$user->isEmail()
				&& !$user->isShop()
				&& $user->getInviteStatus() === Intranet\Enum\InvitationStatus::ACTIVE,
		)->empty();
	}

	protected function getMaxEmailInvitationCount(): int
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return 100;
		}

		$limiter = Manager::getInstance()->getInvitationDailyLimiter();

		return $limiter->getTargetValue(License::getCurrent()->getCode()) ?? 100;
	}

	private function inviteWithQuotaControl(BaseInvitation $invitation, ?int $currentUserId): ?User
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::INVITATION_DAILY_LIMIT_LOCK, 5))
		{
			throw new SystemException('Could not lock invitation daily limit.');
		}

		try
		{
			if ($this->getInvitationQuotaSnapshot()['remaining'] === 0)
			{
				return null;
			}

			return $this->invite($invitation, $currentUserId);
		}
		finally
		{
			$connection->unlock(self::INVITATION_DAILY_LIMIT_LOCK);
		}
	}

	/**
	 * @return array{period: string, limit: int|null, used: int|null, remaining: int|null}
	 */
	private function getInvitationQuotaSnapshot(): array
	{
		if ($this->quotaSnapshotProvider !== null)
		{
			return ($this->quotaSnapshotProvider)();
		}

		if (!Loader::includeModule('bitrix24'))
		{
			return [
				'period' => (new Date())->format('Y-m-d'),
				'limit' => null,
				'used' => null,
				'remaining' => null,
			];
		}

		$limiter = Manager::getInstance()->getInvitationDailyLimiter();
		$limit = $limiter->getTargetValue(License::getCurrent()->getCode());
		if ($limit === null)
		{
			return [
				'period' => (new Date())->format('Y-m-d'),
				'limit' => null,
				'used' => null,
				'remaining' => null,
			];
		}

		$used = $limiter->getCurrentValue();

		return [
			'period' => (new Date())->format('Y-m-d'),
			'limit' => $limit,
			'used' => $used,
			'remaining' => max(0, $limit - $used),
		];
	}

	/**
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function invite(BaseInvitation $invitation, ?int $currentUserId = null): User
	{
		try
		{
			Application::getConnection()->startTransaction();

			$event = new Event(
				'intranet',
				'onBeforeInviteUser',
				[
					'invitation' => $invitation,
				]
			);
			$event->send();

			$user = $this->process($invitation);

			$invitationRepository = ServiceContainer::getInstance()->invitationRepository();
			if (!$invitationRepository->getUserById($user->getId()))
			{
				$invitationRepository->save(
					new Invitation(
						userId: $user->getId(),
						initialized: false,
						isMass: $invitation->getFormType() === 'mass',
						isDepartment: $invitation->getFormType() === 'group',
						isIntegrator: $invitation->getFormType() === 'integrator',
						isRegister: $invitation->getFormType() === 'register',
						id: null,
						originatorId: $currentUserId ?? CurrentUser::get()->getId(),
						type: $invitation->getType(),
					)
				);
			}

			if (!$this->isMassInviteStarted)
			{
				$event = new Event(
					'intranet',
					'onUserInvited',
					[
						'originatorId' =>  $currentUserId ?? CurrentUser::get()->getId(),
						'userId' => [$user->getId()], //is backward compatibility
						'invitedUsers' => new UserCollection($user),
					],
				);
				$event->send();
			}
			Application::getConnection()->commitTransaction();

			return $user;
		}
		catch (\Exception $exception)
		{
			Application::getConnection()->rollbackTransaction();

			throw $exception;
		}
	}

	private function getFakeUserTypeList(): array
	{
		return (new ExternalAuthType())->getNotUserTypeList();
	}

	/**
	 * @param BaseInvitation $invitation
	 * @return User|null
	 * @throws \Exception
	 */
	private function process(BaseInvitation $invitation): ?User
	{
		$userCollection = $this->userRepository->findRealUsersByLogins(
			[$invitation->getLogin()],
			$this->getFakeUserTypeList(),
		);

		$isFirstInvitation = false;
		if (!($user = $userCollection->first()))
		{
			$isFirstInvitation = true;
			$user = (new Intranet\Internal\Repository\Mapper\UserMapper())->convertFromArray($invitation->toArray());
			$user = $this->registrationService->register($user);
			$user->setInvitedVia($invitation->getType());
		}
		else
		{
			if (
				!$user->isEmail()
				&& !$user->isShop()
				&& $user->getInviteStatus() === Intranet\Enum\InvitationStatus::ACTIVE
			)
			{
				throw new SystemException('User ('.$user->getLogin().') already exists.');
			}
		}

		$event = new Event(
			'intranet',
			'onSendInviteUser',
			[
				'invitedUser' => $user,
				'invitation' => $invitation,
				'isFirstInvitation' => $isFirstInvitation,
			]
		);
		$event->send();

		return $user;
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function inviteUsersToGroup(int $groupId, InvitationsContainer $inviteData, ?int $currentUserId = null): Result
	{
		$result = new Result();

		if (!Loader::includeModule('socialnetwork'))
		{
			throw new SystemException('Module "socialnetwork" is not installed');
		}

		$group = GroupRegistry::getInstance()->get($groupId);

		if ($group === null)
		{
			$result->addError(new Error('', 'socnetgroup_not_found'));

			return $result;
		}

		$invitationItems = $inviteData->backwardsCompatibility();
		if ($group->getType() === Type::Collab)
		{
			$invitationItems['COLLAB_GROUP'] = $group;
		}

		try
		{
			$collabInvitation = new CollabInvitationFacade($group);
			$userCollection = $collabInvitation->inviteByCollection($inviteData->getInvitationCollection(), $currentUserId);
		}
		catch (InvitationFailedException $exception)
		{
			$result->addErrors($exception->getErrors()->getValues());
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		$inviteCommand = new Command\Invitation\InviteUserCollectionToGroupCommand(
			groupId: $groupId,
			userCollection: $userCollection,
			currentUserId: $currentUserId,
		);
		$inviteToGroupResult = $inviteCommand->execute();

		if (!$inviteToGroupResult->isSuccess())
		{
			return $inviteToGroupResult;
		}

		if ($group->getType() === Type::Collab)
		{
			static::sendAnalyticsInvitationsCollabs($groupId, $inviteToGroupResult, $invitationItems);
		}

		$result->setData($userCollection->all());

		return $result;
	}

	private static function sendAnalyticsInvitationsCollabs(int $groupId, Result $inviteToGroupResult, array $invitationItems): void
	{
		/** @var InvitationType $type */
		$type = null;

		foreach ($inviteToGroupResult->getData() as $user)
		{
			$index = array_search($user->getEmail(), array_column($invitationItems['ITEMS'], 'EMAIL'));

			$type = InvitationType::PHONE;
			if ($index !== false)
			{
				$type = InvitationType::EMAIL;
			}

			CollabAnalytics::getInstance()->onUserInvitation($user->getId(), $groupId, $type->value);
		}
	}
}
