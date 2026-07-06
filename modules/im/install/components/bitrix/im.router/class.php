<?php

use Bitrix\Im\V2\Guest\Auth\JoinStatus;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Im\V2\Service\Locator;

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)
{
	die();
}

Loc::loadMessages(__FILE__);

class ImRouterComponent extends \CBitrixComponent
{
	private const NETWORK_LINE = 'networkLines';
	private const GUEST_PATH = '/guest/';

	/** @var \Bitrix\Main\HttpRequest $request */
	protected $request = array();
	protected $errors = array();
	protected $aliasData = array();

	private function showFullscreenChat()
	{
		$this->includeComponentTemplate();
	}

	private function showAirTemplate(): void
	{
		$this->setTemplateName('air');
	}

	private function showBlankPage()
	{
		define('SKIP_TEMPLATE_AUTH_ERROR', true);

		$this->setTemplateName("blank");
		$this->includeComponentTemplate();

		return true;
	}

	private function showLiveChat()
	{
		define('SKIP_TEMPLATE_AUTH_ERROR', true);

		$this->arResult['CONTEXT'] = $this->request->get('iframe') == 'Y'? 'IFRAME': 'NORMAL';
		$this->arResult['CONFIG_ID'] = $this->aliasData['ENTITY_ID'];

		$this->setTemplateName("livechat");
		$this->includeComponentTemplate();

		return true;
	}

	private function showCall()
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

		return true;
	}

	private function showNonExistentCall() : bool
	{
		define('SKIP_TEMPLATE_AUTH_ERROR', true);

		$this->arResult['WRONG_ALIAS'] = true;

		$this->setTemplateName("call");
		$this->includeComponentTemplate();

		return true;
	}

	public function executeComponent()
	{
		if (!$this->checkModules())
		{
			$this->showErrors();
			return;
		}

		$this->request = \Bitrix\Main\Context::getCurrent()->getRequest();

		$this->arResult['MESSENGER_V2'] = \Bitrix\Im\Settings::isLegacyChatActivated()  ? 'N' : 'Y';
		$this->arResult['WRONG_ALIAS'] = false;

		$guestCode = $this->extractGuestCode();
		if ($guestCode !== null)
		{
			$this->handleGuestLink($guestCode);

			return;
		}

		if ($this->request->get('alias'))
		{
			$videoconfFlag = $this->request->get('videoconf');
			$this->aliasData = \Bitrix\Im\Alias::get($this->request->get('alias'));
			if ($this->aliasData['ENTITY_TYPE'] == \Bitrix\Im\Alias::ENTITY_TYPE_LIVECHAT && IsModuleInstalled('imopenlines'))
			{
				$this->showLiveChat();
			}
			else if (isset($videoconfFlag) && !$this->aliasData)
			{
				$this->showNonExistentCall();
			}
			//correct alias
			else if ($this->aliasData['ENTITY_TYPE'] == \Bitrix\Im\Alias::ENTITY_TYPE_VIDEOCONF)
			{
				$this->showCall();
			}
			else if ($this->request->get('iframe') == 'Y')
			{
				$this->showBlankPage();
			}
			else
			{
				LocalRedirect('/');
			}
		}
		else
		{
			global $USER;
			if ($this->isChatEmbeddedOnPage())
			{
				$this->showAirTemplate();
			}
			if ($USER->IsAuthorized() && !\Bitrix\Im\User::getInstance()->isConnector())
			{
				if ($this->isDesktopRequest())
				{
					$this->handleDesktopRequest();
				}

				$this->checkNetworkLines();
				$this->showFullscreenChat();
			}
			else
			{
				LocalRedirect('/');
			}
		}
	}

	/**
	 * Extract guest link code from URI path /guest/{code}.
	 */
	private function extractGuestCode(): ?string
	{
		$requestUri = $this->request->getRequestUri();
		$path = parse_url($requestUri, PHP_URL_PATH);

		if ($path === null || !str_starts_with($path, self::GUEST_PATH))
		{
			return null;
		}

		$code = substr($path, strlen(self::GUEST_PATH));
		$code = rtrim($code, '/');

		if ($code === '' || $code === false)
		{
			return null;
		}

		return $code;
	}

	/**
	 * Handle guest invite link (/guest/{code}).
	 *
	 * If a portal user follows the link, redirects them to the chat.
	 * If a guest follows the link, renders the messenger in place.
	 */
	private function handleGuestLink(string $code): void
	{
		$token = Token::createFromRequest();
		$result = GuestService::getInstance()->joinByCode($code, $token);

		if (!$result->isSuccess())
		{
			LocalRedirect('/');

			return;
		}

		$dialogId = $result->getChat()->getDialogId();

		if ($result->getJoinStatus() === JoinStatus::PORTAL_USER)
		{
			LocalRedirect('/online/?IM_DIALOG=' . $dialogId);

			return;
		}

		$this->arResult['DIALOG_ID'] = $dialogId;
		$this->arResult['IS_GUEST_WELCOME'] = ($result->getJoinStatus() === JoinStatus::NEW_GUEST);
		$this->setTemplateName('guest');
		$this->includeComponentTemplate();
	}

	private function checkNetworkLines(): void
	{
		if (!str_starts_with($this->request['IM_DIALOG'], self::NETWORK_LINE))
		{
			return;
		}

		$code = substr($this->request['IM_DIALOG'], strlen(self::NETWORK_LINE));
		if (!preg_match('/^[a-f0-9]{32}$/i', $code))
		{
			LocalRedirect('/online/');
		}

		$botId = \Bitrix\ImBot\Bot\Network::join($code);
		if ($botId > 0)
		{
			LocalRedirect("/online/?IM_DIALOG={$botId}");
		}

		LocalRedirect('/online/');
	}

	protected function checkModules()
	{
		if(!Loader::includeModule('im'))
		{
			$this->errors[] = Loc::getMessage('IM_COMPONENT_MODULE_NOT_INSTALLED');
			return false;
		}
		return true;
	}

	protected function hasErrors()
	{
		return (count($this->errors) > 0);
	}

	protected function showErrors()
	{
		if(count($this->errors) <= 0)
		{
			return;
		}

		foreach($this->errors as $error)
		{
			ShowError($error);
		}
	}

	private function isChatEmbeddedOnPage(): bool
	{
		return Locator::getMessenger()->getApplication()->shouldHideQuickAccess();
	}

	private function isDesktopRequest(): bool
	{
		return \Bitrix\Im\V2\Application\Context::getCurrent()->isDesktop();
	}

	private function handleDesktopRequest(): void
	{
		if (!$this->isDesktopRequest())
		{
			return;
		}

		$desktopVersion = (int)($this->request->getQuery('BXD_API_VERSION') ?? 0);
		CIMMessenger::SetDesktopVersion($desktopVersion);
		CIMMessenger::SetDesktopStatusOnline(null, false);
	}
}
