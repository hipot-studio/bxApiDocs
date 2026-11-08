<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\Integration;
use Bitrix\Intranet\Integration\Socialnetwork\Collab\CollabProviderData;
use Bitrix\Intranet\Internal\Entity\IntranetUser;
use Bitrix\Intranet\Internal\Factory\Message\CollabJoinMessageFactory;
use Bitrix\Intranet\Internal\Repository\IntranetUserRepository;
use Bitrix\Intranet\Internals\InvitationTable;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;

class InitializeUserCommandEffectService
{
	private IntranetUserRepository $repository;
	private UserInitializationErrorLogger $errorLogger;

	public function __construct(
		?IntranetUserRepository $repository = null,
		?UserInitializationErrorLogger $errorLogger = null,
	)
	{
		$this->repository = $repository ?? ServiceLocator::getInstance()->get(IntranetUserRepository::class);
		$this->errorLogger = $errorLogger ?? new UserInitializationErrorLogger();
	}

	public function saveInitializedUser(IntranetUser $intranetUser): void
	{
		$this->repository->save($intranetUser);
	}

	public function isCloud(): bool
	{
		return IsModuleInstalled('bitrix24');
	}

	public function notifyNewUser(int $userId): void
	{
		\CIntranetNotify::NewUserMessage($userId);
	}

	public function createDemoTasks(int $userId): void
	{
		$result = Integration\Tasks::createDemoTasksForUser($userId);
		if (!$result->isSuccess())
		{
			$this->errorLogger->logResultErrors($result, $userId);
		}
	}

	public function clearUserCardCache(): void
	{
		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			Application::getInstance()->getTaggedCache()->clearByTag('USER_CARD');
		}
	}

	public function initializeBoxUser(int $userId): void
	{
		$dbUser = \CUser::GetByID($userId);
		if ($arUser = $dbUser->Fetch())
		{
			\CIntranetEventHandlers::OnAfterUserAdd($arUser);
		}
	}

	public function handleAfterInitialization(int $userId): void
	{
		$res = InvitationTable::query()
			->setFilter([
				'USER_ID' => $userId,
				'INITIALIZED' => 'N',
			])
			->setSelect(['ID', 'INVITATION_TYPE', 'IS_MASS', 'IS_DEPARTMENT', 'IS_INTEGRATOR', 'IS_REGISTER'])
			->setLimit(1)
			->setOrder(['DATE_CREATE' => 'DESC'])
		;

		$invitationFields = $res->fetch();

		if ($invitationFields)
		{
			InvitationTable::update($invitationFields['ID'], [
				'INITIALIZED' => 'Y',
			]);
		}

		$user = (new UserRepository())->getUserById($userId);

		if ($user && $user->isCollaber())
		{
			$userCollab = (new CollabProviderData())->getUserCollabCollection($user);
			foreach ($userCollab as $collab)
			{
				(new CollabJoinMessageFactory(
					$user,
					$collab,
				))
					->createEmailEvent()
					->sendImmediately()
				;
			}
		}

		(new Event('intranet', 'onUserFirstInitialization', [
			'invitationFields' => $invitationFields,
			'userId' => $userId,
		]))->send();
	}
}
