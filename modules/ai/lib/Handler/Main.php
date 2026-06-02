<?php

declare(strict_types=1);

namespace Bitrix\AI\Handler;

use Bitrix\AI\History;
use Bitrix\AI\Limiter;
use Bitrix\AI\Services\BitrixGptAgreementService;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

class Main
{
	public static function onProlog(): void
	{
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

		Extension::load(['ai.bitrixgpt-agreement-popup']);
		$popupDataJson = Json::encode($popupData);
		Asset::getInstance()->addString(<<<JS
			<script>
				BX.ready(function () {
					BX.AI.showBitrixGptAgreementPopup($popupDataJson);
				});
			</script>
		JS);
	}

	/**
	 * Called after system user totally delete.
	 *
	 * @param int $userId User id.
	 * @return void
	 */
	public static function onAfterUserDelete(int $userId): void
	{
		History\Manager::deleteForUser($userId);
		Limiter\Usage::deleteForUser($userId);
	}
}
