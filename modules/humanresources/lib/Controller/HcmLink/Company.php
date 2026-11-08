<?php

namespace Bitrix\HumanResources\Controller\HcmLink;

use Bitrix\HumanResources\Engine\HcmLinkController;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\HcmLink\CompanyDisconnectService;
use Bitrix\Intranet;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

class Company extends HcmLinkController
{
	private const ERROR_DISCONNECT_FAILED = 'DISCONNECT_FAILED';

	public function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON]),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\Csrf(),
			new Main\Engine\ActionFilter\HttpMethod(
				[Main\Engine\ActionFilter\HttpMethod::METHOD_POST],
			),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function disconnectAction(int $companyId): void
	{
		if (!$this->checkDisconnectAccess())
		{
			return;
		}

		try
		{
			$result = Container::getHcmLinkCompanyDisconnectService()->disconnect($companyId);
		}
		catch (\Throwable $exception)
		{
			// A storage failure carries the failed SQL in its message. It belongs in the log, not in the
			// response: the client gets a stable code and a localized text.
			Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			$this->addError(new Main\Error(
				Loc::getMessage('HUMANRESOURCES_HCMLINK_COMPANY_DISCONNECT_FAILED'),
				self::ERROR_DISCONNECT_FAILED,
			));

			return;
		}

		if (!$result->isSuccess())
		{
			$this->addDisconnectErrors($result->getErrors());
		}
	}

	private function checkDisconnectAccess(): bool
	{
		if (!Container::getHcmLinkAccessService()->canDisconnect())
		{
			$this->addError($this->makeAccessDeniedError());

			return false;
		}

		return true;
	}

	/**
	 * The message of the service is a service one: the client gets a localized text instead and keeps
	 * the code, which is what tells "the integration is already gone" from a general failure.
	 *
	 * @param Main\Error[] $errors
	 */
	private function addDisconnectErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			if ($error->getCode() === CompanyDisconnectService::ERROR_COMPANY_NOT_FOUND)
			{
				$this->addError(new Main\Error(
					Loc::getMessage('HUMANRESOURCES_HCMLINK_COMPANY_DISCONNECT_COMPANY_NOT_FOUND'),
					CompanyDisconnectService::ERROR_COMPANY_NOT_FOUND,
				));

				continue;
			}

			$this->addError($error);
		}
	}
}
