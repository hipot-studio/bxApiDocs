<?php

use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

IncludeModuleLangFile(__FILE__);

class CSocServBoxAuth extends CSocServAuth
{
	const ID = "Box";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";
	const LOGIN_PREFIX = "B_";

	/** @var CBoxOAuthInterface null  */
	protected $entityOAuth = null;

	/**
	 * @param string $code=false
	 * @return CBoxOAuthInterface
	 */
	public function getEntityOAuth($code = false)
	{
		if(!$this->entityOAuth)
		{
			$this->entityOAuth = new CBoxOAuthInterface();
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
			array("box_appid", GetMessage("socserv_box_client_id"), "", array("text", 40)),
			array("box_appsecret", GetMessage("socserv_box_client_secret"), "", array("text", 40)),
			array("note"=>GetMessage("socserv_box_note_2", array('#URL#'=>CBoxOAuthInterface::GetRedirectURI()))),
		);
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl('opener', null, $arParams);

		$phrase = ($arParams["FOR_INTRANET"]) ? GetMessage("socserv_box_form_note_intranet") : GetMessage("socserv_box_form_note");

		if($arParams["FOR_INTRANET"])
		{
			return array("ON_CLICK" => 'onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 600)"');
		}
		else
		{
			return '<a href="javascript:void(0)" onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 600)" class="bx-ss-button box-button"></a><span class="bx-spacer"></span><span>'.$phrase.'</span>';
		}
	}

	public function GetOnClickJs($arParams)
	{
		$url = $this->getUrl('opener', null, $arParams);
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
				CBoxOAuthInterface::GetRedirectURI()
			);
			$portalRedirectUri->addParams([
				'state' => $state,
			]);

			$state = (string)$portalRedirectUri;
			$redirect_uri = static::CONTROLLER_URL . '/redirect.php';
		}
		else
		{
			$redirect_uri = CBoxOAuthInterface::GetRedirectURI();
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
				'select' => ["USER_ID", "OATOKEN", "REFRESH_TOKEN", "OATOKEN_EXPIRES"]
			]);
			if($arOauth = $dbSocservUser->fetch())
			{
				$accessToken = $arOauth["OATOKEN"];
				$accessTokenExpires = $arOauth["OATOKEN_EXPIRES"];

				$entityOauth = $this->getEntityOAuth();
				$entityOauth->setToken($accessToken);
				$entityOauth->setAccessTokenExpires($accessTokenExpires);

				if($entityOauth->checkAccessToken())
				{
					return $accessToken;
				}
				elseif(isset($arOauth["REFRESH_TOKEN"]))
				{
				if($entityOauth->getNewAccessToken($arOauth["REFRESH_TOKEN"], $arOauth["USER_ID"],true))
					{
						return $entityOauth->getToken();
					}
				}
			}
		}

		return $accessToken;
	}

	public function prepareUser($boxUser, $short = false)
	{
		$nameDetails = explode(" ", $boxUser['name'], 2);

		$id = $boxUser['id'];

		$arFields = array(
			'EXTERNAL_AUTH_ID' => static::ID,
			'XML_ID' => $id,
			'LOGIN' => static::LOGIN_PREFIX.$id,
			'NAME'=> $nameDetails[0],
			'LAST_NAME'=> $nameDetails[1],
			'EMAIL' => $boxUser["login"],
			'OATOKEN' => $this->entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $this->entityOAuth->getAccessTokenExpires(),
			'REFRESH_TOKEN' => $this->entityOAuth->getRefreshToken(),
		);

		if(!$short && !empty($boxUser['avatar_url']))
		{
			$picture_url = $boxUser['avatar_url'];
			$temp_path = CFile::GetTempName('', 'picture.jpg');

			$ob = new HttpClient(array(
				"redirect" => true
			));
			$ob->download($picture_url, $temp_path);

			$arPic = CFile::MakeFileArray($temp_path);
			if($arPic)
			{
				$arFields["PERSONAL_PHOTO"] = $arPic;
			}
		}

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

				$boxUser = $this->entityOAuth->GetCurrentUser();

				if(is_array($boxUser))
				{
					$arFields = self::prepareUser($boxUser);
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

class CBoxOAuthInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "Box";

	const AUTH_URL = "https://app.box.com/api/oauth2/authorize";
	const TOKEN_URL = "https://app.box.com/api/oauth2/token";

	const ACCOUNT_URL = "https://api.box.com/2.0/users/me";

	protected $oauthResult;

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServBoxAuth::GetOption("box_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServBoxAuth::GetOption("box_appsecret"));
		}

		parent::__construct($appID, $appSecret, $code);
	}

	public static function GetRedirectURI()
	{
		return (string)(new Uri("/bitrix/tools/oauth/box.php"))->toAbsolute();
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
		$token = $this->getStorageTokens();

		if(is_array($token))
		{
			if(!$this->code)
			{
				$this->access_token = $token["OATOKEN"];
				$this->accessTokenExpires = $token["OATOKEN_EXPIRES"];

				if($this->checkAccessToken())
				{
					return true;
				}
				elseif(isset($token["REFRESH_TOKEN"]))
				{
					if($this->getNewAccessToken($token["REFRESH_TOKEN"], $token["USER_ID"], true))
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

		$h = new HttpClient();
		$result = $h->post(static::TOKEN_URL, array(
			"code"=>$this->code,
			"client_id"=>$this->appID,
			"client_secret"=>$this->appSecret,
			"redirect_uri"=>$redirect_uri,
			"grant_type"=>"authorization_code",
		));

		$this->oauthResult = Json::decode($result);

		if(isset($this->oauthResult["access_token"]) && $this->oauthResult["access_token"] <> '')
		{
			$this->access_token = $this->oauthResult["access_token"];
			$this->accessTokenExpires = time() + $this->oauthResult["expires_in"];

			if(isset($this->oauthResult["refresh_token"]) && $this->oauthResult["refresh_token"] <> '')
			{
				$this->refresh_token = $this->oauthResult["refresh_token"];
			}

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

	public function getNewAccessToken($refreshToken = false, $userId = 0, $save = false, $scope = array())
	{
		if($this->appID == false || $this->appSecret == false)
		{
			return false;
		}

		if($refreshToken == false)
		{
			$refreshToken = $this->refresh_token;
		}

		$http = new HttpClient(array('socketTimeout' => $this->httpTimeout));

		$result = $http->post(static::TOKEN_URL, array(
			'client_id' => $this->appID,
			'client_secret' => $this->appSecret,
			'refresh_token' => $refreshToken,
			'grant_type' => 'refresh_token',
		));

		$arResult = Json::decode($result);

		if(isset($arResult["access_token"]) && $arResult["access_token"] <> '')
		{
			$this->access_token = $arResult["access_token"];
			$this->accessTokenExpires = time() + $arResult["expires_in"];
			$this->refresh_token = $arResult["refresh_token"];

			if($save && intval($userId) > 0)
			{
				$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
					'filter' => [
						"=USER_ID" => intval($userId),
						"=EXTERNAL_AUTH_ID" => CSocServBoxAuth::ID
					],
					'select' => ["ID"]
				]);

				$arOauth = $dbSocservUser->fetch();

				if($arOauth)
				{
					\Bitrix\Socialservices\UserTable::update(
						$arOauth["ID"], array(
							"OATOKEN" => $this->access_token,
							"OATOKEN_EXPIRES" => $this->accessTokenExpires,
							"REFRESH_TOKEN" => $this->refresh_token,
						)
					);
				}
			}

			return true;
		}
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

		$h = new HttpClient();
		$h->setHeader("Authorization", "Bearer ".$this->access_token);

		$result = $h->get(static::ACCOUNT_URL);

		try
		{
			$result = Json::decode($result);
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

			return $result;
		}

		$this->logger->error('oauth.user.fetch_failed', [
			'reason' => 'invalid_response_payload',
		]);

		return $result;
	}
}