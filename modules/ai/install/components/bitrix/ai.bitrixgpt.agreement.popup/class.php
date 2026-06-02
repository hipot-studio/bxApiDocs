<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\AI\Services\BitrixGptAgreementService;
use Bitrix\Main\Loader;

class AiBitrixGptAgreementPopupComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('ai'))
		{
			return;
		}

		if (\defined('AI_BITRIXGPT_AGREEMENT_POPUP_SCHEDULED'))
		{
			return;
		}

		$agreementService = new BitrixGptAgreementService();
		$popupData = $agreementService->getPopupDataForAutoShow();
		if ($popupData === null)
		{
			return;
		}

		\define('AI_BITRIXGPT_AGREEMENT_POPUP_SCHEDULED', true);
		$this->arResult['POPUP_DATA'] = $popupData;
		$this->includeComponentTemplate();
	}
}
