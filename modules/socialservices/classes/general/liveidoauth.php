<?php

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

IncludeModuleLangFile(__FILE__);

class CSocServLiveIDOAuth extends CSocServAuth
{
	const ID = "LiveIDOAuth";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";

	/** @var CLiveIDOAuthInterface null  */
	protected $entityOAuth = null;

	public function getEntityOAuth()
	{
		if (!$this->entityOAuth)
		{
			$this->entityOAuth = new CLiveIDOAuthInterface();
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	public function GetSettings()
	{
		return array(
			array("liveid_appid", GetMessage("socserv_liveid_client_id"), "", Array("text", 40)),
			array("liveid_appsecret", GetMessage("socserv_liveid_client_secret"), "", Array("text", 40)),
			array(
				'note' => getMessage(
					'socserv_liveid_form_note_3',
					array(
						'#URL#' => (string)(new Uri('/bitrix/tools/oauth/liveid.php'))->toAbsolute(),
						'#MAIL_URL#' => (string)(new Uri('/bitrix/tools/mail_oauth.php'))->toAbsolute(),
					)
				),
			),
		);
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl('opener', null, $arParams);
		if($arParams["FOR_INTRANET"])
			return array("ON_CLICK" => 'onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)"');
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
			'redirect_url' => $this->getRedirectUrl($arParams),
			'mode' => $location,
		];
		$state = \Bitrix\Socialservices\OAuth\StateService::getInstance()->createState($stateFields);

		if ($this->userId == null)
		{
			$this->getEntityOAuth()->setRefreshToken("skip");
		}
		if ($addScope !== null)
		{
			$this->getEntityOAuth()->addScope($addScope);
		}

		if ($this->isCloudPortal())
		{
			$portalRedirectUri = (new Uri('/bitrix/tools/oauth/liveid.php'))
				->addParams(['state' => $state,])
				->toAbsolute()
			;

			$state = (string)$portalRedirectUri;
			$redirect_uri = self::CONTROLLER_URL . '/redirect.php';
		}
		else
		{
			$redirect_uri = (string)(new Uri("/bitrix/tools/oauth/liveid.php"))->toAbsolute();
		}

		return $this->getEntityOAuth()->GetAuthUrl($redirect_uri, $state);
	}

	public function getStorageToken()
	{
		$accessToken = null;
		$userId = intval($this->userId);
		if($userId > 0)
		{
			$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
				'filter' => ['=USER_ID' => $userId, "=EXTERNAL_AUTH_ID" => "LiveIDOAuth"],
				'select' => ["OATOKEN", "REFRESH_TOKEN", "OATOKEN_EXPIRES"]
			]);
			if($arOauth = $dbSocservUser->fetch())
			{
				$accessToken = $arOauth["OATOKEN"];

				if(empty($accessToken) || ((intval($arOauth["OATOKEN_EXPIRES"]) > 0) && (intval($arOauth["OATOKEN_EXPIRES"] < intval(time())))))
				{
					if(isset($arOauth['REFRESH_TOKEN']))
						$this->entityOAuth->getNewAccessToken($arOauth['REFRESH_TOKEN'], $userId, true);
					if(($accessToken = $this->entityOAuth->getToken()) === false)
						return null;
				}
			}
		}

		return $accessToken;
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
			if ($this->isCloudPortal())
				$redirect_uri = self::CONTROLLER_URL."/redirect.php";
			else
				$redirect_uri = (string)(new Uri("/bitrix/tools/oauth/liveid.php"))->toAbsolute();

			$appID = trim(self::GetOption("liveid_appid"));
			$appSecret = trim(self::GetOption("liveid_appsecret"));

			$gAuth = new CLiveIDOAuthInterface($appID, $appSecret, $_REQUEST["code"]);
			$gAuth->setLogger($this->logger);

			$bProcessState = true;

			if($gAuth->GetAccessToken($redirect_uri) !== false)
			{

				$arLiveIDUser = $gAuth->GetCurrentUser();
				if(is_array($arLiveIDUser) &&  ($arLiveIDUser['id'] <> ''))
				{
					$email = $first_name = $last_name = "";
					$login = "LiveID".$arLiveIDUser['id'];
					$uId = $arLiveIDUser['id'];
					if($arLiveIDUser['first_name'] <> '')
						$first_name = $arLiveIDUser['first_name'];
					if($arLiveIDUser['last_name'] <> '')
						$last_name = $arLiveIDUser['last_name'];
					if($arLiveIDUser['emails']['preferred'] <> '')
					{
						$email = $arLiveIDUser['emails']['preferred'];
						$login = $arLiveIDUser['emails']['preferred'];
						$uId = $arLiveIDUser['emails']['preferred'];
					}
					$arFields = array(
						'EXTERNAL_AUTH_ID' => self::ID,
						'XML_ID' => $uId,
						'LOGIN' => $login,
						'EMAIL' => $email,
						'NAME'=> $first_name,
						'LAST_NAME'=> $last_name,
					);
					$arFields["PERSONAL_WWW"] = $arLiveIDUser["link"];
					if(isset($arLiveIDUser['access_token']))
						$arFields["OATOKEN"] = $arLiveIDUser['access_token'];

					if(isset($arLiveIDUser['refresh_token']))
						$arFields["REFRESH_TOKEN"] = $arLiveIDUser['refresh_token'];

					if(isset($arLiveIDUser['expires_in']))
						$arFields["OATOKEN_EXPIRES"] = time() + $arLiveIDUser['expires_in'];
					if(SITE_ID <> '')
						$arFields["SITE_ID"] = SITE_ID;
					$bSuccess = $this->AuthorizeUser($arFields);

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

		$this->onAfterWebAuth($addParams, $mode, $url);

		CMain::FinalActions();
	}

	public function getFriendsList($limit = 0, $offset = 0)
	{
		$li = new CLiveIDOAuthInterface();
		$li->setLogger($this->logger);

		if ($this->isCloudPortal())
		{
			$redirect_uri = self::CONTROLLER_URL."/redirect.php";
		}
		else
		{
			$redirect_uri = (string)(new Uri("/bitrix/tools/oauth/liveid.php"))->toAbsolute();
		}

		if($li->GetAccessToken($redirect_uri) !== false)
		{
			$res = $li->GetCurrentUserFriends($limit, $offset);
		}

		if(is_array($res) && is_array($res['data']))
		{
			foreach($res['data'] as $key => $contact)
			{
				$res['data'][$key]['uid'] = $contact['id'];
				$res['data'][$key]['url'] = $this->getProfileUrl($contact['id']);
			}
			return $res['data'];
		}

		return false;
	}

	public function getProfileUrl($id)
	{
		return 'https://people.live.com/';
	}
}

class CLiveIDOAuthInterface
{
	const SERVICE_ID = "LiveIDOAuth";

	const AUTH_URL = "https://login.live.com/oauth20_authorize.srf";
	const TOKEN_URL = "https://login.live.com/oauth20_token.srf";
	const CONTACTS_URL = "https://apis.live.net/v5.0/me/";
	const FRIENDS_URL = "https://apis.live.net/v5.0/me/contacts/";

	protected $appID;
	protected $appSecret;
	protected $code = false;
	protected $access_token = false;
	protected $accessTokenExpires = 0;
	protected $refresh_token = '';
	protected $scope = array(
		'wl.signin',
		'wl.basic',
		'wl.offline_access',
		'wl.emails',
	);

	protected LoggerInterface $logger;

	public function __construct($appID = false, $appSecret = false, $code=false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServLiveIDOAuth::GetOption("liveid_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServLiveIDOAuth::GetOption("liveid_appsecret"));
		}

		$this->httpTimeout = SOCSERV_DEFAULT_HTTP_TIMEOUT;
		$this->appID = $appID;
		$this->appSecret = $appSecret;
		$this->code = $code;
		$this->logger = new NullLogger();
	}

	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	public function getAccessTokenExpires()
	{
		return $this->accessTokenExpires;
	}

	public function getAppID()
	{
		return $this->appID;
	}

	public function getAppSecret()
	{
		return $this->appSecret;
	}

	public function getToken()
	{
		return $this->access_token;
	}

	/**
	 * @param string $refresh_token
	 */
	public function setRefreshToken($refresh_token)
	{
		$this->refresh_token = $refresh_token;
	}

	public function setScope($scope)
	{
		$this->scope = $scope;
	}

	public function getScope()
	{
		return $this->scope;
	}

	public function addScope($scope)
	{
		if(is_array($scope))
			$this->scope = array_merge($this->scope, $scope);
		else
			$this->scope[] = $scope;
		return $this;
	}

	public function getScopeEncode()
	{
		return implode('+', array_map('urlencode', array_unique($this->getScope())));
	}

	public function GetAuthUrl($redirect_uri, $state='')
	{
		return self::AUTH_URL.
			"?client_id=".urlencode($this->appID).
			"&redirect_uri=".urlencode($redirect_uri).
			"&scope=".$this->getScopeEncode().
			"&response_type=code".
			($state <> ''? '&state='.urlencode($state):'');
	}

	public function GetAccessToken($redirect_uri)
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
					if($this->getNewAccessToken($tokens["REFRESH_TOKEN"]))
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

		$result = CHTTP::sPostHeader(self::TOKEN_URL, array(
			"code"=>$this->code,
			"client_id"=>$this->appID,
			"client_secret"=>$this->appSecret,
			"redirect_uri"=>$redirect_uri,
			"grant_type"=>"authorization_code",
		), array(), $this->httpTimeout);

		$arResult = CUtil::JsObjectToPhp($result);

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

	public function GetCurrentUser()
	{
		if($this->access_token === false)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'empty_access_token',
			]);

			return false;
		}

		$result = CHTTP::sGetHeader(self::CONTACTS_URL."?access_token=".urlencode($this->access_token), array(), $this->httpTimeout);

		$result = CUtil::JsObjectToPhp($result);

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

	public function GetCurrentUserFriends($limit = 0, $offset = 0)
	{
		if($this->access_token === false)
			return false;

		$url = self::FRIENDS_URL."?access_token=".urlencode($this->access_token);

		if($limit > 0)
		{
			$url .= '&limit='.intval($limit)."&offset=".intval($offset);
		}

		$result = CHTTP::sGetHeader($url, array(), $this->httpTimeout);

		$result = CUtil::JsObjectToPhp($result);

		if(is_array($result))
		{
			$result["access_token"] = $this->access_token;
			$result["refresh_token"] = $this->refresh_token;
			$result["expires_in"] = $this->accessTokenExpires;
		}
		return $result;
	}

	private function getStorageTokens()
	{
		global $USER;

		if(is_object($USER))
		{
			$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
				'filter' => ['=USER_ID' => $USER->GetID(), "=EXTERNAL_AUTH_ID" => CSocServLiveIDOAuth::ID],
				'select' => ["USER_ID", "OATOKEN", "OATOKEN_EXPIRES", "REFRESH_TOKEN"]
			]);
			return $dbSocservUser->fetch();
		}

		return false;
	}

	private function checkAccessToken()
	{
		return (($this->access_token - 30) < time()) ? false : true;
	}

	public function getNewAccessToken($refreshToken, $userId = 0, $save = false)
	{
		if($this->appID == false || $this->appSecret == false)
			return false;

		$result = CHTTP::sPostHeader(self::TOKEN_URL, array(
			"refresh_token"=>$refreshToken,
			"client_id"=>$this->appID,
			"client_secret"=>$this->appSecret,
			"grant_type"=>"refresh_token",
		), array(), $this->httpTimeout);

		try
		{
			$arResult = Json::decode($result);
		}
		catch (ArgumentException)
		{
			return false;
		}

		if(isset($arResult["access_token"]) && $arResult["access_token"] <> '')
		{
			$this->access_token = $arResult["access_token"];
			$this->accessTokenExpires = $arResult["expires_in"];
			if($save && intval($userId) > 0)
			{
				$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
					'filter' => [
						'=USER_ID' => intval($userId),
						"=EXTERNAL_AUTH_ID" => 'LiveIDOAuth'
					],
					'select' => ["ID"]
				]);
				if($arOauth = $dbSocservUser->fetch())
					\Bitrix\Socialservices\UserTable::update($arOauth["ID"], array("OATOKEN" => $this->access_token, "OATOKEN_EXPIRES" => time() + $this->accessTokenExpires));
			}
			return true;
		}
		return false;
	}

	protected function deleteStorageTokens()
	{
		global $USER;

		if(is_object($USER) && $USER->IsAuthorized())
		{
			$dbSocservUser = \Bitrix\Socialservices\UserTable::getList(array(
				'filter' => array(
					'=USER_ID' => $USER->GetID(),
					"=EXTERNAL_AUTH_ID" => static::SERVICE_ID
				),
				'select' => array("ID")
			));

			while($accessToken = $dbSocservUser->fetch())
			{
				\Bitrix\Socialservices\UserTable::delete($accessToken['ID']);
			}
		}
	}
}
?>