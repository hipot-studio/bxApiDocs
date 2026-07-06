<?php

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

require_once __DIR__ . '/lib/autoload.php';

/**
 * Bitrix vars
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 * @global CMain $APPLICATION
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest;
use Bitrix\Main;
use Bitrix\Rest\Component\AppLayout;

Main\Localization\Loc::loadMessages(__FILE__);
Main\Localization\Loc::loadLanguageFile(__DIR__ . '/component.php');

class CAppLayoutComponent extends \CBitrixComponent
{
	public function onPrepareComponentParams($arParams)
	{
		if (!Loader::includeModule('rest'))
		{
			return parent::onPrepareComponentParams($arParams);
		}

		$arParams['POPUP'] ??= false;
		$arParams['IS_SLIDER'] = ($arParams['IS_SLIDER'] ?? 'N') === 'Y';
		$arParams['INITIALIZE'] = ($arParams['INITIALIZE'] ?? 'Y') === 'N' ? 'N' : 'Y';
		$arParams['DETAIL_URL'] = $arParams['DETAIL_URL'] ?? '/marketplace/detail/#code#/?from=app_layout';
		$arParams['PLACEMENT'] = mb_strtoupper($arParams['PLACEMENT'] ?? Rest\PlacementTable::PLACEMENT_DEFAULT);
		$arParams['PLACEMENT_OPTIONS'] = $arParams['PLACEMENT_OPTIONS'] ?? '';

		$extractors = [
			AppLayout\Extractor\PlacementPreviewExtractor::class,
			AppLayout\Extractor\ComponentExtractor::class,
			AppLayout\Extractor\IframeExtractor::class,
			AppLayout\Extractor\PlacementDataExtractor::class,
			AppLayout\Extractor\RefererUriExtractor::class,
		];

		/** @var AppLayout\ContractorInterface $extractor */
		foreach ($extractors as $extractorClass)
		{
			$extractor = new $extractorClass($arParams, $this->request);
			if ($extractor->isEnabled())
			{
				$arParams = array_merge($arParams, $extractor->run());
			}
		}

		if (!empty($arParams['ID']))
		{
			$arParams['CODE'] = intval($arParams['ID']);
		}

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		try
		{
			$this->checkParams();

			$fillers = [
				AppLayout\Filler\AppBodyFiller::class,
				AppLayout\Filler\AppPlacementFiller::class,
				AppLayout\Filler\SubscriptionFiller::class,
				AppLayout\Filler\AppNameFiller::class,
				AppLayout\Filler\AuthTokenFiller::class,
				AppLayout\Filler\AppUrlFiller::class,
			];
			/** @var AppLayout\ContractorInterface $filler */
			foreach ($fillers as $fillerClass)
			{
				$filler = new $fillerClass(
					$this->arParams,
					$this->arResult,
					$this->request
				);
				if ($filler->isEnabled())
				{
					$this->arResult = array_merge($this->arResult, $filler->run());
				}
			}

			return $this->__includeComponent();
		}
		catch (AppLayout\Exception\SubscriptionRequiredException)
		{
			$this->arResult['PAYMENT_TYPE'] = Rest\AppTable::STATUS_SUBSCRIPTION;
			$this->IncludeComponentTemplate('payment');
		}
		catch (Main\SystemException $e)
		{
			$this->arResult['ERROR_MESSAGE'] = $this->getErrorMessageFromException($e);
			$this->IncludeComponentTemplate('error');
		}
	}

	private function checkParams(): void
	{
		if (!Loader::includeModule('rest'))
		{
			throw new AppLayout\Exception\AppLayoutException(Loc::getMessage('REST_MODULE_NOT_INSTALLED') );
		}

		if (empty($this->arParams['CODE']))
		{
			throw new AppLayout\Exception\AppLayoutException(Loc::getMessage('REST_APP_NOT_DEFINED'));
		}
	}

	private function getErrorMessageFromException(\Exception $e): string
	{
		$result = match ($e::class)
		{
			AppLayout\Exception\AccessDeniedException::class => Loc::getMessage('REST_AL_ERROR_APP_ACCESS_DENIED'),
			AppLayout\Exception\AppNotFoundException::class => $this->buildAppMissingMessage('REST_AL_ERROR_APP_NOT_FOUND'),
			AppLayout\Exception\AppNotFoundInMarketplaceException::class => $this->buildAppMissingMessage('REST_AL_ERROR_APP_NOT_FOUND_MARKETPLACE'),
			AppLayout\Exception\AppNotInstalledException::class => Loc::getMessage('REST_AL_ERROR_APP_INSTALL_NOT_FINISH'),
			AppLayout\Exception\AuthUnknownException::class => Loc::getMessage('REST_AL_ERROR_APP_GET_OAUTH_TOKEN'),
			AppLayout\Exception\AuthExternalException::class => Loc::getMessage('REST_AL_ERROR_APP_GET_OAUTH_TOKEN')
				. '[' . $e->getMessage() . ']',
			AppLayout\Exception\PlacementNotInstalledException::class => Loc::getMessage('REST_AL_ERROR_APP_PLACEMENT_NOT_INSTALLED'),
			AppLayout\Exception\AppHandlerNotDefinedException::class => Loc::getMessage('REST_APP_HANDLER_NOT_DEFINED'),
			AppLayout\Exception\AppLayoutException::class => Loc::getMessage('REST_APP_LAYOUT_ERROR'),
			default => Loc::getMessage('REST_APP_LAYOUT_UNKNOWN_ERROR') . '[' . $e->getMessage() . ']',
		};

		return $result ?? $e->getMessage();
	}

	private function buildAppMissingMessage(string $messageKey): string
	{
		$code = $this->arParams['CODE'] ?? '';
		$message = (string)Loc::getMessage($messageKey, ['#CODE#' => htmlspecialcharsbx((string)$code)]);
		if (is_string($code) && $code !== '')
		{
			$marketplaceUrl = str_replace('#code#', urlencode($code), $this->arParams['DETAIL_URL']);
			$linkText = Loc::getMessage('REST_AL_ERROR_APP_FIND_IN_MARKETPLACE');
			$message .= ' <a href="' . htmlspecialcharsbx($marketplaceUrl) . '" target="_top">' . $linkText . '</a>';
		}

		return $message;
	}
}
