<?php

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Socialservices\OAuth\StateService;

IncludeModuleLangFile(__FILE__);

class CSocServMailRu2 extends CSocServAuth
{
	const ID = "MailRu2";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";

	protected $entityOAuth;

	public function GetSettings()
	{
		return array(
			array("mailru2_client_id", GetMessage("socserv_mailru2_id"), "", Array("text", 40)),
			array("mailru2_client_secret", GetMessage("socserv_mailru2_key"), "", Array("text", 40)),
			array(
				'note' => getMessage(
					'socserv_mailru2_sett_note_2',
					array(
						'#URL#' => $this->getEntityOAuth()->getRedirectUri(),
						'#MAIL_URL#' => (string)(new Uri('/bitrix/tools/mail_oauth.php'))->toAbsolute(),
					)
				),
			),
		);
	}

	/**
	 * @param string|bool $code = false
	 * @return CMailRu2Interface
	 */
	public function getEntityOAuth($code = false)
	{
		if (!$this->entityOAuth)
		{
			$this->entityOAuth = new CMailRu2Interface();
		}

		if ($code !== false)
		{
			$this->entityOAuth->setCode($code);
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl($arParams);

		$phrase = ($arParams["FOR_INTRANET"])
			? GetMessage("socserv_mailru2_note_intranet")
			: GetMessage("socserv_mailru2_note");

		return $arParams["FOR_INTRANET"]
			? array("ON_CLICK" => 'onclick="BX.util.popup(\'' . htmlspecialcharsbx(CUtil::JSEscape($url)) . '\', 680, 800)"')
			: '<a href="javascript:void(0)" onclick="BX.util.popup(\'' . htmlspecialcharsbx(CUtil::JSEscape($url)) . '\', 680, 800)" class="bx-ss-button mailru-button"></a><span class="bx-spacer"></span><span>' . $phrase . '</span>';
	}

	public function GetOnClickJs($arParams)
	{
		$url = $this->getUrl($arParams);
		return "BX.util.popup('" . CUtil::JSEscape($url) . "', 680, 800)";
	}

	public function getUrl($arParams)
	{
		global $APPLICATION;

		/**
		 * @var \CMain $APPLICATION
		 */

		$backUrl = (string)(
			$arParams['BACKURL']
			?? $APPLICATION->GetCurPageParam('', [
				'logout', 'auth_service_error', 'auth_service_id', 'backurl',
			])
		);
		$state = StateService::getInstance()->createState([
			'site_id' => SITE_ID,
			'check_key' => \CSocServAuthManager::getUniqueKey(),
			'redirect_url' => $backUrl,
		]);

		if ($this->isCloudPortal())
		{
			$portalRedirectUri = new Uri(
				$this->getEntityOAuth()->GetRedirectURI()
			);
			$portalRedirectUri->addParams([
				'state' => $state,
			]);

			$state = (string)$portalRedirectUri;
			$redirectUri = new Uri(
				static::CONTROLLER_URL . '/redirect.php'
			);
		}
		else
		{
			$redirectUri = $this->getEntityOAuth()->GetRedirectURI();
		}

		return $this->getEntityOAuth()->GetAuthUrl($redirectUri, $state);
	}

	public function addScope($scope)
	{
		return $this->getEntityOAuth()->addScope($scope);
	}

	public function prepareUser($arUser, $short = false)
	{
		$entityOAuth = $this->getEntityOAuth();
		$arFields = array(
			'EXTERNAL_AUTH_ID' => self::ID,
			'XML_ID' => $arUser["email"],
			'LOGIN' => $arUser["email"],
			'EMAIL' => $arUser["email"],
			'NAME' => $arUser["first_name"],
			'LAST_NAME' => $arUser["last_name"],
			'OATOKEN' => $entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $entityOAuth->getAccessTokenExpires(),
		);

		if (!$short && isset($arUser['image']))
		{
			$picture_url = $arUser['image'];
			$temp_path = CFile::GetTempName('', 'picture.jpg');

			$ob = new HttpClient(array(
				"redirect" => true
			));
			$ob->download($picture_url, $temp_path);

			$arPic = CFile::MakeFileArray($temp_path);
			if ($arPic)
			{
				$arFields["PERSONAL_PHOTO"] = $arPic;
			}
		}

		if (isset($arUser['birthday']))
		{
			if ($date = MakeTimeStamp($arUser['birthday'], "MM/DD/YYYY"))
			{
				$arFields["PERSONAL_BIRTHDAY"] = ConvertTimeStamp($date);
			}
		}

		if (isset($arUser['gender']) && $arUser['gender'] != '')
		{
			if ($arUser['gender'] == 'm')
			{
				$arFields["PERSONAL_GENDER"] = 'M';
			}
			elseif ($arUser['gender'] == 'f')
			{
				$arFields["PERSONAL_GENDER"] = 'F';
			}
		}

		if (SITE_ID <> '')
		{
			$arFields["SITE_ID"] = SITE_ID;
		}

		return $arFields;
	}

	public function Authorize()
	{
		global $APPLICATION;

		$APPLICATION->RestartBuffer();
		$authError = SOCSERV_AUTHORISATION_ERROR;
		$this->logger->info('oauth.auth.start');

		if (empty($_REQUEST['code']))
		{
			$this->logger->error('oauth.request.invalid_code');
			$this->sendOauthError(OAuthErrorCode::MissingCode);
		}
		elseif (CSocServAuthManager::CheckUniqueKey())
		{
			if ($this->isCloudPortal())
			{
				$redirect_uri = static::CONTROLLER_URL . "/redirect.php";
			}
			else
			{
				$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();
			}

			$entityOAuth = $this->getEntityOAuth($_REQUEST['code']);
			if ($entityOAuth->GetAccessToken($redirect_uri) !== false)
			{
				$arUser = $entityOAuth->GetCurrentUser();
				if (is_array($arUser) && isset($arUser["email"]))
				{
					$authError = $this->AuthorizeUser(
						$this->prepareUser($arUser)
					);
				}
				else
				{
					$this->logger->error('oauth.user.fetch_failed', [
						'reason' => 'missing_user_email',
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

		$url = $this->getRedirectUriAfterAuthorize($authError, self::ID);

		$this->onAfterWebAuth(true, self::OPENER_MODE, $url);
		CMain::FinalActions();
	}

	public function setUser($userId)
	{
		$this->getEntityOAuth()->setUser($userId);
	}
}


class CMailRu2Interface extends CSocServOAuthTransport
{
	const SERVICE_ID = "MailRu2";

	const AUTH_URL = "https://oauth.mail.ru/login";
	const TOKEN_URL = "https://oauth.mail.ru/token";
	const USER_INFO_URL = "https://oauth.mail.ru/userinfo";

	protected $userId = false;
	protected $responseData = array();

	protected $scope = array(
		"userinfo",
	);

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		if ($appID === false)
		{
			$appID = trim(CSocServAuth::GetOption("mailru2_client_id"));
		}

		if ($appSecret === false)
		{
			$appSecret = trim(CSocServAuth::GetOption("mailru2_client_secret"));
		}

		parent::__construct($appID, $appSecret, $code);
	}

	/**
	 * @return string
	 */
	public function GetRedirectURI()
	{
		return (string)(new Uri("/bitrix/tools/oauth/mailru2.php"))->toAbsolute();
	}

	/**
	 * @param string $redirect_uri
	 * @param string $state
	 * @param bool $forceConsent If true, adds prompt=consent to always show the consent screen with scopes.
	 * Required together with offline_access scope to obtain a refresh_token.
	 * @return string
	 */
	public function GetAuthUrl(string $redirect_uri, string $state = '', bool $forceConsent = false): string
	{
		return self::AUTH_URL
			."?client_id=".$this->appID
			."&redirect_uri=".urlencode($redirect_uri)
			."&scope=".$this->getScopeEncode()
			."&response_type="."code"
			.($state <> '' ? '&state='.urlencode($state) : '')
			.'&prompt_force=1'
			.($forceConsent ? '&prompt=consent' : '');
	}

	/**
	 * @return array
	 */
	public function getResult()
	{
		return $this->responseData;
	}

	/**
	 * @param string $redirect_uri
	 *
	 * @return bool
	 */
	public function GetAccessToken($redirect_uri)
	{
		$token = $this->getStorageTokens();
		if (is_array($token))
		{
			$this->access_token = $token["OATOKEN"];
			$this->accessTokenExpires = $token["OATOKEN_EXPIRES"];

			if (!$this->code)
			{
				if ($this->checkAccessToken())
				{
					return true;
				}
				else if (isset($token['REFRESH_TOKEN']))
				{
					if ($this->getNewAccessToken($token['REFRESH_TOKEN'], $this->userId, true))
					{
						return true;
					}
				}
			}

			$this->deleteStorageTokens();
		}

		if ($this->code === false)
		{
			$this->logger->error('oauth.token.exchange_failed', [
				'reason' => 'empty_code',
			]);

			return false;
		}

		$query = array(
			"code" => $this->code,
			"grant_type" => "authorization_code",
			"redirect_uri" => $redirect_uri,
		);

		$h = new \Bitrix\Main\Web\HttpClient(array(
			"socketTimeout" => $this->httpTimeout,
			"streamTimeout" => $this->httpTimeout,
		));
		$h->setAuthorization($this->appID, $this->appSecret);
		$h->setHeader('User-Agent', 'Bitrix'); // Mail.ru requires User-Agent to be set

		$result = $h->post(self::TOKEN_URL, $query);

		try
		{
			$arResult = \Bitrix\Main\Web\Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$arResult = array();
		}

		if ((isset($arResult["access_token"]) && $arResult["access_token"] <> ''))
		{
			$this->access_token = $arResult["access_token"];
			$this->accessTokenExpires = time() + $arResult["expires_in"];
			$this->refresh_token = $arResult['refresh_token'];

			$_SESSION["OAUTH_DATA"] = array(
				"OATOKEN" => $this->access_token,
				"OATOKEN_EXPIRES" => $this->accessTokenExpires,
				"REFRESH_TOKEN" => $this->refresh_token
			);
			return true;
		}

		$this->logger->error('oauth.token.exchange_failed', [
			'reason' => 'token_not_found_in_response',
		]);

		return false;
	}

	/**
	 * @param bool $refreshToken
	 * @param int $userId
	 * @param bool $save
	 *
	 * @return bool
	 */
	public function getNewAccessToken($refreshToken = false, $userId = 0, $save = false)
	{
		if ($this->appID == false || $this->appSecret == false)
		{
			return false;
		}

		if ($refreshToken == false)
		{
			$refreshToken = $this->refresh_token;
		}

		$http = new HttpClient(array(
			'socketTimeout' => $this->httpTimeout,
			'streamTimeout' => $this->httpTimeout,
		));
		$http->setHeader('User-Agent', 'Bitrix');

		$result = $http->post(static::TOKEN_URL, array(
			'refresh_token' => $refreshToken,
			'client_id' => $this->appID,
			'client_secret' => $this->appSecret,
			'grant_type' => 'refresh_token',
		));

		try
		{
			$arResult = Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$arResult = array();
		}

		if (!empty($arResult['access_token']))
		{
			$this->access_token = $arResult['access_token'];
			$this->accessTokenExpires = $arResult['expires_in'] + time();
			if ($save && intval($userId) > 0)
			{
				$dbSocservUser = \Bitrix\Socialservices\UserTable::getList(array(
					'filter' => array(
						'=EXTERNAL_AUTH_ID' => static::SERVICE_ID,
						'=USER_ID' => $userId,
					),
					'select' => array('ID')
				));
				if ($arOauth = $dbSocservUser->fetch())
				{
					\Bitrix\Socialservices\UserTable::update($arOauth['ID'], array(
						'OATOKEN' => $this->access_token,
						'OATOKEN_EXPIRES' => $this->accessTokenExpires)
					);
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * @return array|false
	 */
	public function GetCurrentUser()
	{
		if ($this->access_token === false)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'empty_access_token',
			]);

			return false;
		}

		$http = new HttpClient();
		$http->setTimeout($this->httpTimeout);

		$result = $http->get(self::USER_INFO_URL . '?access_token=' . $this->access_token);

		try
		{
			$decoded = Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response',
			]);

			return false;
		}

		if (!is_array($decoded))
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response_payload',
			]);
		}

		return $decoded;
	}

	/**
	 * @return bool
	 */
	public function GetAppInfo()
	{
		return false;
	}

	/**
	 * @return string
	 */
	public function getScopeEncode()
	{
		return implode(' ', array_map('urlencode', array_unique($this->getScope())));
	}

}
