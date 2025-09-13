<?php

use Bitrix\Landing\Manager;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

CBitrixComponent::includeComponentClass('bitrix:landing.blocks.mp_widget.base');

class LandingBlocksMainpageVibeAuto extends LandingBlocksMainpageWidgetBase
{
	private const WIDGET_CSS_VAR_PROPERTIES = [];
	private const MODULE_ID = 'landing';

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent(): void
	{
		$this->initializeParams();
		$this->getData();
		parent::executeComponent();
	}

	protected function initializeParams(): void
	{
		foreach (self::WIDGET_CSS_VAR_PROPERTIES as $property => $cssVar)
		{
			$this->addCssVarProperty($property, $cssVar);
		}
	}

	protected function getData(): void
	{
		$this->arResult['ZONE']  = Manager::getZone();

		$this->arResult['FEEDBACK_FORM'] = $this->getFeedbackFormData();
	}

	private function getFeedbackFormData(): array
	{
		$forms = [];
		$portalUri = null;
		if (Loader::includeModule('ui'))
		{
			$portalUri = (new Bitrix\UI\Form\UrlProvider)->getPartnerPortalUrl();
			$forms =  Bitrix\UI\Form\FormsProvider::getForms();
		}

		return [
			'id' => 'landing-feedback-mainpage',
			'forms' => $forms,
			'presets' => [
				'source' => self::MODULE_ID,
			],
			'portal' => $portalUri,
		];
	}

	private function getFormData(): array
	{
		return [];
	}
}
