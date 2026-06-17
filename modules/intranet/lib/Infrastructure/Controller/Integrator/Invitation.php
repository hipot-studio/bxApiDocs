<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\Integrator;

use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\CloudPortalControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\IntegratorEmailControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\IntegratorLimitControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\InviteLimitControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\PortalCreatorEmailConfirmationControl;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\UserEmailControl;
use Bitrix\Intranet\Internal\Service\Invitation\Analytics;
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
		$analytics = new Analytics();

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection());
		}

		$analytics->sendInvitation(
			(int)$result->getData()['newIntegratorId'],
			Analytics::ANALYTIC_INVITATION_TYPE_C_SUB_SECTION_INTEGRATOR,
			true,
			1,
		);

		return AjaxJson::createSuccess($result->getData());
	}
}
