<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Factory\InvitationLinkFacadeFactory;
use Bitrix\Intranet\Public\Facade\Invitation\InvitationLinkFacade;
use Bitrix\Intranet\Public\Provider\Portal;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SystemException;

class AuthRegistrationComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			LocalRedirect('/');
		}
		$request = \Bitrix\Main\Context::getCurrent()->getRequest();

		if ($request->get('login') === 'yes')
		{
			LocalRedirect('/?login=yes');
		}

		try
		{
			$invitationLinkFacade = (new InvitationLinkFacadeFactory($request->get('invite_token') ?? ''))->create();
			$invitationLinkFacade->checkAccess();
			if (CurrentUser::get()->isAuthorized())
			{
				$user = (new UserRepository())->getUserById(CurrentUser::get()->getId());

				if ($user)
				{
					$invitationLinkFacade->processAuthUser($user);
				}

				LocalRedirect('/');
			}

			if ($request->isPost() && $request->getPost('Register'))
			{
				$user = $this->createUser($request->getValues());
				$this->processRegistration($invitationLinkFacade, $user);
			}
		}
		catch (AccessDeniedException $exception)
		{
			LocalRedirect('/?login=yes');
		}
		catch (SystemException $exception)
		{
			$this->arResult['AUTH_RESULT'] = $exception->getMessage();
		}

		$this->arResult['HOST_NAME'] = (new Portal\DomainProvider())->getHostName();

		$this->includeComponentTemplate();
	}

	protected function processRegistration(
		InvitationLinkFacade $invitationLinkFacade,
		User $user,
	): void
	{
		try
		{
			$registeredUser = $invitationLinkFacade->register($user);

			if ($registeredUser->getActive())
			{
				LocalRedirect('/');

				return;
			}

			$this->arResult['AUTH_RESULT'] = [
				'TYPE' => 'OK',
				'ID' => $registeredUser->getId(),
			];
		}
		catch (SystemException $exception)
		{
			if (!$this->isSingleEmailExistsError($exception, $user))
			{
				throw $exception;
			}

			$this->arResult['AUTH_RESULT'] = [
				'TYPE' => 'ERROR',
				'MESSAGE' => $this->getEmailExistsMessage($user),
				'LOGIN_URL' => SITE_DIR . 'auth/?login=yes',
			];
		}
	}

	protected function getEmailExistsMessage(User $user): string
	{
		return Loc::getMessage('USER_WITH_EMAIL_EXIST', [
			'#EMAIL#' => htmlspecialcharsbx($user->getEmail()),
		]) ?? '';
	}

	private function isSingleEmailExistsError(SystemException $exception, User $user): bool
	{
		$emailExistsMessage = $this->getEmailExistsMessage($user);
		$registrationError = $exception->getMessage();
		if (str_ends_with($registrationError, '<br>'))
		{
			$registrationError = substr($registrationError, 0, -4);
		}

		return $emailExistsMessage !== '' && $registrationError === $emailExistsMessage;
	}

	private function createUser($userData): User
	{
		return new User(
			login: $userData['USER_LOGIN'] ?? '',
			email: $userData['USER_EMAIL'] ?? '',
			name: $userData['USER_NAME'] ?? '',
			lastName: $userData['USER_LAST_NAME'] ?? '',
			authPhoneNumber: $userData['USER_PHONE_NUMBER'] ?? '',
			lid: SITE_ID,
			password: $userData['USER_PASSWORD'] ?? '',
		);
	}
}
