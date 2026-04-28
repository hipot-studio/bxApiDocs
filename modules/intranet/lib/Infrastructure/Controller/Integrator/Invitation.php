<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\Integrator;

use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\CloudPortalControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\IntegratorEmailControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\IntegratorLimitControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InviteLimitControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\PortalCreatorEmailConfirmationControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\UserEmailControl;
use Bitrix\Intranet\Public\Command\Integrator\InviteCommand;
use Bitrix\Intranet\ActionFilter\UserType;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InviteIntranetAccessControl;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;

class Invitation extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new UserType(['employee']),
			new InviteIntranetAccessControl(),
			new CloudPortalControl(),
			new PortalCreatorEmailConfirmationControl(),
			new InviteLimitControl(),
			new IntegratorLimitControl(),
		];
	}

	public function configureActions(): array
	{
		return [
			...parent::configureActions(),
			'send' => [
				'+prefilters' => [
					new UserEmailControl($this->request->get('integratorEmail')),
					new IntegratorEmailControl($this->request->get('integratorEmail')),
				],
			],
		];
	}

	public function sendAction(string $integratorEmail, array $partnerData = []): AjaxJson
	{
		$result = (new InviteCommand($integratorEmail, $partnerData))->run();

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection());
		}

		return AjaxJson::createSuccess($result->getData());
	}
}
