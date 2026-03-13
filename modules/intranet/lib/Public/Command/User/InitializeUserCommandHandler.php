<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Integration\Socialnetwork\Collab\CollabProviderData;
use Bitrix\Intranet\Internal\Entity\IntranetUser;
use Bitrix\Intranet\Internal\Factory\Message\CollabJoinMessageFactory;
use Bitrix\Intranet\Internal\Repository\IntranetUserRepository;
use Bitrix\Intranet\Internals\InvitationTable;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Intranet\Integration;

class InitializeUserCommandHandler
{
	private IntranetUserRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(IntranetUserRepository::class);
	}

	public function __invoke(InitializeUserCommand $command): IntranetUser
	{
		$intranetUser = new IntranetUser();
		$intranetUser->setUserId($command->userId);
		$intranetUser->setInitialized(true);

		$this->repository->save($intranetUser);
		$userId = $command->userId;

		if (IsModuleInstalled('bitrix24'))
		{
			\CIntranetNotify::NewUserMessage($userId);
			Integration\Tasks::createDemoTasksForUser($userId);

			if (defined('BX_COMP_MANAGED_CACHE'))
			{
				Application::getInstance()->getTaggedCache()->clearByTag('USER_CARD');
			}
		}
		else
		{
			$dbUser = \CUser::GetByID($userId);
			if ($arUser = $dbUser->Fetch())
			{
				\CIntranetEventHandlers::OnAfterUserAdd($arUser);
			}
		}

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

		return $intranetUser;
	}
}
