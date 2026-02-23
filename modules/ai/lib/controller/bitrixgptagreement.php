<?php

declare(strict_types=1);

namespace Bitrix\AI\Controller;

use Bitrix\AI\Controller\ActionFilter\CheckBitrixGptAgreementAccess;
use Bitrix\AI\Services\BitrixGptAgreementService;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;

class BitrixGptAgreement extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
			new CheckBitrixGptAgreementAccess(),
		];
	}

	public function acceptAction(BitrixGptAgreementService $agreementService): void
	{
		$agreementService->acceptAgreement();
	}

	public function declineAction(BitrixGptAgreementService $agreementService): void
	{
		$agreementService->declineAgreement();
	}

	public function skipAction(BitrixGptAgreementService $agreementService): void
	{
		$agreementService->skipAgreementPopup();
	}

	public function getPopupDataAction(BitrixGptAgreementService $agreementService): ?array
	{
		return $agreementService->onSaveAISettings();
	}
}
