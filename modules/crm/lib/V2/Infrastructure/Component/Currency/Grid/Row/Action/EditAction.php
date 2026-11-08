<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class EditAction extends BaseAction
{
	public function __construct(
		private readonly string $editUrlTemplate,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'edit';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return (string)Loc::getMessage('CRM_COMMON_ACTION_EDIT');
	}

	public function getControl(array $rawFields): ?array
	{
		$currencyCode = self::getCurrencyCode($rawFields);
		if ($currencyCode === null)
		{
			return null;
		}

		$url = str_replace('#currency_id#', rawurlencode($currencyCode), $this->editUrlTemplate);
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (
			$scheme === false
			|| (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true))
		)
		{
			return null;
		}

		$this->href = $url;
		$this->onclick = 'return !BX.Crm.CurrencyList.openForm(' . Json::encode($url) . ');';

		return parent::getControl($rawFields);
	}

	private static function getCurrencyCode(array $rawFields): ?string
	{
		$currencyCode = $rawFields['CURRENCY'] ?? $rawFields['ID'] ?? null;

		return is_string($currencyCode) && preg_match('/^[A-Z]{3}$/D', $currencyCode) === 1
			? $currencyCode
			: null;
	}
}
