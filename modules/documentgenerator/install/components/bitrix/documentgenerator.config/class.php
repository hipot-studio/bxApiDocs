<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class DocumentGeneratorConfigComponent extends CBitrixComponent implements \Bitrix\Main\Engine\Contract\Controllerable, Errorable
{
	use ErrorableImplementation;

	public function __construct($component = null)
	{
		parent::__construct($component);

		$this->errorCollection = new ErrorCollection();
	}

	public function executeComponent()
	{
		if(!Loader::includeModule('documentgenerator'))
		{
			$this->showError(Loc::getMessage('DOCGEN_CONFIG_MODULE_DOCGEN_ERROR'));
			return;
		}

		if(!$this->hasAccess())
		{
			$this->showError(Loc::getMessage('DOCGEN_CONFIG_PERMISSIONS_ERROR'));
			return;
		}

		if (Loader::includeModule('ui'))
		{
			\Bitrix\UI\Toolbar\Facade\Toolbar::deleteFavoriteStar();
		}

		$this->arResult = [];

		$this->arResult['TITLE'] = Loc::getMessage('DOCGEN_CONFIG_TITLE');
		$this->arResult['enablePublicSign'] = (Option::get('documentgenerator', 'document_enable_public_b24_sign', 'Y') == 'Y');
		$this->prepareTimezoneResult();

		global $APPLICATION;
		$APPLICATION->SetTitle($this->arResult['TITLE']);

		$this->includeComponentTemplate();
	}

	protected function hasAccess(): bool
	{
		return \Bitrix\DocumentGenerator\Driver::getInstance()->getUserPermissions()->canModifySettings();
	}

	protected function showError($error)
	{
		ShowError($error);
	}

	/**
	 * @return array
	 */
	public function configureActions()
	{
		return [];
	}

	/**
	 * @param array $config
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function saveConfigAction(array $config)
	{
		if(!Loader::includeModule('documentgenerator'))
		{
			return;
		}
		if (!$this->hasAccess())
		{
			return;
		}

		if (
			\Bitrix\DocumentGenerator\Timezone::isEnabled()
			&& array_key_exists('document_timezone', $config)
		)
		{
			$timezone = $config['document_timezone'];
			if ($timezone !== '' && (!is_string($timezone) || !\Bitrix\DocumentGenerator\Timezone::isValid($timezone)))
			{
				$this->errorCollection->setError(new Error('Invalid timezone.', 'TIMEZONE_INVALID'));

				return;
			}

			Option::set('documentgenerator', \Bitrix\DocumentGenerator\Timezone::OPTION_NAME, $timezone);
		}

		if (
			!$this->areRestrictionsActive()
			&& array_key_exists('document_enable_public_b24_sign', $config)
		)
		{
			Option::set('documentgenerator', 'document_enable_public_b24_sign', $config['document_enable_public_b24_sign']);
		}
	}

	protected function prepareTimezoneResult(): void
	{
		if (!\Bitrix\DocumentGenerator\Timezone::isEnabled())
		{
			return;
		}

		$timezone = Option::get(
			'documentgenerator',
			\Bitrix\DocumentGenerator\Timezone::OPTION_NAME,
			'',
		);
		$this->arResult['timezoneFeatureEnabled'] = true;
		$this->arResult['timezoneItems'] = \Bitrix\DocumentGenerator\Timezone::getItems();
		$this->arResult['timezone'] = \Bitrix\DocumentGenerator\Timezone::isValid($timezone) ? $timezone : '';
	}

	protected function areRestrictionsActive(): bool
	{
		return \Bitrix\DocumentGenerator\Integration\Bitrix24Manager::isRestrictionsActive();
	}
}
