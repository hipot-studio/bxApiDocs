<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)
{
	die();
}

Loc::loadMessages(__FILE__);

class CallRouterComponent extends \CBitrixComponent
{

	/** @var \Bitrix\Main\HttpRequest $request */
	protected $request = null;
	protected $errors = [];
	protected $aliasData = [];

	public function executeComponent()
	{
		global $APPLICATION;
		$APPLICATION->SetTitle(Loc::getMessage("IM_ROUTER_PAGE_TITLE"));

		if (!$this->checkModules())
		{
			$this->showErrors();
			return;
		}

		$this->request = \Bitrix\Main\Context::getCurrent()->getRequest();

		$this->arResult['WRONG_ALIAS'] = false;

		if ($this->request->get('alias'))
		{
			$videoconfFlag = $this->request->get('videoconf');
			$this->aliasData = \Bitrix\Im\Alias::get($this->request->get('alias'));
			if (isset($videoconfFlag) && !$this->aliasData)
			{
				$this->showNonExistentCall();
				return;
			}
			//correct alias
			else if ($this->aliasData['ENTITY_TYPE'] == \Bitrix\Im\Alias::ENTITY_TYPE_VIDEOCONF)
			{
				$this->showCall();
				return;
			}
		}
		LocalRedirect('/');
	}

	private function showCall(): void
	{
		define('SKIP_TEMPLATE_AUTH_ERROR', true);

		if (!defined('NO_AGENT_CHECK'))
		{
			define('NO_AGENT_CHECK', true);
		}
		if (!defined('DisableEventsCheck'))
		{
			define('DisableEventsCheck', true);
		}

		if (Loader::includeModule('ui'))
		{
			\Bitrix\Main\UI\Extension::load('ui.roboto');
		}

		$this->arResult['ALIAS'] = $this->aliasData['ALIAS'];
		$this->arResult['CHAT_ID'] = $this->aliasData['ENTITY_ID'];

		$this->setTemplateName("call");
		$this->includeComponentTemplate();
	}

	private function showNonExistentCall(): void
	{
		define('SKIP_TEMPLATE_AUTH_ERROR', true);

		$this->arResult['WRONG_ALIAS'] = true;

		$this->setTemplateName("call");
		$this->includeComponentTemplate();
	}


	private function checkModules(): bool
	{
		if (!Loader::includeModule('call'))
		{
			$this->errors[] = Loc::getMessage('IM_COMPONENT_MODULE_NOT_INSTALLED');
			return false;
		}
		return true;
	}


	private function showErrors(): void
	{
		if (count($this->errors) <= 0)
		{
			return;
		}

		foreach ($this->errors as $error)
		{
			ShowError($error);
		}
	}
}
