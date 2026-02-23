<?php

declare(strict_types=1);

namespace Bitrix\AI\Handler;

use Bitrix\AI\History;
use Bitrix\AI\Limiter;
use Bitrix\AI\Services\BitrixGptAgreementService;

class Main
{
	private static bool $isAgreementPopupShown = false;

	public static function onProlog(): void
	{
		$agreementService = new BitrixGptAgreementService();
		self::$isAgreementPopupShown = $agreementService->handler(self::$isAgreementPopupShown);
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
