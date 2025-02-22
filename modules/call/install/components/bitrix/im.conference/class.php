<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    exit;
}

use Bitrix\Im\Call\Call;
use Bitrix\Im\Call\Conference;
use Bitrix\Im\Chat;
use Bitrix\Im\Limit;
use Bitrix\Im\Settings;
use Bitrix\Im\User;
use Bitrix\Intranet\Util;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;

class ImComponentConference extends CBitrixComponent
{
    public const OPTION_ENABLED = 'enabled';
    public const OPTION_DISABLED = 'disabled';
    public const OPTION_LIMITED = 'limited';
    private $chatId = 0;
    private $userId = 0;
    private $userCount = 0;
    private $userLimit = 0;
    private $startupErrorCode = '';
    private $isIntranetOrExtranet = false;
    private $isPasswordRequired = false;

    /** @var Bitrix\Im\Call\Conference */
    private $conference;

    public function executeComponent()
    {
        global $USER;

        $this->includeComponentLang('class.php');

        if (!$this->checkModules()) {
            return false;
        }

        if (!$this->prepareParams()) {
            return false;
        }

        if ($this->arParams['WRONG_ALIAS']) {
            $this->startupErrorCode = Conference::ERROR_WRONG_ALIAS;
        } elseif (!Loader::includeModule('intranet')) {
            $this->startupErrorCode = Conference::ERROR_BITRIX24_ONLY;
        }
        //		else if ($this->conference && !$this->conference->isActive())
        //		{
        //			//finished or not started yet
        //			$this->startupErrorCode = $this->conference->getStatus();
        //		}
        else {
            if ($this->conference->isPasswordRequired()) {
                // if password is required - we check if user already had entered the password (this fact will be saved in session storage)
                $storage = $this->getLocalSession();
                $isUserInChat = Chat::isUserInChat($this->chatId);
                if (!$isUserInChat && true === $storage->get('checked')) {
                    $storage->set('checked', false);
                    $this->isPasswordRequired = true;
                } elseif (true !== $storage->get('checked')) {
                    $this->isPasswordRequired = true;
                }
            }

            if ($USER->IsAuthorized()) {
                $wasKickedFromChat = Chat::isUserKickedFromChat($this->chatId);
                if ($wasKickedFromChat) {
                    $this->startupErrorCode = Conference::ERROR_KICKED_FROM_CALL;
                } else {
                    $this->checkLoggedInUser();
                }
            } else {
                // if user with intranet cookies clicked "continue as guest"
                $guestCookieName = 'VIDEOCONF_GUEST_'.$this->conference->getAlias();
                $guestCookie = isset($_COOKIE[$guestCookieName]);

                $cookieLogin = $this->getLoginCookies();
                if (!$guestCookie && '' !== $cookieLogin && 0 !== mb_strpos($cookieLogin, 'im_call')) {
                    // try to login by login cookies
                    $USER->LoginByCookies();
                    if ($USER->GetID() <= 0) {
                        $this->startupErrorCode = Conference::ERROR_DETECT_INTRANET_USER;
                    } else {
                        $this->checkLoggedInUser();
                    }
                } elseif ($this->userCount + 1 > $this->userLimit) {
                    $this->startupErrorCode = Conference::ERROR_USER_LIMIT_REACHED;
                }
            }
        }

        $this->setRichLink();
        $this->prepareResult();
        $this->includeComponentTemplate();

        return true;
    }

    protected function checkModules(): bool
    {
        if (!Loader::includeModule('im')) {
            ShowError(Loc::getMessage('IM_COMPONENT_MODULE_IM_NOT_INSTALLED'));

            return false;
        }

        if (!Loader::includeModule('call')) {
            ShowError(Loc::getMessage('IM_COMPONENT_MODULE_CALL_NOT_INSTALLED'));

            return false;
        }

        return true;
    }

    protected function prepareParams(): bool
    {
        $this->chatId = (int) $this->arParams['CHAT_ID'];
        if ($this->arParams['WRONG_ALIAS']) {
            return true;
        }

        if (!isset($this->chatId) && !$this->arParams['WRONG_ALIAS']) {
            return false;
        }
        $this->userCount = CIMChat::getUserCount($this->chatId);
        if (false === $this->userCount) {
            return false;
        }
        $this->conference = Conference::getByAlias($this->arParams['ALIAS']);
        if (false === $this->conference) {
            return false;
        }

        $this->userLimit = $this->conference->getUserLimit();

        return true;
    }

    protected function prepareResult(): bool
    {
        $this->arResult['ALIAS'] = $this->arParams['ALIAS'];
        $this->arResult['CHAT_ID'] = $this->chatId;
        $this->arResult['PASSWORD_REQUIRED'] = $this->isPasswordRequired;
        $this->arResult['SITE_ID'] = Context::getCurrent()->getSite();
        $this->arResult['USER_ID'] = $this->userId;
        $this->arResult['USER_COUNT'] = $this->userCount;
        $this->arResult['STARTUP_ERROR_CODE'] = $this->startupErrorCode;
        $this->arResult['IS_INTRANET_OR_EXTRANET'] = $this->isIntranetOrExtranet;
        $this->arResult['LANGUAGE'] = Loc::getCurrentLang();
        $this->arResult['FEATURE_CONFIG'] = $this->getFeatureConfig();
        $this->arResult['LOGGER_CONFIG'] = Settings::getLoggerConfig();

        $this->arResult['PRESENTERS'] = [];
        if ($this->conference) {
            $this->arResult['CONFERENCE_ID'] = $this->conference->getId();
            $this->arResult['CONFERENCE_TITLE'] = $this->conference->getChatName();
            $this->arResult['IS_BROADCAST'] = $this->conference->isBroadcast();
            $this->arResult['PRESENTERS'] = $this->conference->getPresentersInfo();
        }

        return true;
    }

    protected function getFeatureConfig()
    {
        $result = [];

        // feature screen sharing

        $screenSharingLimit = Limit::getTypeCallScreenSharing();
        $screenSharingState = self::OPTION_ENABLED;

        if ($screenSharingLimit['ACTIVE']) {
            if (User::getInstance($this->userId)->isExtranet()) {
                $screenSharingState = self::OPTION_DISABLED;
            } else {
                $screenSharingState = self::OPTION_LIMITED;
            }
        }

        $result[] = [
            'id' => 'screenSharing',
            'state' => $screenSharingState,
            'articleCode' => $screenSharingLimit['ARTICLE_CODE'],
        ];

        // feature record

        $recordLimit = Limit::getTypeCallRecord();
        $recordState = self::OPTION_ENABLED;

        if ($recordLimit['ACTIVE']) {
            if (User::getInstance($this->userId)->isExtranet()) {
                $recordState = self::OPTION_DISABLED;
            } else {
                $recordState = self::OPTION_LIMITED;
            }
        }

        $result[] = [
            'id' => 'record',
            'state' => $recordState,
            'articleCode' => $recordLimit['ARTICLE_CODE'],
        ];

        return $result;
    }

    protected function addUserToChat(): bool
    {
        global $USER;

        $chat = new CIMChat(0);
        $addingResult = $chat->AddUser($this->chatId, $USER->GetID());

        if (!$addingResult) {
            ShowError(Loc::getMessage('IM_COMPONENT_MODULE_IM_NOT_INSTALLED'));

            return false;
        }
        ++$this->userCount;

        return true;
    }

    protected function checkLoggedInUser(): ?bool
    {
        global $USER;

        // if not intranet/extranet/call-user - it is different external type, log him out and treat as guest
        if (!Util::isIntranetUser() && !Util::isExtranetUser() && 'call' !== $USER->GetParam('EXTERNAL_AUTH_ID')) {
            $USER->Logout();
        } else {
            if (Util::isIntranetUser() || Util::isExtranetUser()) {
                $this->isIntranetOrExtranet = true;
            }

            $this->userId = $USER->GetID();

            $isUserInChat = Chat::isUserInChat($this->chatId);
            if (!$isUserInChat) {
                if ($this->userCount + 1 > $this->userLimit) {
                    $this->startupErrorCode = Conference::ERROR_USER_LIMIT_REACHED;

                    return false;
                }

                if (!$this->isPasswordRequired) {
                    $this->addUserToChat();
                }
            }
        }

        return true;
    }

    protected function getLoginCookies(): string
    {
        $cookiePrefix = COption::GetOptionString('main', 'cookie_name', 'BITRIX_SM');
        $cookieLogin = (string) ($_COOKIE[$cookiePrefix.'_UIDL'] ?? '');
        if ('' === $cookieLogin) {
            $cookieLogin = (string) ($_COOKIE[$cookiePrefix.'_LOGIN'] ?? '');
        }

        return $cookieLogin;
    }

    protected function setRichLink(): bool
    {
        Asset::getInstance()->addString(
            '<meta property="og:title" content="'.Loc::getMessage('IM_COMPONENT_OG_TITLE_2').'" />'
        );
        Asset::getInstance()->addString(
            '<meta property="og:description" content="'.Loc::getMessage('IM_COMPONENT_OG_DESCRIPTION_2').'" />'
        );

        $imagePath = $this->getPath().'/images/og_image_3.jpg';
        Asset::getInstance()->addString(
            '<meta property="og:image" content="'.$imagePath.'" />'
        );

        Asset::getInstance()->addString(
            '<meta name="robots" content="noindex, nofollow" />'
        );

        return true;
    }

    protected function getLocalSession(): SessionLocalStorage
    {
        return Application::getInstance()->getLocalSession('conference_check_'.$this->conference->getId());
    }
}
