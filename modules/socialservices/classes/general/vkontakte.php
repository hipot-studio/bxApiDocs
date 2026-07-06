<?php

use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;

IncludeModuleLangFile(__FILE__);

class CSocServVKontakte extends CSocServAuth
{
	const ID = "VKontakte";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";

	protected $entityOAuth = NULL;

	public function GetSettings()
	{
		return array(
			array("vkontakte_appid", GetMessage("socserv_vk_id"), "", Array("text", 40)),
			array("vkontakte_appsecret", GetMessage("socserv_vk_key"), "", Array("text", 40)),
			array("note" => GetMessage("socserv_vk_sett_note2_MSGVER_1", array('#URL#'=>$this->getEntityOAuth()->GetRedirectURI()))),
		);
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl($arParams);

		$phrase = ($arParams["FOR_INTRANET"]) ? GetMessage("socserv_vk_note_intranet") : GetMessage("socserv_vk_note");
		if ($arParams["FOR_INTRANET"])
			return array("ON_CLICK" => 'onclick="BX.util.popup(\'' . htmlspecialcharsbx(CUtil::JSEscape($url)) . '\', 680, 800)"');

		return '<a href="javascript:void(0)" onclick="BX.util.popup(\'' . htmlspecialcharsbx(CUtil::JSEscape($url)) . '\', 680, 800)" class="bx-ss-button vkontakte-button"></a><span class="bx-spacer"></span><span>' . $phrase . '</span>';
	}

	public function GetOnClickJs($arParams)
	{
		$url = $this->getUrl($arParams);

		return "BX.util.popup('" . CUtil::JSEscape($url) . "', 680, 800)";
	}

	public function getUrl($arParams)
	{
		$stateFields = [
			'site_id' => SITE_ID,
			'check_key' => \CSocServAuthManager::getUniqueKey(),
			'redirect_url' => $this->getRedirectUrl($arParams),
		];
		$state = \Bitrix\Socialservices\OAuth\StateService::getInstance()->createState($stateFields);

		if ($this->isCloudPortal())
		{
			$portalRedirectUri = new Uri(
				$this->getEntityOAuth()->GetRedirectURI()
			);
			$portalRedirectUri->addParams([
				'state' => $state,
			]);

			$state = (string)$portalRedirectUri;
			$redirect_uri = self::CONTROLLER_URL . '/redirect.php';
		}
		else
		{
			$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();
		}

		return $this->getEntityOAuth()->GetAuthUrl($redirect_uri, $state);
	}

	public function getEntityOAuth($code = false)
	{
		if (!$this->entityOAuth)
		{
			$this->entityOAuth = new CVKontakteOAuthInterface();
		}

		if ($code !== false)
		{
			$this->entityOAuth->setCode($code);
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	public function prepareUser($arVkUser, $short = false)
	{
		$first_name = $last_name = $gender = "";

		if ($arVkUser['response']['0']['first_name'] <> '')
		{
			$first_name = $arVkUser['response']['0']['first_name'];
		}

		if ($arVkUser['response']['0']['last_name'] <> '')
		{
			$last_name = $arVkUser['response']['0']['last_name'];
		}

		if (isset($arVkUser['response']['0']['sex']) && $arVkUser['response']['0']['sex'] != '')
		{
			if ($arVkUser['response']['0']['sex'] == '2')
				$gender = 'M';
			elseif ($arVkUser['response']['0']['sex'] == '1')
				$gender = 'F';
		}

		$arFields = array(
			'EXTERNAL_AUTH_ID' => self::ID,
			'XML_ID' => $arVkUser['response']['0']['id'],
			'LOGIN' => "VKuser" . $arVkUser['response']['0']['id'],
			'EMAIL' => $this->entityOAuth->GetCurrentUserEmail(),
			'NAME' => $first_name,
			'LAST_NAME' => $last_name,
			'PERSONAL_GENDER' => $gender,
			'OATOKEN' => $this->entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $this->entityOAuth->getAccessTokenExpires(),
		);

		if (isset($arVkUser['response']['0']['photo_max_orig']) && self::CheckPhotoURI($arVkUser['response']['0']['photo_max_orig']))
		{
			if (!$short)
			{
				$arPic = CFile::MakeFileArray($arVkUser['response']['0']['photo_max_orig']);
				if ($arPic)
				{
					$arFields["PERSONAL_PHOTO"] = $arPic;
				}
			}

			if (isset($arVkUser['response']['0']['bdate']))
			{
				if ($date = MakeTimeStamp($arVkUser['response']['0']['bdate'], "DD.MM.YYYY"))
				{
					$arFields["PERSONAL_BIRTHDAY"] = ConvertTimeStamp($date);
				}
			}

			$arFields["PERSONAL_WWW"] = self::getProfileUrl($arVkUser['response']['0']['id']);

			if (SITE_ID <> '')
			{
				$arFields["SITE_ID"] = SITE_ID;
			}
		}

		return $arFields;
	}

	public function Authorize()
	{
		$GLOBALS["APPLICATION"]->RestartBuffer();
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
				$redirect_uri = self::CONTROLLER_URL . "/redirect.php";
			else
				$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();

			$this->entityOAuth = $this->getEntityOAuth($_REQUEST['code']);
			if ($this->entityOAuth->GetAccessToken($redirect_uri) !== false)
			{
				$arVkUser = $this->entityOAuth->GetCurrentUser();
				if (is_array($arVkUser) && ($arVkUser['response']['0']['id'] <> ''))
				{
					$arFields = $this->prepareUser($arVkUser);
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

		$url = $this->getRedirectUriAfterAuthorize($bSuccess, self::ID);

		$this->onAfterWebAuth(true, self::OPENER_MODE, $url);
		CMain::FinalActions();
	}

	public function setUser($userId)
	{
		$this->getEntityOAuth()->setUser($userId);
	}

	public function getFriendsList($limit, &$next)
	{
		if ($this->isCloudPortal())
			$redirect_uri = self::CONTROLLER_URL . "/redirect.php";
		else
			$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();

		$vk = $this->getEntityOAuth();
		if ($vk->GetAccessToken($redirect_uri) !== false)
		{
			$res = $vk->getCurrentUserFriends($limit, $next);
			if (is_array($res) && is_array($res['response']))
			{
				foreach ($res['response'] as $key => $contact)
				{
					$res['response'][$key]['name'] = $contact["first_name"];
					$res['response'][$key]['url'] = "https://vk.ru/id" . $contact["id"];
					$res['response'][$key]['picture'] = $contact['photo_200_orig'];
				}

				return $res['response'];
			}
		}

		return false;
	}

	public function sendMessage($uid, $message)
	{
		$vk = $this->getEntityOAuth();

		if ($this->isCloudPortal())
			$redirect_uri = self::CONTROLLER_URL . "/redirect.php";
		else
			$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();

		if ($vk->GetAccessToken($redirect_uri) !== false)
		{
			$res = $vk->sendMessage($uid, $message);
		}

		return $res;
	}

	public function getProfileUrl($uid)
	{
		return "http://vk.ru/id" . $uid;
	}
}

class CVKontakteOAuthInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "VKontakte";

	// https://vk.com/dev/constant_version_updates
	const AUTH_URL = "https://id.vk.ru/authorize";
	const TOKEN_URL = "https://id.vk.ru/oauth2/auth";
	const CONTACTS_URL = "https://api.vk.ru/method/users.get";
	const FRIENDS_URL = "https://api.vk.ru/method/friends.get";
	const MESSAGE_URL = "https://api.vk.ru/method/messages.send";
	const APP_URL = "https://api.vk.ru/method/apps.get";

	// https://dev.vk.com/ru/reference/versions
	const API_VERSION = "5.199";

	protected $userID = false;
	protected $userEmail = false;
	protected $scope = array(
		"friends",
		"offline",
		"email",
	);

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		if ($appID === false)
		{
			$appID = trim(CSocServVKontakte::GetOption("vkontakte_appid"));
		}

		if ($appSecret === false)
		{
			$appSecret = trim(CSocServVKontakte::GetOption("vkontakte_appsecret"));
		}

		parent::__construct($appID, $appSecret, $code);
	}

	private function generateCodeVerifier(): string
	{
		return Random::getString(40);
	}

	private function getCodeVerifier(): string
	{
		if(!isset($_SESSION["CODE_VERIFIER"]))
		{
			$this->setCodeVerifier();
		}

		return $_SESSION["CODE_VERIFIER"];
	}

	private function setCodeVerifier(): void
	{
		$_SESSION["CODE_VERIFIER"] = $this->generateCodeVerifier();
	}

	private function getCodeChallenge(): string
	{
		return str_replace(
			['+', '/', '='],
			['-', '_', ''],
			base64_encode(hash('sha256', $this->getCodeVerifier(), true))
		);
	}


	public function GetRedirectURI()
	{
		return (string)(new Uri("/bitrix/tools/oauth/vkontakte.php"))->toAbsolute();
	}

	public function GetAuthUrl($redirect_uri, $state = '')
	{
		return self::AUTH_URL .
		"?client_id=" . urlencode($this->appID) .
		"&redirect_uri=" . urlencode($redirect_uri) .
		"&scope=" . $this->getScopeEncode() .
		"&response_type=code" .
		"&code_challenge_method=S256" .
		"&code_challenge=" . urlencode($this->getCodeChallenge()) .
		($state <> '' ? '&state=' . urlencode($state) : '');
	}

	public function GetAccessToken($redirect_uri)
	{
		$token = $this->getStorageTokens();
		if (is_array($token))
		{
			$this->access_token = $token["OATOKEN"];

			return true;
		}

		if ($this->code === false)
		{
			$this->logger->error('oauth.token.exchange_failed', [
				'reason' => 'empty_code',
			]);

			return false;
		}

		$query = array(
			'grant_type' => 'authorization_code',
			'code' => $this->code,
			'code_verifier' => $this->getCodeVerifier(),
			'client_id' => $this->appID,
			'device_id' => $_REQUEST["device_id"],
			'redirect_uri' => $redirect_uri,
			'state' => $_REQUEST["state"],
		);

		$h = new \Bitrix\Main\Web\HttpClient(array(
			"socketTimeout" => $this->httpTimeout,
			"streamTimeout" => $this->httpTimeout,
		));

		$result = $h->post(self::TOKEN_URL, $query);

		try
		{
			$arResult = \Bitrix\Main\Web\Json::decode($result);
		} catch (\Bitrix\Main\ArgumentException $e)
		{
			$arResult = array();
		}

		foreach ($arResult as $key => $value)
		{
			if (mb_strpos($key, 'access_token_') === 0)
			{
				$this->access_token = $value;
				$this->userID = null;
				$this->userEmail = null;

				$_SESSION["OAUTH_DATA"] = array("OATOKEN" => $this->access_token);
				return true;
			}
		}

		if ((isset($arResult["access_token"]) && $arResult["access_token"] <> '') && isset($arResult["user_id"]) && $arResult["user_id"] <> '')
		{
			$this->access_token = $arResult["access_token"];
			$this->userID = $arResult["user_id"];
			$this->userEmail = $arResult["email"] ?? null;

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
		if ($this->access_token === false)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'empty_access_token',
			]);

			return false;
		}

		$h = new \Bitrix\Main\Web\HttpClient(array(
			"socketTimeout" => $this->httpTimeout,
			"streamTimeout" => $this->httpTimeout,
		));


		$result = $h->get(self::CONTACTS_URL . '?v='.self::API_VERSION.'&fields=uid,first_name,last_name,nickname,screen_name,sex,bdate,city,country,timezone,photo,photo_medium,photo_max_orig,photo_rec,email&access_token=' . urlencode($this->access_token));

		try
		{
			$result = \Bitrix\Main\Web\Json::decode($result);
		} catch (\Bitrix\Main\ArgumentException $e)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response',
			]);

			$result = array();
		}

		if (!is_array($result))
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response_payload',
				'payload_type' => gettype($result),
			]);
		}

		return $result;
	}

	public function GetAppInfo()
	{
		if ($this->access_token === false)
			return false;

		$h = new \Bitrix\Main\Web\HttpClient();
		$h->setTimeout($this->httpTimeout);

		$result = $h->get(self::APP_URL . '?v='.self::API_VERSION.'&fields=id&access_token=' . urlencode($this->access_token));

		try
		{
			$result = \Bitrix\Main\Web\Json::decode($result);
		} catch (\Bitrix\Main\ArgumentException $e)
		{
			$result = array();
		}

		return $result['response']['items'][0];
	}

	public function GetCurrentUserEmail()
	{
		return $this->userEmail;
	}

	public function GetCurrentUserFriends($limit, &$next)
	{
		if ($this->access_token === false)
		{
			return false;
		}

		$url = self::FRIENDS_URL . '?v='.self::API_VERSION.'&uids=' . $this->userID . '&fields=uid,first_name,last_name,nickname,screen_name,photo_200_orig,contacts,email&access_token=' . urlencode($this->access_token);

		if ($limit > 0)
		{
			$url .= "&count=" . intval($limit) . "&offset=" . intval($next);
		}

		$h = new \Bitrix\Main\Web\HttpClient();
		$h->setTimeout($this->httpTimeout);

		$result = $h->get($url);

		try
		{
			$result = \Bitrix\Main\Web\Json::decode($result);
		} catch (\Bitrix\Main\ArgumentException $e)
		{
			$result = array();
		}

		$next = $limit + $next;

		return $result;
	}

	public function sendMessage($uid, $message)
	{
		if ($this->access_token === false)
		{
			return false;
		}

		$url = self::MESSAGE_URL;

		$arPost = array(
			"user_id" => $uid,
			"access_token" => $this->access_token,
			"message" => $message,
		);

		$ob = new \Bitrix\Main\Web\HttpClient();

		return $ob->post($url, $arPost);
	}
}

?>
