<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Integrator;

use Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator\PartnerInfo;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CIntranetInviteDialog;

class InviteHandler
{
	public function __invoke(InviteCommand $command): Result
	{
		$result = new Result();

		$error = '';
		$newIntegratorId = CIntranetInviteDialog::inviteIntegrator(
			SITE_ID,
			$command->integratorEmail,
			Loc::getMessage("INTRANET_INTEGRATOR_INVITE_HANDLER_INVITE_TEXT"),
			$error,
		);

		if (!empty($error))
		{
			$result->addError(new Error($error));
		}
		else
		{
			(new PartnerInfo())->addByResponseAndUserId(
				$command->partnerData,
				(int)$newIntegratorId,
			);
			CIntranetInviteDialog::logAction($newIntegratorId, 'intranet', 'invite_user', 'integrator_dialog');

			$result->setData(['newIntegratorId' => $newIntegratorId]);
		}

		return $result;
	}
}
