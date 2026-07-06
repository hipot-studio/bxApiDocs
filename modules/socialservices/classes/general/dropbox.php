<?php

use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Main\Web\Uri;

IncludeModuleLangFile(__FILE__);

class CSocServDropboxAuth extends CSocServAuth
{
	const ID = "Dropbox";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";
	const LOGIN_PREFIX = "DB_";

	/** @var CDropboxOAuthInterface null  */
	protected $entityOAuth = null;

	/**
	 * @param string $code=false
	 * @return CDropboxOAuthInterface
	 */
	public function getEntityOAuth($code = false)
	{
		if(!$this->entityOAuth)
		{
			$this->entityOAuth = new CDropboxOAuthInterface();
		}

		if($code !== false)
		{
			$this->entityOAuth->setCode($code);
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	public function GetSettings()
	{
		return array(
			array("dropbox_appid", GetMessage("socserv_dropbox_client_id"), "", array("text", 40)),
			array("dropbox_appsecret", GetMessage("socserv_dropbox_client_secret"), "", array("text", 40)),
			array("note"=>GetMessage("socserv_dropbox_note", array('#URL#'=>CDropboxOAuthInterface::GetRedirectURI()))),
		);
	}

	public function GetFormHtml($arParams)
	{
		$url = static::getUrl('opener', null, $arParams);

		$phrase = ($arParams["FOR_INTRANET"]) ? GetMessage("socserv_dropbox_form_note_intranet") : GetMessage("socserv_dropbox_form_note");

		if($arParams["FOR_INTRANET"])
		{
			return array("ON_CLICK" => 'onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 600)"');
		}
		else
		{
			return '<a href="javascript:void(0)" onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 600)" class="bx-ss-button dropbox-button"></a><span class="bx-spacer"></span><span>'.$phrase.'</span>';
		}
	}

	public function GetOnClickJs($arParams)
	{
		$url = static::getUrl('opener', null, $arParams);
		return "BX.util.popup('".CUtil::JSEscape($url)."', 680, 600)";
	}


	public function getUrl($location = 'opener', $addScope = null, $arParams = array())
	{
		$this->entityOAuth = $this->getEntityOAuth();
		$stateFields = [
			'site_id' => SITE_ID,
			'check_key' => \CSocServAuthManager::getUniqueKey(),
			'redirect_url' => $this->getRedirectUrl($arParams),
			'mode' => $location,
		];
		$state = \Bitrix\Socialservices\OAuth\StateService::getInstance()->createState($stateFields);
		if ($this->isCloudPortal())
		{
			$portalRedirectUri = new Uri(
				CDropboxOAuthInterface::GetRedirectURI()
			);
			$portalRedirectUri->addParams([
				'state' => $state,
			]);

			$state = (string)$portalRedirectUri;
			$redirect_uri = static::CONTROLLER_URL . '/redirect.php';
		}
		else
		{
			$redirect_uri = CDropboxOAuthInterface::GetRedirectURI();
		}

		return $this->entityOAuth->GetAuthUrl($redirect_uri, $state);
	}

	public function getStorageToken()
	{
		$accessToken = null;
		$userId = intval($this->userId);
		if($userId > 0)
		{
			$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
				'filter' => ['=USER_ID' => $userId, "=EXTERNAL_AUTH_ID" => static::ID],
				'select' => ["OATOKEN", "REFRESH_TOKEN", "OATOKEN_EXPIRES"]
			]);
			if($arOauth = $dbSocservUser->fetch())
			{
				$accessToken = $arOauth["OATOKEN"];
			}
		}

		return $accessToken;
	}

	public function prepareUser($arDropboxUser, $short = false)
	{
		$first_name = "";
		$last_name = "";
		if(is_array($arDropboxUser['name']))
		{
			$first_name = $arDropboxUser['name']['given_name'];
			$last_name = $arDropboxUser['name']['surname'];
		}

		$id = $arDropboxUser['uid'];

		$arFields = array(
			'EXTERNAL_AUTH_ID' => static::ID,
			'XML_ID' => $id,
			'LOGIN' => static::LOGIN_PREFIX.$id,
			'NAME'=> $first_name,
			'LAST_NAME'=> $last_name,
			'EMAIL' => $arDropboxUser["email"],
			'OATOKEN' => $this->entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $this->entityOAuth->getAccessTokenExpires(),
		);

		if(SITE_ID <> '')
		{
			$arFields["SITE_ID"] = SITE_ID;
		}

		return $arFields;
	}

	public function Authorize()
	{
		global $APPLICATION;
		$APPLICATION->RestartBuffer();

		$bProcessState = false;
		$authError = SOCSERV_AUTHORISATION_ERROR;
		$this->logger->info('oauth.auth.start');

		if (empty($_REQUEST['code']))
		{
			$this->logger->error('oauth.request.invalid_code');
			$this->sendOauthError(OAuthErrorCode::MissingCode);
		}
		elseif (CSocServAuthManager::CheckUniqueKey())
		{
			$bProcessState = true;

			$this->entityOAuth = $this->getEntityOAuth($_REQUEST['code']);

			if ($this->isCloudPortal())
			{
				$redirect_uri = static::CONTROLLER_URL."/redirect.php";
			}
			else
			{
				$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();
			}

			if($this->entityOAuth->GetAccessToken($redirect_uri) !== false)
			{
				$arDropboxUser = $this->entityOAuth->GetCurrentUser();
				if(is_array($arDropboxUser))
				{
					$arFields = self::prepareUser($arDropboxUser);
					$authError = $this->AuthorizeUser($arFields);
				}
				else
				{
					$this->logger->error('oauth.user.fetch_failed', [
						'reason' => 'invalid_response_payload',
					]);
				}
			}
			else
			{
				$this->logger->error('oauth.token.exchange_failed', [
					'reason' => 'get_access_token_failed',
				]);
			}
		}
		else
		{
			$this->logger->error('oauth.request.invalid_check_key', [
				'reason' => 'check_key_validation_failed',
			]);
			$this->sendOauthError(OAuthErrorCode::InvalidCheckKey);
		}

		$this->logger->info('oauth.auth.finish', [
			'success' => ($authError === true),
			'auth_result' => $authError,
		]);

		if(!$bProcessState)
		{
			unset($_REQUEST["state"]);
		}

		$arState = $this->getState();
		$mode = 'opener';
		if(isset($arState['mode']))
		{
			$mode = $arState['mode'];
		}
		$url = $this->getRedirectUriAfterAuthorize($authError, static::ID);
		$addParams = !str_starts_with($url, '#');

		$this->onAfterWebAuth($addParams, $mode, $url);

		CMain::FinalActions();
	}
}

class CDropboxOAuthInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "Dropbox";

	const AUTH_URL = "https://www.dropbox.com/oauth2/authorize";
	const TOKEN_URL = "https://www.dropbox.com/oauth2/token";

	const ACCOUNT_URL = "https://api.dropboxapi.com/2/users/get_current_account";

	protected $oauthResult;

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServDropboxAuth::GetOption("dropbox_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServDropboxAuth::GetOption("dropbox_appsecret"));
		}

		parent::__construct($appID, $appSecret, $code);
	}

	public static function GetRedirectURI()
	{
		return (string)(new Uri("/bitrix/tools/oauth/dropbox.php"))->toAbsolute();
	}

	public function GetAuthUrl($redirect_uri, $state = '')
	{
		return static::AUTH_URL.
		"?client_id=".urlencode($this->appID).
		"&redirect_uri=".urlencode($redirect_uri).
		"&response_type=code".
		($state <> '' ? '&state='.urlencode($state) : '');
	}

	public function GetAccessToken($redirect_uri)
	{
		$tokens = $this->getStorageTokens();

		if(is_array($tokens))
		{
			$this->access_token = $tokens["OATOKEN"];

			if(!$this->code)
			{
				return true;
			}

			$this->deleteStorageTokens();
		}

		if($this->code === false)
		{
			$this->logger->error('oauth.token.exchange_failed', [
				'reason' => 'empty_code',
			]);

			return false;
		}

		$h = new \Bitrix\Main\Web\HttpClient();
		$result = $h->post(static::TOKEN_URL, array(
			"code"=>$this->code,
			"client_id"=>$this->appID,
			"client_secret"=>$this->appSecret,
			"redirect_uri"=>$redirect_uri,
			"grant_type"=>"authorization_code",
		));

		$this->oauthResult = \Bitrix\Main\Web\Json::decode($result);

		if(isset($this->oauthResult["access_token"]) && $this->oauthResult["access_token"] <> '')
		{
			if(isset($this->oauthResult["refresh_token"]) && $this->oauthResult["refresh_token"] <> '')
			{
				$this->refresh_token = $this->oauthResult["refresh_token"];
			}
			$this->access_token = $this->oauthResult["access_token"];

			$_SESSION["OAUTH_DATA"] = array(
				"OATOKEN" => $this->access_token,
			);

			return true;
		}

		$this->logger->error('oauth.token.exchange_failed', [
			'reason' => 'token_not_found_in_response',
		]);

		return false;
	}

	public function GetCurrentUser()
	{
		if($this->access_token === false)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'empty_access_token',
			]);

			return false;
		}

		$h = new \Bitrix\Main\Web\HttpClient();
		$h->setHeader("Authorization", "Bearer ".$this->access_token);
		$h->setHeader("Content-Type", ""); // !!! Dropbox doest not accept empty POST requests with application/json or application/x-www-form-urlencoded types

		$result = $h->post(static::ACCOUNT_URL);

		try
		{
			$result = \Bitrix\Main\Web\Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response',
			]);

			return false;
		}

		if(is_array($result))
		{
			$result["uid"] = $this->oauthResult['uid'];
			$result["access_token"] = $this->access_token;

			return $result;
		}

		$this->logger->error('oauth.user.fetch_failed', [
			'reason' => 'invalid_response_payload',
		]);

		return $result;
	}
}