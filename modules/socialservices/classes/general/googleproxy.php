<?php

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Service\MicroService\Client;
use Bitrix\Main\SystemException;
use Bitrix\SocialServices\UserTable;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Main\Web\HttpClient;


class CSocServGoogleProxyOAuth extends CSocServGoogleOAuth
{
	public const PROXY_CONST = 'BITRIX';
	/**
	 * @var \Bitrix\Main\EO_User
	 */
	private $user;

	/**
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function isProxyAuth(): bool
	{
		return !\Bitrix\Main\Loader::includeModule('bitrix24')
			&& (\Bitrix\Main\Config\Option::get('socialservices', 'google_sync_proxy', 'N') === 'Y');
	}

	public function Authorize()
	{
		global $APPLICATION;
		$APPLICATION->RestartBuffer();

		$bSuccess = false;

		$authError = SOCSERV_AUTHORISATION_ERROR;
		$this->logger->info('oauth.auth.start');

		$state = $this->parseState($_REQUEST['state'] ?? '') ?? [];

		if (empty($_REQUEST['code']))
		{
			$this->logger->error('oauth.request.invalid_code');
			$this->sendOauthError(OAuthErrorCode::MissingCode);
		}
		elseif (!$this->checkUserToken($state['user_token'] ?? null))
		{
			$this->logger->error('oauth.request.invalid_check_key', [
				'reason' => 'user_token_validation_failed',
			]);
			$this->sendOauthError(OAuthErrorCode::InvalidCheckKey);
		}
		else
		{
			$this->getEntityOAuth()->setCode($_REQUEST["code"]);

			if($this->getEntityOAuth()->GetAccessToken() !== false)
			{
				$arGoogleUser = $this->getEntityOAuth()->GetCurrentUser();

				if(is_array($arGoogleUser) && !isset($arGoogleUser["error"]))
				{
					$arFields = $this->prepareUser($arGoogleUser);
					$arFields['USER_ID'] = $this->user->getId();
					$authError = $this->AuthorizeUser($arFields);
				}
				elseif (isset($arGoogleUser["error"]))
				{
					$this->logger->error('oauth.user.fetch_failed', [
						'reason' => 'provider_error',
					]);
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

		$this->logger->info('oauth.auth.finish', [
			'success' => ($authError === true),
			'auth_result' => $authError,
		]);

		$mode = $state['mode'] ?? 'opener';

		if($this->user && ($authError === true))
		{
			$bSuccess = true;
			CSocServUtil::checkOAuthProxyParams();
		}
		$url = $this->getRedirectUriAfterAuthorize($authError, static::ID);
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

	public function getUrl($location = 'opener', $addScope = null, $arParams = array())
	{
		if(IsModuleInstalled('bitrix24') && defined('BX24_HOST_NAME'))
		{
			return '';
		}

		$this->entityOAuth = $this->getEntityOAuth();

		if($this->userId === null)
		{
			$this->entityOAuth->setRefreshToken("skip");
		}

		if($addScope !== null)
		{
			$this->entityOAuth->addScope($addScope);
		}

		$defaultReturnUrl = $GLOBALS["APPLICATION"]->GetCurPageParam(
			'',
			["logout", "auth_service_error", "auth_service_id", "backurl", 'serviceName', 'hitHash']
		);
		$state = \Bitrix\Socialservices\OAuth\StateService::getInstance()->createState(
			payload: [
				'provider' => static::ID,
				'site_id' => SITE_ID,
				'check_key' => \CSocServAuthManager::getUniqueKey(),
				'redirect_url' => isset($arParams['BACKURL']) ? $arParams['BACKURL'] : $defaultReturnUrl,
				'mode' => $location,
				'user_token' => $this->generateUserToken(),
			],
			additionalInfo: [
				'hostUrl' => \Bitrix\Main\Engine\UrlManager::getInstance()->getHostUrl(),
				'mode' => $location,
			],
		);

		$redirect_uri = $this->getEntityOAuth()->getRedirectUri();

		return $this->entityOAuth->GetAuthUrl($redirect_uri, $state, $arParams['APIKEY']);
	}

	/**
	 * @param string $code=false
	 * @return CGoogleOAuthInterface
	 */
	public function getEntityOAuth($code = false)
	{
		if(!($this->entityOAuth instanceof CGoogleProxyOAuthInterface))
		{
			$this->entityOAuth = new CGoogleProxyOAuthInterface();
		}

		if($code !== false)
		{
			$this->entityOAuth->setCode($code);
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	/**
	 * @return string
	 * @throws SystemException
	 * @throws \Bitrix\Main\Security\SecurityException
	 */
	private function generateUserToken(): string
	{
		$configuration = Configuration::getInstance();
		$cipherKey = $configuration->get('crypto')['crypto_key'] ?? null;
		if (!$cipherKey)
		{
			throw new SystemException('There is no crypto[crypto_key] in .settings.php. Generate it.');
		}

		$cipher = new Cipher();

		return base64_encode($cipher->encrypt(time() . '_'. $this->userId .'_' . self::PROXY_CONST, $cipherKey));
	}

	/**
	 * @param string|null $userToken
	 * @return bool
	 * @throws SystemException
	 * @throws \Bitrix\Main\Security\SecurityException
	 */
	private function checkUserToken(string $userToken = null): bool
	{
		if (!$userToken)
		{
			return false;
		}

		$configuration = Configuration::getInstance();
		$cipherKey = $configuration->get('crypto')['crypto_key'] ?? null;
		if (!$cipherKey)
		{
			throw new SystemException('There is no crypto[crypto_key] in .settings.php. Generate it.');
		}

		$cipher = new Cipher();
		$data = explode('_', $cipher->decrypt(base64_decode($userToken), $cipherKey));
		if (
			empty($data[1])
			|| (($data[0] + 3600) < time())
			|| $data[2] !== self::PROXY_CONST
		)
		{
			return false;
		}

		$user = \Bitrix\Main\UserTable::query()
			->where('ID', (int)$data[1])
			->setSelect(['*'])
			->exec()
			->fetchObject()
		;

		if (!$user)
		{
			return false;
		}

		$this->user = $user;
		$this->userId = $data[1];

		return true;
	}

	private function parseState(string $requestState = null): ?array
	{
		return $this->getState($requestState);
	}

	public function AuthorizeUser($socservUserFields, $bSave = false)
	{
		if(!isset($socservUserFields['XML_ID']) || $socservUserFields['XML_ID'] == '')
		{
			return false;
		}

		if(!isset($socservUserFields['EXTERNAL_AUTH_ID']) || $socservUserFields['EXTERNAL_AUTH_ID'] == '')
		{
			return false;
		}

		if (!empty($socservUserFields['USER_ID']))
		{
			$this->deleteOldTokens($socservUserFields['USER_ID'], $socservUserFields['EXTERNAL_AUTH_ID']);

			$dbSocUser = UserTable::getList(
				[
					'filter' => [
						'=XML_ID' => $socservUserFields['XML_ID'],
						'=EXTERNAL_AUTH_ID' => $socservUserFields['EXTERNAL_AUTH_ID']
					],
					'select' => ['ID'],
				]
			);

			$storedUser = $dbSocUser->fetch();

			if (!$storedUser)
			{
				$result = UserTable::add(UserTable::filterFields($socservUserFields));
			}
			else
			{
				$result = UserTable::update($storedUser['ID'], UserTable::filterFields($socservUserFields));
			}
		}
		else
		{
			return false;
		}

		return $result->isSuccess();
	}

	/**
	 * @throws SystemException
	 * @throws \Exception
	 */
	private function deleteOldTokens($userId, $externalAuthId): void
	{
		$dbTokens = \Bitrix\Socialservices\UserTable::getList(
			[
			'filter' => [
				'=USER_ID' => $userId,
				'=EXTERNAL_AUTH_ID' => $externalAuthId
			],
			'select' => ['ID']
		]);

		while ($accessToken = $dbTokens->fetch())
		{
			UserTable::delete($accessToken['ID']);
		}
	}
}

class CGoogleProxyOAuthInterface extends CGoogleOAuthInterface
{
	public const TOKEN_URL = "https://calendar-proxy-ru-01.bitrix24.com";

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		parent::__construct($this->getAppId(), null, $code);
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
				if ($this->checkAccessToken())
				{
					return true;
				}

				if(
					isset($tokens["REFRESH_TOKEN"])
					&& $this->getNewAccessToken(
						$tokens["REFRESH_TOKEN"],
						$this->userId,
						true
					)
				)
				{
					return true;
				}
			}

			$this->deleteStorageTokens();
		}

		if($this->code === false)
		{
			return false;
		}

		$http = new HttpClient([
			"socketTimeout" => $this->httpTimeout
		]);

		$params = array_merge(
			$this->getLicenseParams(),
			[
				"client_id" => $this->appID,
				"code" => $this->code,
				"redirect_uri" => $this->getRedirectUri(),
				"grant_type" => "authorization_code",
			]
		);

		try
		{
			$result = \Bitrix\Main\Web\Json::decode($http->post(static::TOKEN_URL, $params));
			if (isset($result['APP_ID'], $result['API_KEY']))
			{
				$params['client_id'] = $result['APP_ID'];
				$this->appID = $result['APP_ID'];
				CSocServGoogleOAuth::SetOption("google_proxy_appid", trim($result['APP_ID']));
				CSocServGoogleOAuth::SetOption("google_proxy_api_key", trim($result['API_KEY']));

				$result = \Bitrix\Main\Web\Json::decode($http->post(static::TOKEN_URL, $params));
			}

			$this->arResult = $result;
		}
		catch(\Bitrix\Main\ArgumentException $e)
		{
			$this->arResult = [];
		}

		if(isset($this->arResult["access_token"]) && $this->arResult["access_token"] <> '')
		{
			if(isset($this->arResult["refresh_token"]) && $this->arResult["refresh_token"] <> '')
			{
				$this->refresh_token = $this->arResult["refresh_token"];
			}
			$this->access_token = $this->arResult["access_token"];
			$this->accessTokenExpires = $this->arResult["expires_in"] + time();

			$_SESSION["OAUTH_DATA"] = [
				"OATOKEN" => $this->access_token,
				"OATOKEN_EXPIRES" => $this->accessTokenExpires,
				"REFRESH_TOKEN" => $this->refresh_token,
			];

			return true;
		}

		return false;
	}

	public function getNewAccessToken($refreshToken = false, $userId = 0, $save = false)
	{
		if($this->appID === false)
		{
			return false;
		}

		if($refreshToken === false)
		{
			$refreshToken = $this->refresh_token;
		}


		$params = array_merge(
			$this->getLicenseParams(),
			[
				"client_id" => $this->appID,
				"refresh_token"=>$refreshToken,
				"grant_type"=>"refresh_token",
			]
		);

		$http = new HttpClient(
			array("socketTimeout" => $this->httpTimeout)
		);

		$result = $http->post(static::TOKEN_URL, $params);

		try
		{
			$this->arResult = \Bitrix\Main\Web\Json::decode($result);
		}
		catch(\Bitrix\Main\ArgumentException $e)
		{
			$this->arResult = [];
		}

		if (isset($this->arResult["access_token"]) && $this->arResult["access_token"] <> '')
		{
			$this->access_token = $this->arResult["access_token"];
			$this->accessTokenExpires = $this->arResult["expires_in"] + time();
			if ($save && intval($userId) > 0)
			{
				$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
					'filter' => [
						'=EXTERNAL_AUTH_ID' => static::SERVICE_ID,
						'=USER_ID' => $userId,
					],
					'select' => ["ID"]
				]);
				if($arOauth = $dbSocservUser->Fetch())
				{
					\Bitrix\Socialservices\UserTable::update($arOauth["ID"], [
							"OATOKEN" => $this->access_token,
							"OATOKEN_EXPIRES" => $this->accessTokenExpires
						]
					);
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * @return string
	 */
	public function getAppId(): string
	{
		if ($appId = trim(CSocServGoogleOAuth::GetOption("google_proxy_appid")))
		{
			return $appId;
		}

		$http = new HttpClient(["socketTimeout" => $this->httpTimeout]);

		$result = $http->post(static::TOKEN_URL, $this->getLicenseParams());

		try
		{
			$proxyData = \Bitrix\Main\Web\Json::decode($result);
			CSocServGoogleOAuth::SetOption("google_proxy_appid", trim($proxyData['APP_ID']));
			CSocServGoogleOAuth::SetOption("google_proxy_api_key", trim($proxyData['API_KEY']));

			return $proxyData['APP_ID'];
		}
		catch(\Bitrix\Main\ArgumentException $e)
		{
		}

		return '';
	}

	/**
	 * @return string
	 */
	public function getRedirectUri(): string
	{
		return static::TOKEN_URL;
	}

	/**
	 * @return array
	 */
	public function getLicenseParams(): array
	{
		$params["BX_TYPE"] = Client::getPortalType();
		$params["BX_LICENCE"] = Client::getLicenseCode();
		$params["SERVER_NAME"] = Client::getServerName();
		$params["license_key"] = Client::signRequest($params);

		return $params;
	}
}
