<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Action;

use Bitrix\Crm\V2\Internal\Service\Currency\Compatibility\LegacyCurrencyMutationAdapter;
use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;

final class SetBaseAction extends BaseAction
{
	public function __construct(
		private readonly Settings $settings,
		private readonly string $baseCurrencyId,
		private readonly CurrencyMutationService $mutationService,
		private readonly LegacyCurrencyMutationAdapter $mutationAdapter,
		private readonly string $text,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'set-base';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		$currencyCode = self::normalizeCurrencyCode($request->getPost('currencyCode'));
		if ($currencyCode === null)
		{
			return null;
		}

		if ($currencyCode === $this->mutationAdapter->getBaseCurrencyId())
		{
			return new Result();
		}

		return $this->mutationService->setBaseCurrency($currencyCode);
	}

	protected function getText(): string
	{
		return $this->text;
	}

	public function getControl(array $rawFields): ?array
	{
		$currencyCode = self::normalizeCurrencyCode($rawFields['CURRENCY'] ?? $rawFields['ID'] ?? null);
		if (
			$currencyCode === null
			|| $currencyCode === $this->baseCurrencyId
			|| self::isYes($rawFields['BASE'] ?? null)
		)
		{
			return null;
		}

		$safeGridId = \CUtil::JSEscape($this->settings->getID());
		$this->onclick = "BX.Main.gridManager.getInstanceById('{$safeGridId}')"
			. ".sendRowAction('set-base', { currencyCode: '{$currencyCode}' });";

		return parent::getControl($rawFields);
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
