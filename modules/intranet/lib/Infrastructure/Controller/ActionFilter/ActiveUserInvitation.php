<?php

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Intranet\Contract\Repository\UserRepository;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Public\Type\Collection\InvitationCollection;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\ExternalAuthType;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet\Component\UserProfile;
use Bitrix\Intranet\Service\UserService;

class ActiveUserInvitation extends Engine\ActionFilter\Base
{
	public function __construct(
		private UserRepository $userRepository,
	)
	{
		parent::__construct();
	}

	final public function onBeforeAction(Event $event)
	{
		Loc::loadLanguageFile($_SERVER["DOCUMENT_ROOT"] . '/bitrix/modules/intranet/lib/invitation/register.php');

		/** @var \Bitrix\Main\Engine\Action $action */
		$action = $event->getParameter('action');
		$arguments = $action->getArguments();
		/** @var \Bitrix\Intranet\Public\Type\Collection\InvitationCollection $invitationCollection */
		$invitationCollection = $this->extractInvitationCollection($arguments);

		if (!$invitationCollection)
		{
			return null;
		}
		$emailInvitation = $invitationCollection->filter(fn ($invitation) => $invitation instanceof \Bitrix\Intranet\Public\Type\EmailInvitation);
		$phoneInvitation = $invitationCollection->filter(fn ($invitation) => $invitation instanceof \Bitrix\Intranet\Public\Type\PhoneInvitation);
		$emailLogins = $emailInvitation->map(fn($invitation) => $invitation->getEmail());
		$phoneLogins = $phoneInvitation->map(fn($invitation) => $invitation->getPhone());

		$emailUserCollection = $this->userRepository->findActivatedUsersByLogins(
			$emailLogins,
			(new ExternalAuthType())->getAllTypeList(),
		);

		$firedUserList = [];

		if (!$emailUserCollection->empty())
		{
			$emailList = $this
				->filterUserCollectionByActivity($emailUserCollection, true)
				->map(fn($user) => $user->getLogin())
			;

			$mapFields = ['id', 'login', 'email', 'name', 'photo', 'role', 'phoneNumber', 'position', 'profileUrl'];
			$firedUserList = $this
				->filterUserCollectionByActivity($emailUserCollection, false)
				->map(fn(User $user) => $this->mapUserFields($user, $mapFields))
			;

			if (!empty($emailList))
			{
				$this->addError(new Error(
					Loc::getMessage("INTRANET_INVITATION_USER_EXIST_ERROR", [
						"#EMAIL_LIST#" => implode(', ', $emailList),
					]),
					'EMAIL_EXIST_ERROR',
					[
						'emailList' => $emailList,
					],
				));
			}
		}

		$phoneUserCollection = $this->userRepository->findActivatedUsersByLogins(
			$phoneLogins,
			(new ExternalAuthType())->getAllTypeList(),
		);

		if (!$phoneUserCollection->empty())
		{
			$phoneList = $this
				->filterUserCollectionByActivity($phoneUserCollection, true)
				->map(fn($user) => $user->getLogin())
			;

			$mapFields = ['id', 'login', 'name', 'photo', 'role', 'phoneNumber', 'position', 'profileUrl'];
			$firedUserList = array_merge($firedUserList,
				$this
					->filterUserCollectionByActivity($phoneUserCollection, false)
					->map(fn(User $user) => $this->mapUserFields($user, $mapFields))
			);

			if (!empty($phoneList))
			{
				$this->addError(new Error(
					Loc::getMessage("INTRANET_INVITATION_USER_PHONE_EXIST_ERROR", [
						"#PHONE_LIST#" => implode(', ', $phoneList),
					]),
					'PHONE_EXIST_ERROR',
					['phoneList' => $phoneList],
				));
			}
		}

		if ($this->errorCollection->isEmpty())
		{
			$firedUserListLogins = array_map(fn($user) => $user['login'], $firedUserList);

			$invitationCollection = $invitationCollection->filter(
				fn($invitation) => !in_array($invitation->getLogin(), $firedUserListLogins, true)
			);

			$arguments['invitationCollection'] = $invitationCollection;
			$arguments['firedUserList'] = $firedUserList;

			$action->setArguments($arguments);

			return null;
		}

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function extractInvitationCollection(array $arguments): ?InvitationCollection
	{
		foreach ($arguments as $argument)
		{
			if ($argument instanceof InvitationCollection)
			{
				return $argument;
			}
		}

		return null;
	}

	private function filterUserCollectionByActivity(UserCollection $userCollection, bool $isActive): UserCollection
	{
		return $userCollection
			->filter(fn(User $user) => $user->getActive() === $isActive);
	}

	private function mapUserFields(User $user, array $fields): array
	{
		$userData = [];

		foreach ($fields as $field)
		{
			switch ($field)
			{
				case 'id':
					$userData['id'] = $user->getId();
					break;
				case 'login':
					$userData['login'] = $user->getLogin();
					break;
				case 'email':
					$userData['email'] = $user->getEmail();
					break;
				case 'name':
					$userData['name'] = $user->getFormattedName();
					break;
				case 'photo':
					$userData['photo'] = UserProfile::getUserPhoto($user->getPersonalPhoto(), 40);
					break;
				case 'role':
					$userData['role'] = $user->getRole();
					break;
				case 'phoneNumber':
					$userData['phoneNumber'] = $user->getPhoneNumber();
					break;
				case 'position':
					$userData['position'] = $user->getWorkPosition();
					break;
				case 'profileUrl':
					$userData['profileUrl'] = (new UserService())->getDetailUrl($user->getId());
					break;
			}
		}

		return $userData;
	}
}