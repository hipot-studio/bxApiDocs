<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Currency\Compatibility\LegacyCurrencyMutationAdapter;
use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DeleteAction extends BaseAction
{
	private const ERROR_PROTECTED_CURRENCY = 'CRM_CURRENCY_DELETE_PROTECTED';

	public function __construct(
		private readonly Settings $settings,
		private readonly string $baseCurrencyId,
		private readonly string $accountingCurrencyId,
		private readonly CurrencyMutationService $mutationService,
		private readonly LegacyCurrencyMutationAdapter $mutationAdapter,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		$currencyCode = self::normalizeCurrencyCode($request->getPost('currencyCode'));
		if ($currencyCode === null)
		{
			return null;
		}

		$baseCurrencyId = $this->mutationAdapter->getBaseCurrencyId();
		$accountingCurrencyId = $this->mutationAdapter->getAccountingCurrencyId();
		if ($currencyCode === $baseCurrencyId)
		{
			return $this->createProtectedCurrencyResult('CRM_CURRERCY_ERR_DELETION_OF_BASE_CURRENCY');
		}

		if ($currencyCode === $accountingCurrencyId)
		{
			return $this->createProtectedCurrencyResult('CRM_CURRERCY_ERR_DELETION_OF_ACCOUNTING_CURRENCY');
		}

		return $this->mutationService->delete($currencyCode);
	}

	protected function getText(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return (string)Loc::getMessage('CRM_COMMON_ACTION_DELETE');
	}

	public function getControl(array $rawFields): ?array
	{
		$currencyCode = self::normalizeCurrencyCode($rawFields['CURRENCY'] ?? $rawFields['ID'] ?? null);
		if (
			$currencyCode === null
			|| $currencyCode === $this->baseCurrencyId
			|| $currencyCode === $this->accountingCurrencyId
			|| self::isYes($rawFields['BASE'] ?? null)
			|| self::isYes($rawFields['ACCOUNTING'] ?? null)
		)
		{
			return null;
		}

		$safeGridId = \CUtil::JSEscape($this->settings->getID());
		$this->onclick = "BX.Main.gridManager.getInstanceById('{$safeGridId}')"
			. ".sendRowAction('delete', { currencyCode: '{$currencyCode}' });";

		return parent::getControl($rawFields);
	}

	private function createProtectedCurrencyResult(string $messageCode): Result
	{
		Loc::loadMessages(
			Application::getDocumentRoot() . BX_ROOT . '/modules/crm/classes/general/crm_currency.php',
		);

		return (new Result())->addError(
			new Error((string)Loc::getMessage($messageCode), self::ERROR_PROTECTED_CURRENCY),
		);
	}

	private static function normalizeCurrencyCode(mixed $currencyCode): ?string
	{
		return is_string($currencyCode) && preg_match('/^[A-Z]{3}$/D', $currencyCode) === 1
			? $currencyCode
			: null;
	}

	private static function isYes(mixed $value): bool
	{
		return $value === true || $value === 'Y';
	}
}
