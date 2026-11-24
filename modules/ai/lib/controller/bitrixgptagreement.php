<?php

declare(strict_types=1);

namespace Bitrix\AI\Controller;

use Bitrix\AI\Controller\ActionFilter\CheckBitrixGptAgreementAccess;
use Bitrix\AI\Services\BitrixGptAgreementService;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;

/**
 * BitrixGPT agreement popup actions. Available to portal administrators only.
 * The popup is shown only on Belarus portals (checked by getPopupDataAction()); the accept, decline
 * and skip actions do not check the region, so decline turns off AI scenarios on any portal.
 */
class BitrixGptAgreement extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
			new CheckBitrixGptAgreementAccess(),
		];
	}

	/**
	 * Accepts the agreement on behalf of the portal.
	 *
	 * @param BitrixGptAgreementService $agreementService
	 * @return void
	 */
	public function acceptAction(BitrixGptAgreementService $agreementService): void
	{
		$agreementService->acceptAgreement();
	}

	/**
	 * Declines the agreement and turns off all AI scenarios, including aiassistant MCP options.
	 *
	 * @param BitrixGptAgreementService $agreementService
	 * @return void
	 */
	public function declineAction(BitrixGptAgreementService $agreementService): void
	{
		$agreementService->declineAgreement();
	}

	/**
	 * Postpones the popup for a day; ignored for new portals and after the show limit is reached.
	 *
	 * @param BitrixGptAgreementService $agreementService
	 * @return void
	 */
	public function skipAction(BitrixGptAgreementService $agreementService): void
	{
		$agreementService->skipAgreementPopup();
	}

	/**
	 * Returns popup data to show after AI settings are saved.
	 *
	 * @param BitrixGptAgreementService $agreementService
	 * @return array{attempt: int, showLimit: int, showSkip: bool}|null Null if the popup is not needed.
	 */
	public function getPopupDataAction(BitrixGptAgreementService $agreementService): ?array
	{
		return $agreementService->onSaveAISettings();
	}
}
