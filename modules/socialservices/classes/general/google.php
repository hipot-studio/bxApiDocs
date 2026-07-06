<?php

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\JWK;
use Bitrix\Main\Web\JWT;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;

IncludeModuleLangFile(__FILE__);

class CSocServGoogleOAuth extends CSocServAuth
{
	public const ID = "GoogleOAuth";
	const LOGIN_PREFIX = "G_";

	/** @var CGoogleOAuthInterface null  */
	protected $entityOAuth = null;

	/**
	 * @param string $code=false
	 * @return CGoogleOAuthInterface
	 */
	public function getEntityOAuth($code = false)
	{
		if(!$this->entityOAuth)
		{
			$this->entityOAuth = new CGoogleOAuthInterface();
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
		return [
			["google_appid", GetMessage("socserv_google_client_id"), "", ["text", 40]],
			["google_appsecret", GetMessage("socserv_google_client_secret"), "", ["text", 40]],
			['google_app_api_key', GetMessage('socserv_google_api_key'), '', ['text', 40]],
			[
				'note' => getMessage(
					'socserv_google_note_2_MSGVER_1',
					[
						'#URL#' => $this->getEntityOAuth()->getRedirectUri(),
						'#MAIL_URL#' => (string)(new Uri('/bitrix/tools/mail_oauth.php'))->toAbsolute(),
					]
				),
			],
		];
	}

	public function CheckSettings()
	{
		return self::GetOption('google_appid') !== ''
			&& self::GetOption('google_appsecret') !== '';
	}


	public function GetFormHtml($arParams)
	{
		$url = static::getUrl('opener', null, $arParams);

		$isForIntranet = $params['FOR_INTRANET'] ?? false;
		if ($isForIntranet)
		{
			return array("ON_CLICK" => 'onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)"');
		}

		$phrase = $isForIntranet ? GetMessage("socserv_google_form_note_intranet") : GetMessage("socserv_google_form_note");

		return '<a href="javascript:void(0)" onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)" class="bx-ss-button google-button"></a><span class="bx-spacer"></span><span>'.$phrase.'</span>';
	}

	public function GetOnClickJs($arParams)
	{
		$url = static::getUrl('opener', null, $arParams);
		return "BX.util.popup('".CUtil::JSEscape($url)."', 680, 800)";
	}

	public function getUrl($location = 'opener', $addScope = null, $arParams = array())
	{
		$this->entityOAuth = $this->getEntityOAuth();

		if ($this->userId === null)
		{
			$this->entityOAuth->setRefreshToken("skip");
		}

		if ($addScope !== null)
		{
			$this->entityOAuth->addScope($addScope);
		}

		$stateFields = [
			'provider' => static::ID,
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
			$redirect_uri = static::getControllerUrl() . '/redirect.php';
		}
		else
		{
			$redirect_uri = $this->getEntityOAuth()->getRedirectUri();
		}

		return $this->entityOAuth->GetAuthUrl($redirect_uri, $state, $arParams['APIKEY'] ?? '');
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

				if(empty($accessToken) || ((intval($arOauth["OATOKEN_EXPIRES"]) > 0) && (intval($arOauth["OATOKEN_EXPIRES"] < intval(time())))))
				{
					if(isset($arOauth['REFRESH_TOKEN']))
					{
						$this->getEntityOAuth()->getNewAccessToken($arOauth['REFRESH_TOKEN'], $userId, true);
					}

					if(($accessToken = $this->getEntityOAuth()->getToken()) === false)
					{
						return null;
					}
				}
			}
		}

		return $accessToken;
	}

	public function prepareUser($arGoogleUser, $short = false)
	{
		$first_name = "";
		$last_name = "";
		if(is_array($arGoogleUser['name']))
		{
			$first_name = $arGoogleUser['name']['givenName'];
			$last_name = $arGoogleUser['name']['familyName'];
		}
		elseif(!empty($arGoogleUser['name']))
		{
			$aName = explode(" ", $arGoogleUser['name']);
			if(!empty($arGoogleUser['given_name']))
			{
				$first_name = $arGoogleUser['given_name'];
			}
			else
			{
				$first_name = $aName[0];
			}

			if(!empty($arGoogleUser['family_name']))
			{
				$last_name = $arGoogleUser['family_name'];
			}
			elseif(!empty($aName[1]))
			{
				$last_name = $aName[1];
			}
		}

		$id = $arGoogleUser['id'] ?? $arGoogleUser['sub'];
		$email = $arGoogleUser['email'];

		if(!empty($arGoogleUser['email']))
		{
			$dbRes = \Bitrix\Main\UserTable::getList(array(
				'filter' => array(
					'=EXTERNAL_AUTH_ID' => 'socservices',
					'=XML_ID' => $email,
				),
				'select' => array('ID'),
				'limit' => 1
			));
			if($dbRes->fetch())
			{
				$id = $email;
			}
		}

		$arFields = [
			'EXTERNAL_AUTH_ID' => static::ID,
			'XML_ID' => $id,
			'LOGIN' => static::LOGIN_PREFIX.$id,
			'EMAIL' => $email,
			'NAME'=> $first_name,
			'LAST_NAME'=> $last_name,
			'OATOKEN' => $this->entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $this->entityOAuth->getAccessTokenExpires(),
			'REFRESH_TOKEN' => $this->entityOAuth->getRefreshToken(),
		];

		if(!empty($arGoogleUser['gender']))
		{
			if($arGoogleUser['gender'] === 'male')
			{
				$arFields["PERSONAL_GENDER"] = 'M';
			}
			elseif($arGoogleUser['gender'] === 'female')
			{
				$arFields["PERSONAL_GENDER"] = 'F';
			}
		}

		if(!$short && isset($arGoogleUser['picture']) && $this->CheckPhotoURI($arGoogleUser['picture']))
		{
			$arGoogleUser['picture'] = preg_replace("/\?.*$/", '', $arGoogleUser['picture']);
			$arPic = false;
			if ($arGoogleUser['picture'])
			{
				$temp_path =  CFile::GetTempName('', sha1($arGoogleUser['picture']));

				$http = new HttpClient();
				$http->setPrivateIp(false);
				if($http->download($arGoogleUser['picture'], $temp_path))
				{
					$arPic = CFile::MakeFileArray($temp_path);
				}
			}

			if($arPic)
			{
				$arFields["PERSONAL_PHOTO"] = $arPic;
			}
		}

		$arFields["PERSONAL_WWW"] = $arGoogleUser['link'] ?? $arGoogleUser['url'];

		if(!empty(SITE_ID))
		{
			$arFields["SITE_ID"] = SITE_ID;
		}

		return $arFields;
	}

	public function Authorize()
	{
		global $APPLICATION;
		$APPLICATION->RestartBuffer();

		$bSuccess = false;
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
			$this->getEntityOAuth()->setCode($_REQUEST["code"]);

			$bProcessState = true;

			if($this->getEntityOAuth()->GetAccessToken() !== false)
			{
				$arGoogleUser = $this->getEntityOAuth()->GetCurrentUser();

				if(is_array($arGoogleUser) && !isset($arGoogleUser["error"]))
				{
					$arFields = $this->prepareUser($arGoogleUser);
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

		$bSuccess = $authError === true;
		$arState = $this->getState();
		$mode = $arState['mode'] ?? 'opener';
		$url = $this->getRedirectUriAfterAuthorize($authError, static::ID);
		$addParams = !str_starts_with($url, '#');

		if($bSuccess)
		{
			CSocServUtil::checkOAuthProxyParams();
		}

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

	public function setUser($userId)
	{
		$this->getEntityOAuth()->setUser($userId);
	}

	public function getFriendsList($limit, &$next)
	{
		$res = [];

		if($this->getEntityOAuth()->GetAccessToken() !== false)
		{
			$res = $this->getEntityOAuth()->getCurrentUserFriends($limit, $next);

			foreach($res as $key => $contact)
			{
				$contact['uid'] = $contact['email'];

				$arName = $contact['name'];

				$contact['first_name'] = trim($arName['givenName']);
				$contact['last_name'] = trim($arName['familyName']);
				$contact['second_name'] = trim($arName['additionalName']);

				if(!$contact['first_name'] && !$contact['last_name'])
				{
					$contact['first_name'] = $contact['uid'];
				}

				$res[$key] = $contact;
			}
		}

		return $res;
	}
}

class CGoogleOAuthInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "GoogleOAuth";

	public const CERTS_URL = "https://www.googleapis.com/oauth2/v3/certs";
	public const JWT_ALG = ["RS256"];

	const AUTH_URL = "https://accounts.google.com/o/oauth2/auth";
	const TOKEN_URL = "https://accounts.google.com/o/oauth2/token";
	const CONTACTS_URL = "https://www.googleapis.com/oauth2/v1/userinfo";
	const FRIENDS_URL = "https://www.google.com/m8/feeds/contacts/default/full";
	const TOKENINFO_URL = "https://www.googleapis.com/oauth2/v2/tokeninfo";

	const REDIRECT_URI = "/bitrix/tools/oauth/google.php";

	protected $standardScope = array(
		'https://www.googleapis.com/auth/userinfo.email',
		'https://www.googleapis.com/auth/userinfo.profile',
	);

	protected $scope = array();

	protected $arResult = array();

	protected ?string $idTokenAuth = null;
	protected ?array $fetchedPublicKeys = null;

	public function __construct($appID = false, $appSecret = false, $code = false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServGoogleOAuth::GetOption("google_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServGoogleOAuth::GetOption("google_appsecret"));
		}

		$this->scope = $this->standardScope;

		$this->checkSavedScope();

		parent::__construct($appID, $appSecret, $code);
	}

	protected function getOptionNameForScopes(): string
	{
		return 'saved_scope_'.static::SERVICE_ID;
	}

	protected function checkSavedScope(): void
	{
		$savedScope = \Bitrix\Main\Config\Option::get('socialservices', $this->getOptionNameForScopes(), '');
		if($savedScope)
		{
			$savedScope = unserialize($savedScope, ['allowed_classes' => false]);
			if(is_array($savedScope))
			{
				$this->scope = array_merge($this->scope, $savedScope);
			}
		}
	}

	protected function saveScope(): void
	{
		$scope = array_unique(array_diff($this->scope, $this->standardScope));
		\Bitrix\Main\Config\Option::set('socialservices', $this->getOptionNameForScopes(), serialize($scope));
	}

	public function addScope($scope)
	{
		parent::addScope($scope);

		$this->saveScope();

		return $this;
	}

	public function removeScope(string $scope): void
	{
		parent::removeScope($scope);

		$this->saveScope();
	}

	public function getScopeEncode()
	{
		return implode('+', array_map('urlencode', array_unique($this->getScope())));
	}

	public function getResult()
	{
		return $this->arResult;
	}

	public function getError()
	{
		return is_array($this->arResult) && isset($this->arResult['error'])
			? $this->arResult['error']
			: '';
	}

	public function GetAuthUrl($redirect_uri, $state = '', $apiKey = '')
	{
		return static::AUTH_URL.
			"?client_id=".urlencode($this->appID).
			"&redirect_uri=".urlencode($redirect_uri).
			"&scope=".$this->getScopeEncode().
			"&response_type=code".
			"&access_type=offline".
			($this->refresh_token <> '' ? '' : '&approval_prompt=force').
			($state <> '' ? '&state='.urlencode($state) : '').
			($apiKey !== '' ? '&key=' . urlencode($apiKey) : '')
		;
	}

	public function setIdTokenAuth(string $tokenId): void
	{
		$this->idTokenAuth = $tokenId;
	}

	private function fetchPublicKeys(): ?array
	{
		if ($this->fetchedPublicKeys)
		{
			return $this->fetchedPublicKeys;
		}

		try
		{
			$publicKeys = $this->getDecodedJson(self::CERTS_URL);
			if (empty($publicKeys['keys']) || count($publicKeys['keys']) < 1)
			{
				return null;
			}

			$parsedPublicKeys = JWK::parseKeySet($publicKeys['keys']);
			foreach ($parsedPublicKeys as $keyId => $publicKey)
			{
				$details = openssl_pkey_get_details($publicKey);
				$this->fetchedPublicKeys[$keyId] = $details['key'];
			}

			return $this->fetchedPublicKeys;
		}
		catch (\Exception $e)
		{
		}

		return null;
	}

	private function decodeIdentityToken(string $identityToken): array
	{
		$publicKeys = $this->fetchPublicKeys();
		if ($publicKeys === null)
		{
			return [];
		}

		try
		{
			return (array)JWT::decode($identityToken, $publicKeys, self::JWT_ALG);
		}
		catch (UnexpectedValueException $exception)
		{
			return [];
		}
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
				$redirect_uri = \CSocServGoogleOAuth::getControllerUrl()."/redirect.php";
			}
			else
			{
				$redirect_uri = $this->getRedirectUri();
			}
		}

		$authParams = [
			"client_id" => $this->appID,
			"code" => $this->code,
			"redirect_uri" => $redirect_uri,
			"grant_type" => "authorization_code",
			"client_secret" => $this->appSecret,
		];

		$this->arResult = $this->getDecodedJson(static::TOKEN_URL, $authParams);

		if(isset($this->arResult["access_token"]) && $this->arResult["access_token"] <> '')
		{
			if(isset($this->arResult["refresh_token"]) && $this->arResult["refresh_token"] <> '')
			{
				$this->refresh_token = $this->arResult["refresh_token"];
			}
			$this->access_token = $this->arResult["access_token"];
			$this->accessTokenExpires = $this->arResult["expires_in"] + time();

			$_SESSION["OAUTH_DATA"] = array(
				"OATOKEN" => $this->access_token,
				"OATOKEN_EXPIRES" => $this->accessTokenExpires,
				"REFRESH_TOKEN" => $this->refresh_token,
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
		if ($this->idTokenAuth)
		{
			$identity = $this->decodeIdentityToken($this->idTokenAuth);

			if (!$identity)
			{
				$this->logger->error('oauth.user.fetch_failed', [
					'reason' => 'invalid_identity_token',
				]);
			}

			return $identity ?: false;
		}

		if($this->access_token === false)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'empty_access_token',
			]);

			return false;
		}

		$result = $this->getDecodedJson(static::CONTACTS_URL.'?access_token='.urlencode($this->access_token));

		if ($result)
		{
			$result["access_token"] = $this->access_token;
			$result["refresh_token"] = $this->refresh_token;
			$result["expires_in"] = $this->accessTokenExpires;
		}
		elseif ($result === [] || $result === null)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response',
			]);
		}

		return $result;
	}

	public function GetAppInfo()
	{
		if ($this->idTokenAuth)
		{
			$identity = $this->decodeIdentityToken($this->idTokenAuth);
			if (empty($identity['aud']))
			{
				return false;
			}

			return [
				'id' => $identity['aud'],
			];
		}
		if ($this->access_token === false)
		{
			return false;
		}

		$result = $this->getDecodedJson(static::TOKENINFO_URL.'?access_token='.urlencode($this->access_token));

		if ($result && $result["audience"])
		{
			$result["id"] = $result["audience"];
		}

		return $result;
	}

	public function GetCurrentUserFriends($limit, &$next)
	{
		if($this->access_token === false)
			return false;

		$http = new HttpClient();
		$http->setHeader('GData-Version', '3.0');
		$http->setHeader('Authorization', 'Bearer '.$this->access_token);

		$url = static::FRIENDS_URL.'?';

		$limit = (int)$limit;
		$next = (int)$next;

		if ($limit > 0)
		{
			$url .= '&max-results='.$limit;
		}

		if ($next > 0)
		{
			$url .= '&start-index='.$next;
		}

		$result = $http->get($url);

		if((int)$http->getStatus() === 200)
		{
			$obXml = new \CDataXML();
			if($obXml->loadString($result))
			{
				$tree = $obXml->getTree();

				$total = $tree->elementsByName("totalResults");
				$total = (int)$total[0]->textContent();

				$limitNode = $tree->elementsByName("itemsPerPage");
				$next += (int)$limitNode[0]->textContent();

				if($next >= $total)
				{
					$next = '__finish__';
				}

				$arFriends = array();
				$arEntries = $tree->elementsByName('entry');
				foreach($arEntries as $entry)
				{
					$arEntry = array();
					$entryChildren = $entry->children();

					foreach ($entryChildren as $child)
					{
						$tag = $child->name();

						switch($tag)
						{
							case 'category':
							case 'updated':
							case 'edited';
								break;

							case 'name':
								$arEntry[$tag] = array();
								foreach($child->children() as $subChild)
								{
									$arEntry[$tag][$subChild->name()] = $subChild->textContent();
								}
							break;

							case 'email':

								if($child->getAttribute('primary') == 'true')
								{
									$arEntry[$tag] = $child->getAttribute('address');
								}

							break;
							default:

								$tagContent = $tag == 'link'
									? $child->getAttribute('href')
									: $child->textContent();

								if($child->getAttribute('rel'))
								{
									if(!isset($arEntry[$tag]))
									{
										$arEntry[$tag] = array();
									}

									$arEntry[$tag][preg_replace("/^[^#]*#/", "", $child->getAttribute('rel'))] = $tagContent;
								}
								elseif(isset($arEntry[$tag]))
								{
									if(!is_array($arEntry[$tag][0]) || !isset($arEntry[$tag][0]))
									{
										$arEntry[$tag] = array($arEntry[$tag], $tagContent);
									}
									else
									{
										$arEntry[$tag][] = $tagContent;
									}
								}
								else
								{
									$arEntry[$tag] = $tagContent;
								}
						}
					}

					if($arEntry['email'])
					{
						$arFriends[] = $arEntry;
					}
				}
				return $arFriends;
			}
		}

		return false;
	}

	public function getNewAccessToken($refreshToken = false, $userId = 0, $save = false)
	{
		if($this->appID == false || $this->appSecret == false)
		{
			return false;
		}

		if($refreshToken === false)
		{
			$refreshToken = $this->refresh_token;
		}

		$this->arResult = $this->getDecodedJson(static::TOKEN_URL, [
			"client_id" => $this->appID,
			"refresh_token"=>$refreshToken,
			"grant_type"=>"refresh_token",
			"client_secret" => $this->appSecret,
		]);

		if (isset($this->arResult["access_token"]) && $this->arResult["access_token"] <> '')
		{
			$this->access_token = $this->arResult["access_token"];
			$this->accessTokenExpires = $this->arResult["expires_in"] + time();
			if ($save && intval($userId) > 0)
			{
				$dbSocservUser = \Bitrix\Socialservices\UserTable::getList(array(
					'filter' => array(
						'=EXTERNAL_AUTH_ID' => static::SERVICE_ID,
						'=USER_ID' => $userId,
					),
					'select' => array("ID")
				));
				if($arOauth = $dbSocservUser->Fetch())
				{
					\Bitrix\Socialservices\UserTable::update($arOauth["ID"], array(
						"OATOKEN" => $this->access_token,
						"OATOKEN_EXPIRES" => $this->accessTokenExpires)
					);
				}
			}

			return true;
		}

		return false;
	}

	public function getRedirectUri()
	{
		return (string)(new Uri(static::REDIRECT_URI))->toAbsolute();
	}
}
