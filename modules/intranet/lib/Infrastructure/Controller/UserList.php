<?php

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Exception\ErrorCollectionException;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InviteIntranetAccessControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InviteLimitControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\PortalCreatorEmailConfirmationControl;
use Bitrix\Intranet\Infrastructure\Controller\AutoWire\DepartmentParameterTrait;
use Bitrix\Intranet\Infrastructure\Controller\AutoWire\UserCollectionParameterTrait;
use Bitrix\Intranet\Integration\HumanResources\DepartmentAssigner;
use Bitrix\Intranet\Internal\Integration\Im\ChatService;
use Bitrix\Intranet\Invitation;
use Bitrix\Intranet\Public\Facade\Invitation\ReInvitationFacade;
use Bitrix\Intranet\Public\Type\Collection\InvitationCollection;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Intranet\User;
use Bitrix\Intranet\User\Access\UserAccessController;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Intranet\User\Command\DeleteOrFireUserCommand;
use Bitrix\Intranet\User\Command\DeleteUserCommand;
use Bitrix\Intranet\User\Command\FireUserCommand;
use Bitrix\Intranet\User\Command\RestoreUserCommand;
use Bitrix\Intranet\UserTable;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Response;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use CSite;
use CUser;
use Exception;

class UserList extends Controller
{
	use UserCollectionParameterTrait;
	use DepartmentParameterTrait;

	private const DEFAULT_SELECT = ['ID', 'NAME', 'LAST_NAME', 'LOGIN', 'EMAIL', 'SECOND_NAME'];

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IntranetUser(),
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			$this->createUserCollectionParameterFromGrid(),
			$this->createDepartmentCollectionParameter(),
		];
	}

	public function configureActions(): array
	{
		return [
			'groupReInvite' => [
				'+prefilters' => [
					new InviteIntranetAccessControl(),
					new PortalCreatorEmailConfirmationControl(),
					new InviteLimitControl(),
				],
			],
		];
	}

	/**
	 * @ajaxAction intranet.v2.UserList.fire
	 * @param UserCollection $userCollection
	 * @return Response
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function fireAction(UserCollection $userCollection): Response
	{
		$access = UserAccessController::createByDefault();

		if (!$access->check(UserActionDictionary::FIRE))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROLLER_USER_LIST_NO_PERMISSIONS'), 403));

			return AjaxJson::createError(($this->errorCollection));
		}

		$originatorUsers = [];

		foreach ($userCollection as $user)
		{
			if ($user->isCurrent())
			{
				continue;
			}

			$command = new FireUserCommand($user);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				$skippedFiredUsers[$user->getId()] = $user->getFormattedName();
			}
			else
			{
				$deactivateUser = new User($user->getId());
				$originatorUser = $deactivateUser->fetchOriginatorUser();

				if (!in_array($originatorUser, $originatorUsers))
				{
					$originatorUsers[] = $originatorUser;
				}
			}
		}

		foreach ($originatorUsers as $originatorUser)
		{
			Invitation::fullSyncCounterByUser($originatorUser);
		}

		return AjaxJson::createSuccess([
			'skippedFiredUsers' => $skippedFiredUsers ?? [],
		]);
	}

	/**
	 * @ajaxAction intranet.v2.UserList.deleteOrFire
	 * @param UserCollection $userCollection
	 * @return Response
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function deleteOrFireAction(UserCollection $userCollection): Response
	{
		$access = UserAccessController::createByDefault();

		if (
			!$access->check(UserActionDictionary::DELETE)
			|| !$access->check(UserActionDictionary::FIRE)
		)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROLLER_USER_LIST_NO_PERMISSIONS'), 403));

			return AjaxJson::createError(($this->errorCollection));
		}

		foreach ($userCollection as $user)
		{
			if ($user->isCurrent())
			{
				continue;
			}

			$command = new DeleteOrFireUserCommand($user);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				$skippedFiredUsers[$user->getId()] = $user->getFormattedName();
			}
		}

		return AjaxJson::createSuccess([
			'skippedFiredUsers' => $skippedFiredUsers ?? [],
		]);
	}

	/**
	 * @ajaxAction intranet.v2.UserList.restore
	 * @param UserCollection $userCollection
	 * @return Response
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function restoreAction(UserCollection $userCollection): Response
	{
		$access = UserAccessController::createByDefault();

		if (!$access->check(UserActionDictionary::RESTORE))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROLLER_USER_LIST_NO_PERMISSIONS'), 403));

			return AjaxJson::createError(($this->errorCollection));
		}

		foreach ($userCollection as $user)
		{
			if ($user->isCurrent())
			{
				continue;
			}

			$command = new RestoreUserCommand($user);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				if ($user->getActive())
				{
					$skippedActiveUsers[$user->getId()] = $user->getFormattedName();
				}
				else
				{
					$skippedFiredUsers[$user->getId()] = $user->getFormattedName();
				}
			}
		}

		return AjaxJson::createSuccess([
			'skippedActiveUsers' => $skippedActiveUsers ?? [],
			'skippedFiredUsers' => $skippedFiredUsers ?? [],
		]);
	}

	/**
	 * @ajaxAction intranet.v2.UserList.delete
	 * @param UserCollection $userCollection
	 * @return Response
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function deleteAction(UserCollection $userCollection): Response
	{
		$access = UserAccessController::createByDefault();

		if (!$access->check(UserActionDictionary::DELETE))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROLLER_USER_LIST_NO_PERMISSIONS'), 403));

			return AjaxJson::createError(($this->errorCollection));
		}

		foreach ($userCollection as $user)
		{
			if ($user->isCurrent())
			{
				continue;
			}

			$command = new DeleteUserCommand($user);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				if ($user->getActive())
				{
					$skippedActiveUsers[$user->getId()] = $user->getFormattedName();
				{
					$this->addError(new Error('Could not decline invitation for user ID '.$user['ID']));
				}
				}
				else
				{
					$skippedFiredUsers[$user->getId()] = $user->getFormattedName();
				}
			}
		}

		return AjaxJson::createSuccess([
			'skippedActiveUsers' => $skippedActiveUsers ?? [],
			'skippedFiredUsers' => $skippedFiredUsers ?? [],
		]);
	}

	/**
	 * @ajaxAction intranet.v2.UserList.reInvite
	 * @param array $fields
	 * @return Response
	 * @throws ArgumentException
	 */
	public function reInviteAction(array $fields): Response
	{
		$res = $this->getUserList($fields, ['ACTIVE', 'CONFIRM_CODE', 'PERSONAL_MOBILE', 'UF_DEPARTMENT']);
		$skippedActiveUsers = [];
		$skippedFiredUsers = [];
		$skippedWaitingUsers = [];
		$usersToInvite['ITEMS'] = [];

		if (!$res)
		{
			return AjaxJson::createError($this->errorCollection);
		}
		$invitationCollection = new InvitationCollection();
		while ($user = $res->fetch())
		{
			if ($user['ACTIVE'] === 'N' && !empty($user['CONFIRM_CODE']))
			{
				$skippedWaitingUsers[$user['ID']] = $this->getUserFullName($user);
			}
			elseif ($user['ACTIVE'] === 'N' && empty($user['CONFIRM_CODE']))
			{
				$skippedFiredUsers[$user['ID']] = $this->getUserFullName($user);
			}
			elseif ($user['ACTIVE'] === 'N' || empty($user['CONFIRM_CODE']))
			{
				$skippedActiveUsers[$user['ID']] = $this->getUserFullName($user);
			}
			else
			{
				if (!empty($user['PERSONAL_MOBILE']))
				{
					$invitationCollection->add(
						new PhoneInvitation($user['PERSONAL_MOBILE'], $user['NAME'], $user['LAST_NAME'], formType: 'mass'),
					);
				}
				else
				{
					$invitationCollection->add(
						new EmailInvitation($user['EMAIL'], $user['NAME'], $user['LAST_NAME'], formType: 'mass'),
					);
				}
			}
		}

		if (!$invitationCollection->empty())
		{
			$invitationFacade = new ReInvitationFacade();
			try
			{
				$invitationFacade->inviteByCollection($invitationCollection);
			}
			catch (ErrorCollectionException $exception)
			{
				$this->errorCollection = $exception->getErrors();
			}
			catch (Exception $exception)
			{
				$this->addError(new Error($exception->getMessage()));
			}
		}

		return AjaxJson::createSuccess([
			'skippedActiveUsers' => $skippedActiveUsers,
			'skippedFiredUsers' => $skippedFiredUsers,
			'skippedWaitingUsers' => $skippedWaitingUsers,
		]);
	}

	/**
	 * @ajaxAction intranet.v2.UserList.createChat
	 * @param UserCollection $userCollection
	 * @return Response
	 */
	public function createChatAction(UserCollection $userCollection): Response
	{
		$chatService = new ChatService();
		$result = new Result();

		try
		{
			$result = $chatService->createGroupChatWithUsers($userCollection);

			if (!$result->isSuccess())
			{
				$this->addError($result->getError());
			}
		}
		catch (Exception $e)
		{
			$this->addError(new Error($e->getMessage()));
		}

		if ($this->hasErrors() || !($result instanceof \Bitrix\Im\V2\Result))
		{
			return AjaxJson::createError($this->errorCollection);
		}

		return AjaxJson::createSuccess($result->getResult()['CHAT_ID'] ?? null);
	}

	/**
	 * @ajaxAction intranet.v2.UserList.changeDepartment
	 * @param UserCollection $userCollection
	 * @param DepartmentCollection $departmentCollection
	 * @return Response
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function changeDepartmentAction(UserCollection $userCollection, DepartmentCollection $departmentCollection): Response
	{
		$intranetUserCollection = new UserCollection();
		$extranetUserCollection = new UserCollection();

		foreach ($userCollection as $user)
		{
			if ($user->isIntranet())
			{
				$intranetUserCollection->add($user);
			}
			else
			{
				$extranetUserCollection->add($user);
			}
		}

		$departmentAssigner = new DepartmentAssigner($departmentCollection);
		$departmentAssigner->reassignUsers($intranetUserCollection);

		return AjaxJson::createSuccess([
			'skippedActiveUsers' => $extranetUserCollection->mapToFullNames(true),
		]);
	}

	private function getUserFullName($user): array
	{
		return [
			'id' => $user['ID'],
			'fullName' => CUser::FormatName(CSite::GetNameFormat(), $user, true),
		];
	}

	private function getUserList(array $fields, array $select = [])
	{
		if (empty($fields['userIds']))
		{
			$this->addError(new Error('no selected users'));

			return null;
		}

		if ($fields['isSelectedAllRows'] === 'N')
		{
			$filter = ['@ID' => $fields['userIds']];
		}
		else
		{
			$filter = $fields['filter'];
		}

		return UserTable::getList([
			'select' => array_merge($select, self::DEFAULT_SELECT),
			'filter' => $filter,
		]);
	}
}
