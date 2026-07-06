<?php

use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Main\Web\Uri;

IncludeModuleLangFile(__FILE__);

class CSocServOffice365OAuth extends CSocServAuth
{
	public const ID = "Office365";
	/**
	 * @deprecated Use \CSocServOffice365OAuth::getControllerUrl() instead.
	 * @var string
	 */
	public const CONTROLLER_URL = 'https://www.bitrix24.com/controller';

	/** @var COffice365OAuthInterface null  */
	protected $entityOAuth = null;

	public function getEntityOAuth()
	{
		if(!$this->entityOAuth)
		{
			$this->entityOAuth = new COffice365OAuthInterface();
			$this->entityOAuth->setUser($this->userId);
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	public static function getControllerUrl(): string
	{
		return 'https://www.bitrix24.com/controller';
	}


	public function GetSettings()
	{
		return array(
			array("office365_appid", GetMessage("socserv_office365_client_id"), "", Array("text", 40)),
			array("office365_appsecret", GetMessage("socserv_office365_client_secret"), "", Array("text", 40)),
			array("office365_tenant", GetMessage("socserv_office365_tenant"), "", Array("text", 40)),
			array("note"=>GetMessage("socserv_office365_form_note", array(
				'#URL#'	=>	$this->getEntityOAuth()->getRedirectUri(),
				'#MAIL_URL#'	=> (string)(new Uri('/bitrix/tools/mail_oauth.php'))->toAbsolute()))),
		);
	}

	public function CheckSettings()
	{
		return self::GetOption('office365_appid') !== '' && self::GetOption('office365_appsecret') !== '';
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl('opener', null, $arParams);
		if($arParams["FOR_INTRANET"])
		{
			return array("ON_CLICK" => 'onclick="BX.util.popup(\'' . htmlspecialcharsbx(CUtil::JSEscape($url)) . '\', 680, 800)"');
		}
		return '<a href="javascript:void(0)" onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)" class="bx-ss-button liveid-button"></a><span class="bx-spacer"></span><span>'.GetMessage("MAIN_OPTION_COMMENT").'</span>';
	}

	public function GetOnClickJs($arParams)
	{
		$url = $this->getUrl('opener', null, $arParams);
		return "BX.util.popup('".CUtil::JSEscape($url)."', 680, 800)";
	}

	public function getUrl($location = 'opener', $addScope = null, $arParams = array())
	{
		$stateFields = [
			'site_id' => SITE_ID,
			'check_key' => \CSocServAuthManager::getUniqueKey(),
			'redirect_url' => $this->getRedirectUrl($arParams, ['serviceName', 'hitHash']),
			'mode' => $location,
		];
		$state = \Bitrix\Socialservices\OAuth\StateService::getInstance()->createState($stateFields);

		if ($this->isCloudPortal())
		{
			$portalRedirectUri = new Uri(
				$this->getEntityOAuth()->getRedirectUri()
			);
			$portalRedirectUri->addParams([
				'state' => $state,
			]);

			$state = (string)$portalRedirectUri;
			$redirect_uri = \CSocServOffice365OAuth::getControllerUrl() . '/redirect.php';
		}
		else
		{
			$redirect_uri = $this->getEntityOAuth()->getRedirectUri();
		}

		return $this->getEntityOAuth()->GetAuthUrl($redirect_uri, $state);
	}

	public function getStorageToken()
	{
		$accessToken = null;
		$userId = (int)$this->userId;
		if($userId > 0)
		{
			$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
				'filter' => ['=USER_ID' => $userId, "=EXTERNAL_AUTH_ID" => static::ID],
				'select' => ["OATOKEN", "REFRESH_TOKEN", "OATOKEN_EXPIRES"]
			]);
			if($arOauth = $dbSocservUser->fetch())
			{
				$accessToken = $arOauth["OATOKEN"];

				if(empty($accessToken) || ((int)$arOauth["OATOKEN_EXPIRES"] && ((int)($arOauth["OATOKEN_EXPIRES"] < time()))))
				{
					if(isset($arOauth['REFRESH_TOKEN']))
					{
						$this->entityOAuth->getNewAccessToken($arOauth['REFRESH_TOKEN'], $userId, true);
					}
					if(($accessToken = $this->entityOAuth->getToken()) === false)
					{
						return null;
					}
				}
			}
		}

		return $accessToken;
	}

	public function prepareUser($office365User)
	{
		$email = $first_name = $last_name = "";
		$login = "Office365".$office365User['id'];
		$uId = $office365User['id'];

		if(!empty($office365User['givenName']))
		{
			$first_name = $office365User['givenName'];
		}

		if(!empty($office365User['surname']))
		{
			$last_name = $office365User['surname'];
		}

		if(!empty($office365User['mail']))
		{
			$email = $office365User['mail'];
			$login = $office365User['mail'];
		}

		$arFields = [
			'EXTERNAL_AUTH_ID' => self::ID,
			'XML_ID' => $uId,
			'LOGIN' => $login,
			'EMAIL' => $email,
			'NAME'=> $first_name,
			'LAST_NAME'=> $last_name,
		];

		$arFields["PERSONAL_PHONE"] = $office365User["telephoneNumber"];

		if(isset($office365User['access_token']))
		{
			$arFields["OATOKEN"] = $office365User['access_token'];
		}

		if(isset($office365User['refresh_token']))
		{
			$arFields["REFRESH_TOKEN"] = $office365User['refresh_token'];
		}

		if(isset($office365User['expires_in']))
		{
			$arFields["OATOKEN_EXPIRES"] = time() + $office365User['expires_in'];
		}

		if(!empty(SITE_ID))
		{
			$arFields["SITE_ID"] = SITE_ID;
		}

		$arFields["PERMISSIONS"] = serialize([
			"tenant" => $office365User["tenant"],
		]);

		return $arFields;
	}

	public function Authorize()
	{
		global $APPLICATION;

		$APPLICATION->RestartBuffer();

		$bProcessState = false;
		$bSuccess = SOCSERV_AUTHORISATION_ERROR;
		$this->logger->info('oauth.auth.start');

		if (empty($_REQUEST['code']))
		{
			$this->logger->error('oauth.request.invalid_code');
			$this->sendOauthError(OAuthErrorCode::MissingCode);
		}
		elseif (CSocServAuthManager::CheckUniqueKey())
		{
			$this->getEntityOAuth()->setCode($_REQUEST["code"]);

			$bProcessState = true;

			if($this->getEntityOAuth()->GetAccessToken() !== false)
			{
				$office365User = $this->getEntityOAuth()->GetCurrentUser();
				if(is_array($office365User) && !empty($office365User['id']))
				{
					$office365User["tenant"] = preg_replace("/^.*@/", "", $office365User["userPrincipalName"]);

					$allowAuth = true;
					$tenantRestriction = self::GetOption("office365_tenant");
					if(!empty($tenantRestriction))
					{
						$allowAuth = $office365User["tenant"] === $tenantRestriction;
					}

					if($allowAuth)
					{
						$arFields = $this->prepareUser($office365User);
						$bSuccess = $this->AuthorizeUser($arFields);
					}
					else
					{
						$this->logger->error('oauth.user.fetch_failed', [
							'reason' => 'tenant_mismatch',
						]);
					}
				}
				else
				{
					$this->logger->error('oauth.user.fetch_failed', [
						'reason' => 'missing_user_id',
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
			'success' => ($bSuccess === true),
			'auth_result' => $bSuccess,
		]);

		if(!$bProcessState)
		{
			unset($_REQUEST["state"]);
		}

		$mode = 'opener';
		$arState = $this->getState();
		if(isset($arState['mode']))
		{
			$mode = $arState['mode'];
		}
		$url = $this->getRedirectUriAfterAuthorize($bSuccess, self::ID);
		$addParams = !str_starts_with($url, '#');

		if ($bSuccess && $mode === self::MOBILE_MODE)
		{
			$this->onAfterMobileAuth();
		}
		elseif (!isset($_REQUEST['auth_service_error']))
		{
			$this->onAfterWebAuth($addParams, $mode, $url);
		}

		CMain::FinalActions();
	}

	public function getProfileUrl($id)
	{
		return 'https://portal.office.com/';
	}

}

class COffice365OAuthInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "Office365";

	const AUTH_URL = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize";
	const TOKEN_URL = "https://login.microsoftonline.com/common/oauth2/v2.0/token";

	const VERSION = "/v1.0";

	const CONTACTS_URL = "/me/";

	const REDIRECT_URI = "/bitrix/tools/oauth/office365.php";

	protected $resource = "https://graph.microsoft.com";
	protected $scope = [
		"User.Read",
		"offline_access",
	];

	public function __construct($appID = false, $appSecret = false, $code=false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServOffice365OAuth::GetOption("office365_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServOffice365OAuth::GetOption("office365_appsecret"));
		}

		parent::__construct($appID, $appSecret, $code);
	}

	public function GetAuthUrl($redirect_uri, $state='')
	{
		return static::AUTH_URL.
		"?client_id=".urlencode($this->appID).
		"&redirect_uri=".urlencode($redirect_uri).
		"&response_type=code".
		"&scope=".$this->getScopeEncode().
		"&prompt=select_account".
		($state <> ''? '&state='.urlencode($state):'');
	}

	public function getScopeEncode(): string
	{
		$scopesAsString = implode(' ', array_unique($this->getScope()));

		return rawurlencode($scopesAsString);
	}

	public function GetAccessToken($redirect_uri = false)
	{
		$tokens = $this->getStorageTokens();

		if(is_array($tokens))
		{
			$this->access_token = $tokens["OATOKEN"];
			$this->accessTokenExpires = $tokens["OATOKEN_EXPIRES"];

			if(!$this->code)
			{
				if($this->checkAccessToken())
				{
					return true;
				}
				elseif(isset($tokens["REFRESH_TOKEN"]))
				{
					if($this->getNewAccessToken($tokens["REFRESH_TOKEN"], $this->userId, true))
					{
						return true;
					}
				}
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

		if($redirect_uri === false)
		{
			if(IsModuleInstalled('bitrix24') && defined('BX24_HOST_NAME'))
			{
				$redirect_uri = \CSocServOffice365OAuth::getControllerUrl()."/redirect.php";
			}
			else
			{
				$redirect_uri = $this->getRedirectUri();
			}
		}

		$httpClient = new \Bitrix\Main\Web\HttpClient();

		$requestData = http_build_query([
			"code" => $this->code,
			"client_id" => $this->appID,
			"client_secret" => $this->appSecret,
			"redirect_uri" => $redirect_uri,
			"grant_type" => "authorization_code",
			"scope" => implode(' ', array_unique($this->getScope())),
		], '', '&', PHP_QUERY_RFC3986);

		$result = $httpClient->post(static::TOKEN_URL, $requestData);

		try
		{
			$arResult = \Bitrix\Main\Web\Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$arResult = [];
		}

		if(isset($arResult["access_token"]) && $arResult["access_token"] <> '')
		{
			$this->access_token = $arResult["access_token"];
			$this->accessTokenExpires = $arResult["expires_in"];
			if(isset($arResult["refresh_token"]) && $arResult["refresh_token"] <> '')
			{
				$this->refresh_token = $arResult["refresh_token"];
			}
			$_SESSION["OAUTH_DATA"] = array("OATOKEN" => $this->access_token);
			return true;
		}

		$this->logger->error('oauth.token.exchange_failed', [
			'reason' => 'token_not_found_in_response',
		]);

		return false;
	}

	public function getNewAccessToken($refreshToken, $userId = 0, $save = false)
	{
		if($this->appID == false || $this->appSecret == false)
			return false;

		$httpClient = new \Bitrix\Main\Web\HttpClient();

		$result = $httpClient->post(static::TOKEN_URL, array(
			"refresh_token"=>$refreshToken,
			"client_id"=>$this->appID,
			"client_secret"=>$this->appSecret,
			"grant_type"=>"refresh_token",
		));

		try
		{
			$arResult = \Bitrix\Main\Web\Json::decode($result);
		}
		catch(\Bitrix\Main\ArgumentException $e)
		{
			$arResult = array();
		}

		if(isset($arResult["access_token"]) && $arResult["access_token"] <> '')
		{
			$this->access_token = $arResult["access_token"];
			$this->accessTokenExpires = $arResult["expires_in"];
			if($save && intval($userId) > 0)
			{
				$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
					'filter' => ['=USER_ID' => intval($userId), "=EXTERNAL_AUTH_ID" => static::SERVICE_ID],
					'select' => ["ID"]
				]);
				if($arOauth = $dbSocservUser->fetch())
					\Bitrix\Socialservices\UserTable::update($arOauth["ID"], array("OATOKEN" => $this->access_token,"OATOKEN_EXPIRES" => time() + $this->accessTokenExpires));
			}
			return true;
		}
		return false;
	}

	public function getResource()
	{
		return $this->resource;
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

		$httpClient = new \Bitrix\Main\Web\HttpClient();
		$httpClient->setHeader("Authorization", "Bearer ". $this->access_token);

		$result = $httpClient->get($this->resource.static::VERSION.static::CONTACTS_URL);
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
			$result["access_token"] = $this->access_token;
			$result["refresh_token"] = $this->refresh_token;
			$result["expires_in"] = $this->accessTokenExpires;

			return $result;
		}

		$this->logger->error('oauth.user.fetch_failed', [
			'reason' => 'invalid_response_payload',
		]);

		return $result;
	}

	public function getTenant()
	{
		$tokenInfo = $this->getStorageTokens();
		if($tokenInfo && $tokenInfo["PERMISSIONS"])
		{
			$permissions = unserialize($tokenInfo["PERMISSIONS"], ["allowed_classes" => false]);

			return $permissions["tenant"];
		}

		return false;
	}

	public function getRedirectUri()
	{
		return (string)(new Uri(static::REDIRECT_URI))->toAbsolute();
	}
}

/* @deprecated */
class COffice365OAuthInterfaceOld extends COffice365OAuthInterface
{
	const RESOURCE_TPL = "https://#TENANT#-my.sharepoint.com";

	const VERSION = "/_api/v2.0";

	protected $tenant = null;

	public function __construct($tenant = false, $appID = false, $appSecret = false, $code=false)
	{
		if($tenant === false)
		{
			$tenant = trim(CSocServOffice365OAuth::GetOption("office365_tenant"));
		}

		$this->setTenant($tenant);

		return parent::__construct($appID, $appSecret, $code);
	}

	public function getTenant()
	{
		return $this->tenant;
	}

	public function setTenant($tenant)
	{
		$this->tenant = $tenant;
		$this->resource = str_replace("#TENANT#", $this->tenant, static::RESOURCE_TPL);
	}
}

?>
