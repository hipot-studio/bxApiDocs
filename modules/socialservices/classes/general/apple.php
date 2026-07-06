<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\JWK;
use Bitrix\Main\Web\JWT;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Socialservices\OAuth\StateService;

IncludeModuleLangFile(__FILE__);

class CSocServApple extends CSocServAuth
{
	public const ID = 'apple';
	public const LOGIN_PREFIX = 'apple_';
	private const CONTROLLER_URL = 'https://www.bitrix24.ru/controller';

	protected $entityOAuth;

	public function GetSettings()
	{
		return [
			['apple_client_id', Loc::getMessage('SOCSERV_APPLE_ID'), '', ['text', 40]],
			['apple_key_id', Loc::getMessage('SOCSERV_APPLE_KEY_ID'), '', ['text', 40]],
			['apple_team_id', Loc::getMessage('SOCSERV_APPLE_TEAM_ID'), '', ['text', 40]],
			['apple_key_pem', Loc::getMessage('SOCSERV_APPLE_KEY_PEM'), '', ['textarea', 10, 40]],
			[
				'note' => getMessage('SOCSERV_APPLE_SETT_NOTE_2'),
			],
		];
	}

	/**
	 * @param string|bool $code = false
	 * @return CAppleInterface
	 */
	public function getEntityOAuth($code = false): CAppleInterface
	{
		if (!$this->entityOAuth)
		{
			$this->entityOAuth = new CAppleInterface();
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
		$phrase = ($arParams['FOR_INTRANET'])
			? GetMessage('SOCSERV_APPLE_NOTE_INTRANET')
			: GetMessage('SOCSERV_APPLE_NOTE');

		return $arParams['FOR_INTRANET']
			? array('ON_CLICK' => 'onclick="'.\CSocServApple::GetOnClickJs($arParams).'"')
			: '<a href="javascript:void(0)" onclick="'.\CSocServApple::GetOnClickJs($arParams).'" class="bx-ss-button apple-button"></a><span class="bx-spacer"></span><span>' . $phrase . '</span>';
	}

	public function GetOnClickJs($arParams): string
	{
		$url = $this->getUrl($arParams);
		$controllerUrl = \Bitrix\Main\Engine\UrlManager::getInstance()->create('socialservices.authflow.signinapple', [])->getUri();

		return "top.location.href = '{$controllerUrl}&url=" . urlencode(CUtil::JSEscape($url)) . "'";
	}

	public function getUrl($arParams): string
	{
		$stateFields = [
			'site_id' => SITE_ID,
			'check_key' => \CSocServAuthManager::getUniqueKey(),
			'redirect_url' => $this->getRedirectUrl($arParams),
		];
		$state = StateService::getInstance()->createState($stateFields);

		if ($this->isCloudPortal())
		{
			$portalRedirectUri = new Uri(
				$this->getEntityOAuth()->GetRedirectURI()
			);
			$portalRedirectUri->addParams([
				'state' => $state,
			]);

			$state = (string)$portalRedirectUri;
			$redirect_uri = static::CONTROLLER_URL . '/redirect.php';
		}
		else
		{
			$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();
		}

		return $this->getEntityOAuth()->GetAuthUrl($redirect_uri, $state);
	}

	public function addScope($scope): CAppleInterface
	{
		return $this->getEntityOAuth()->addScope($scope);
	}

	public function prepareUser($arUser): array
	{
		$entityOAuth = $this->getEntityOAuth();
		$arFields = [
			'EXTERNAL_AUTH_ID' => self::ID,
			'XML_ID' => $arUser['sub'],
			'LOGIN' => self::LOGIN_PREFIX.$arUser['sub'],
			'EMAIL' => $arUser['email'],
			'OATOKEN' => $entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $entityOAuth->getAccessTokenExpires(),
		];

		if (isset($arUser['first_name']))
		{
			$arFields['NAME'] =  $arUser['first_name'];
		}

		if (isset($arUser['last_name']))
		{
			$arFields['LAST_NAME'] =  $arUser['last_name'];
		}

		if (SITE_ID != '')
		{
			$arFields['SITE_ID'] = SITE_ID;
		}

		return $arFields;
	}

	public static function CheckUniqueKey($bUnset = true): bool
	{
		$arState = [];

		if (isset($_REQUEST['state']))
		{
			$arState = StateService::getInstance()->getPayload((string)$_REQUEST['state']) ?? [];

			if (isset($arState['redirect_url']))
			{
				InitURLParam($arState['redirect_url']);
			}
		}

		if (!isset($_REQUEST['check_key']) && isset($_REQUEST['backurl']))
		{
			InitURLParam($_REQUEST['backurl']);
		}

		$checkKey = '';
		if (isset($_REQUEST['check_key']))
		{
			$checkKey = $_REQUEST['check_key'];
		}
		elseif (isset($arState['check_key']))
		{
			$checkKey = $arState['check_key'];
		}

		if ($_SESSION['UNIQUE_KEY'] !== '' && $checkKey !== '' && ($checkKey === $_SESSION['UNIQUE_KEY']))
		{
			if ($bUnset)
			{
				unset($_SESSION['UNIQUE_KEY']);
			}

			return true;
		}
		return false;
	}

	public function Authorize(): void
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
		elseif (self::CheckUniqueKey())
		{
			if ($this->isCloudPortal())
			{
				$redirect_uri = static::CONTROLLER_URL . '/redirect.php';
			}
			else
			{
				$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();
			}

			$entityOAuth = $this->getEntityOAuth($_REQUEST['code']);
			if ($entityOAuth->GetAccessToken($redirect_uri) !== false)
			{
				$arUser = $entityOAuth->getCurrentUser();
				if (is_array($arUser) && isset($arUser["email"]))
				{
					$arFields = $this->prepareUser($arUser);
					$authError = $this->AuthorizeUser($arFields);
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
		LocalRedirect($url);
	}

	public function setUser($userId)
	{
		$this->getEntityOAuth()->setUser($userId);
	}
}

class CAppleInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "apple";

	const AUTH_URL = 'https://appleid.apple.com/auth/authorize';
	const TOKEN_URL = 'https://appleid.apple.com/auth/token';
	private const PUBLIC_KEYS_URL = 'https://appleid.apple.com/auth/keys';

	private const CLIENT_SECRET_EXPIRATION_TIME = 3600;
	private const DECODE_ALGORITHM = 'RS256';
	private const BITRIX_APP_BUNDLE_ID = 'com.bitrixsoft.cpmobile';

	protected $userId = false;
	protected $responseData = array();
	protected $idToken;

	protected $scope = [
		'name', 'email'
	];

	private $keyId;
	private $teamId;
	private $secretKey;

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		if ($appID === false)
		{
			$appID = trim(\CSocServAuth::GetOption("apple_client_id"));
		}

		$this->keyId = trim(\CSocServAuth::GetOption('apple_key_id'));
		$this->teamId = trim(\CSocServAuth::GetOption('apple_team_id'));
		$this->secretKey = trim(\CSocServAuth::GetOption('apple_key_pem'));

		parent::__construct($appID, $appSecret, $code);
	}

	public function GetRedirectURI(): string
	{
		return (string)(new Uri('/bitrix/tools/oauth/apple.php'))->toAbsolute();
	}

	public function GetAuthUrl($redirect_uri, $state = ''): string
	{
		return self::AUTH_URL .
			'?client_id=' . $this->appID .
			'&redirect_uri=' . urlencode($redirect_uri) .
			'&response_type=' . 'code' .
			'&scope=' . $this->getScopeEncode() .
			'&response_mode=' . 'form_post' .
			($state <> '' ? '&state=' . urlencode($state) : '');
	}

	public function getResult()
	{
		return $this->responseData;
	}

	public function GetAccessToken($redirect_uri = ''): bool
	{
		$token = $this->getStorageTokens();
		if (is_array($token))
		{
			$this->access_token = $token['OATOKEN'];
			$this->accessTokenExpires = $token['OATOKEN_EXPIRES'];

			if (!$this->code)
			{
				if ($this->checkAccessToken())
				{
					return true;
				}

				if (isset($token['REFRESH_TOKEN']) && $this->getNewAccessToken($token['REFRESH_TOKEN'], $this->userId, true))
				{
					return true;
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

		//case for sign in from Bitrix24 application on iOS device
		$request = \Bitrix\Main\Context::getCurrent()->getRequest();
		$requestData = $request->toArray();
		if (
			$requestData['service'] === self::SERVICE_ID
			&& (
				$requestData['platform'] === 'ios'
				|| (empty($requestData['platform']) && mb_strpos($request->getUserAgent(), 'Darwin') !== false)
			)
		)
		{
			$this->appID = self::BITRIX_APP_BUNDLE_ID;
		}

		$query = [
			'code' => $this->code,
			'grant_type' => 'authorization_code',
			'client_secret' => $this->getClientSecret(),
			'client_id' => $this->appID,
			'redirect_uri' => $redirect_uri,
		];

		$httpClient = new HttpClient([
			'socketTimeout' => $this->httpTimeout,
			'streamTimeout' => $this->httpTimeout,
		]);

		$result = $httpClient->post(self::TOKEN_URL, $query);
		try
		{
			$result = \Bitrix\Main\Web\Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$result = [];
		}

		if ((isset($result['access_token']) && $result['access_token'] <> ''))
		{
			$this->access_token = $result['access_token'];
			$this->accessTokenExpires = time() + $result['expires_in'];
			$this->refresh_token = $result['refresh_token'];
			$this->idToken = $result['id_token'];

			$_SESSION["OAUTH_DATA"] = [
				"OATOKEN" => $this->access_token,
				"OATOKEN_EXPIRES" => $this->accessTokenExpires,
				"REFRESH_TOKEN" => $this->refresh_token,
				"ID_TOKEN" => $this->idToken
			];
			return true;
		}

		$this->logger->error('oauth.token.exchange_failed', [
			'reason' => 'token_not_found_in_response',
		]);

		return false;
	}

	public function getNewAccessToken($refreshToken = false, $userId = 0, $save = false): bool
	{
		if (!$this->appID || !$this->appSecret)
		{
			return false;
		}

		if (!$refreshToken)
		{
			$refreshToken = $this->refresh_token;
		}

		$http = new HttpClient(array(
			'socketTimeout' => $this->httpTimeout,
			'streamTimeout' => $this->httpTimeout,
		));

		$result = $http->post(static::TOKEN_URL, array(
			'refresh_token' => $refreshToken,
			'client_id' => $this->appID,
			'client_secret' => $this->getClientSecret(),
			'grant_type' => 'authorization_code',
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
			if ($save && (int)$userId > 0)
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

	public function getCurrentUser()
	{
		if ($this->access_token === false || $this->idToken === false)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'empty_access_token',
			]);

			return false;
		}

		try
		{
			$user = $this->decodeIdentityToken($this->idToken);
		}
		catch (Exception $exception)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_identity_token',
			]);

			return false;
		}

		$user = (array)$user;

		if (!empty($user['sub']) && isset($_REQUEST['user']))
		{
			$userData = json_decode($_REQUEST['user'], true);

			if (!empty($userData))
			{
				$user['first_name'] = $userData['name']['firstName'];
				$user['last_name'] = $userData['name']['lastName'];
			}
		}

		if (empty($user['sub']))
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'missing_user_id',
			]);
		}

		return $user;
	}

	public function GetAppInfo(): array
	{
		$app = [
			'id' => self::BITRIX_APP_BUNDLE_ID
		];

		return $app;
	}

	public function getScopeEncode(): string
	{
		return implode(' ', array_map('urlencode', array_unique($this->getScope())));
	}

	private function getClientSecret(): string
	{
		return $this->generateSignedJWT($this->keyId, $this->teamId, $this->appID, $this->secretKey);
	}

	private function generateSignedJWT(string $keyId, string $teamId, string $clientId, string $secretKey)
	{
		$header = [
			'alg' => 'ES256',
			'kid' => $keyId
		];
		$body = array(
			'iss' => $teamId,
			'iat' => time(),
			'exp' => time() + self::CLIENT_SECRET_EXPIRATION_TIME,
			'aud' => 'https://appleid.apple.com',
			'sub' => $clientId
		);

		$privateKey = openssl_pkey_get_private($secretKey);
		if (!$privateKey)
		{
			return false;
		}

		$payload = JWT::urlsafeB64Encode(json_encode($header)) . '.' . JWT::urlsafeB64Encode(json_encode($body));
		$signature = '';
		$signResult = openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
		if (!$signResult)
		{
			return false;
		}

		$rawSignature = self::convertDERSignature($signature, 64);

		return $payload . '.' . JWT::urlsafeB64Encode($rawSignature);
	}

	private static function convertDERSignature(string $der, int $partLength): string
	{
		$hex = unpack('H*', $der)[1];
		if ('30' !== substr($hex, 0, 2))
		{ // SEQUENCE
			throw new \RuntimeException();
		}
		if ('81' === substr($hex, 2, 2))
		{ // LENGTH > 128
			$hex = substr($hex, 6);
		}
		else
		{
			$hex = substr($hex, 4);
		}
		if ('02' !== substr($hex, 0, 2))
		{ // INTEGER
			throw new \RuntimeException();
		}
		$Rl = hexdec(substr($hex, 2, 2));
		$R = self::retrievePositiveInteger(substr($hex, 4, $Rl * 2));
		$R = str_pad($R, $partLength, '0', STR_PAD_LEFT);
		$hex = substr($hex, 4 + $Rl * 2);
		if ('02' !== substr($hex, 0, 2))
		{ // INTEGER
			throw new \RuntimeException();
		}
		$Sl = hexdec(substr($hex, 2, 2));
		$S = self::retrievePositiveInteger(substr($hex, 4, $Sl * 2));
		$S = str_pad($S, $partLength, '0', STR_PAD_LEFT);

		return pack('H*', $R . $S);
	}

	/**
	 * @param string $data
	 *
	 * @return string
	 */
	private static function retrievePositiveInteger(string $data): string
	{
		while ('00' === substr($data, 0, 2) && substr($data, 2, 2) > '7f')
		{
			$data = substr($data, 2);
		}
		return $data;
	}

	private function fetchPublicKey()
	{
		$publicKeyDetails = [];

		$http = new HttpClient([
			'socketTimeout' => $this->httpTimeout,
			'streamTimeout' => $this->httpTimeout,
		]);
		$publicKeys = $http->get(self::PUBLIC_KEYS_URL);

		try
		{
			$decodedPublicKeys = json_decode($publicKeys, true);
		}
		catch (Exception $e)
		{
			return false;
		}

		if (!isset($decodedPublicKeys['keys']) || count($decodedPublicKeys['keys']) < 1)
		{
			return false;
		}

		$parsedPublicKeys = JWK::parseKeySet($decodedPublicKeys['keys']);

		foreach ($parsedPublicKeys as $keyId => $publicKey)
		{
			$details = openssl_pkey_get_details($publicKey);
			$publicKeyDetails[$keyId] = $details['key'];
		}

		return $publicKeyDetails;
	}

	private function decodeIdentityToken(string $identityToken)
	{
		$payload = '';

		$publicKeys = $this->fetchPublicKey();
		if (is_array($publicKeys))
		{
			$payload = JWT::decode($identityToken, $publicKeys, [self::DECODE_ALGORITHM]);
		}

		return $payload;
	}

}
