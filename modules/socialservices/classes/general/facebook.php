<?

use Bitrix\Main\Web\Json;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Socialservices\OAuth\StateService;
IncludeModuleLangFile(__FILE__);

class CSocServFacebook extends CSocServAuth
{
	const ID = "Facebook";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";
	const LOGIN_PREFIX = "FB_";

	protected $entityOAuth = null;

	/**
	 * @param string $code =false
	 * @return CFacebookInterface
	 */
	public function getEntityOAuth($code = false)
	{
		if(!$this->entityOAuth)
		{
			$this->entityOAuth = new CFacebookInterface();
		}

		if($code !== false)
		{
			$this->entityOAuth->setCode($code);
		}

		$this->entityOAuth->setLogger($this->logger);

		return $this->entityOAuth;
	}

	/**
	 * @return array
	 */
	public function GetSettings(): array
	{
		$urlPreviewEnable = Option::get('main', 'url_preview_enable', 'Y');
		$result = [
			['facebook_appid', Loc::getMessage('socserv_fb_id'), '', ['text', 40]],
			['facebook_appsecret', Loc::getMessage('socserv_fb_secret'), '', ['text', 40]],
			['note' => Loc::getMessage('socserv_fb_sett_note1', ['#URL#'=>$this->getEntityOAuth()->GetRedirectURI()])],
		];

		if($urlPreviewEnable === 'Y')
		{
			$result[] = ['facebook_instagram_url_preview_enable', Loc::getMessage('socserv_fb_instagram_url_preview'), '', ['checkbox']];
			$result[] = ['note' => Loc::getMessage('socserv_fb_sett_note_oembed_2')];
		}

		return $result;
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl($arParams);

		$phrase = ($arParams["FOR_INTRANET"])
			? GetMessage("socserv_fb_note_intranet")
			: GetMessage("socserv_fb_note");

		return $arParams["FOR_INTRANET"]
			? array("ON_CLICK" => 'onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)"')
			: '<a href="javascript:void(0)" onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)" class="bx-ss-button facebook-button"></a><span class="bx-spacer"></span><span>'.$phrase.'</span>';
	}

	public function GetOnClickJs($arParams)
	{
		$url = $this->getUrl($arParams);
		return "BX.util.popup('".CUtil::JSEscape($url)."', 680, 800)";
	}

	public function getUrl($arParams)
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

	public function addScope($scope)
	{
		return $this->getEntityOAuth()->addScope($scope);
	}

	public function prepareUser($arFBUser, $short = false)
	{
		$arFields = array(
			'EXTERNAL_AUTH_ID' => self::ID,
			'XML_ID' => $arFBUser["id"],
			'LOGIN' => static::LOGIN_PREFIX.$arFBUser["id"],
			'EMAIL' => ($arFBUser["email"] != '') ? $arFBUser["email"] : '',
			'NAME'=> $arFBUser["first_name"],
			'LAST_NAME'=> $arFBUser["last_name"],
			'OATOKEN' => $this->entityOAuth->getToken(),
			'OATOKEN_EXPIRES' => $this->entityOAuth->getAccessTokenExpires(),
		);

		if(!$short && isset($arFBUser['picture']['data']['url']) && !$arFBUser['picture']['data']['is_silhouette'])
		{
			$picture_url = CFacebookInterface::GRAPH_URL.'/'.$arFBUser['id'].'/picture?type=large';
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

		if(isset($arFBUser['birthday']))
		{
			if($date = MakeTimeStamp($arFBUser['birthday'], "MM/DD/YYYY"))
			{
				$arFields["PERSONAL_BIRTHDAY"] = ConvertTimeStamp($date);
			}
		}

		if(isset($arFBUser['gender']) && $arFBUser['gender'] != '')
		{
			if($arFBUser['gender'] == 'male')
			{
				$arFields["PERSONAL_GENDER"] = 'M';
			}
			elseif($arFBUser['gender'] == 'female')
			{
				$arFields["PERSONAL_GENDER"] = 'F';
			}
		}

		$arFields["PERSONAL_WWW"] = $this->getProfileUrl($arFBUser['id']);

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
				$redirect_uri = static::CONTROLLER_URL."/redirect.php";
			}
			else
			{
				$redirect_uri = $this->getEntityOAuth()->GetRedirectURI();
			}

			$this->entityOAuth = $this->getEntityOAuth($_REQUEST['code']);
			if($this->entityOAuth->GetAccessToken($redirect_uri) !== false)
			{
				$arFBUser = $this->entityOAuth->GetCurrentUser();
				if(is_array($arFBUser) && isset($arFBUser["id"]))
				{
					$arFields = self::prepareUser($arFBUser);
					$authError = $this->AuthorizeUser($arFields);
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

		$url = $this->getRedirectUriAfterAuthorize($authError, self::ID);

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
		{
			$redirect_uri = self::CONTROLLER_URL."/redirect.php?redirect_to=".urlencode(CSocServUtil::GetCurUrl('auth_service_id='.self::ID, array("code")));
		}
		else
		{
			$redirect_uri = CSocServUtil::GetCurUrl('auth_service_id='.self::ID, array("code"));
		}

		$fb = $this->getEntityOAuth();
		if($fb->GetAccessToken($redirect_uri) !== false)
		{
			$res = $fb->GetCurrentUserFriends($limit, $next);
			if(is_array($res))
			{
				foreach($res['data'] as $key => $value)
				{
					$res['data'][$key]['uid'] = $value['id'];
					$res['data'][$key]['url'] = $this->getProfileUrl($value['id']);

					if(is_array($value['picture']))
					{
						if(!$value['picture']['data']['is_silhouette'])
						{
							$res['data'][$key]['picture'] = CFacebookInterface::GRAPH_URL.'/'.$value['id'].'/picture?type=large';
						}
						else
						{
							$res['data'][$key]['picture'] = '';
						}
						//$res['data'][$key]['picture'] = $value['picture']['data']['url'];
					}
				}

				return $res['data'];
			}
		}

		return false;
	}

	public function sendMessage($uid, $message)
	{
		$fb = new CFacebookInterface();

		if ($this->isCloudPortal())
		{
			$redirect_uri = self::CONTROLLER_URL."/redirect.php?redirect_to=".urlencode(CSocServUtil::GetCurUrl('auth_service_id='.self::ID, array("code")));
		}
		else
		{
			$redirect_uri = CSocServUtil::GetCurUrl('auth_service_id='.self::ID, array("code"));
		}

		if($fb->GetAccessToken($redirect_uri) !== false)
		{
			$res = $fb->sendMessage($uid, $message);
		}


		return $res;
	}

	public function getMessages($uid)
	{
		$fb = new CFacebookInterface();

		if ($this->isCloudPortal())
		{
			$redirect_uri = self::CONTROLLER_URL."/redirect.php?redirect_to=".urlencode(CSocServUtil::GetCurUrl('auth_service_id='.self::ID, array("code")));
		}
		else
		{
			$redirect_uri = CSocServUtil::GetCurUrl('auth_service_id='.self::ID, array("code"));
		}

		if($fb->GetAccessToken($redirect_uri) !== false)
		{
			$res = $fb->getMessages($uid);
		}

		return $res;
	}
	public function getProfileUrl($uid)
	{
		return "http://www.facebook.com/".$uid;
	}

	public static function SendUserFeed($userId, $message, $messageId)
	{
		$fb = new CFacebookInterface();
		return $fb->SendFeed($userId, $message, $messageId);
	}

}

class CFacebookInterface extends CSocServOAuthTransport
{
	const SERVICE_ID = "Facebook";

	const AUTH_URL = "https://www.facebook.com/dialog/oauth";
	const GRAPH_URL = "https://graph.facebook.com";

	protected $userId = false;
	protected $responseData = array();

	protected $scope = array(
		"email",
	);

	public function __construct($appID = false, $appSecret = false, $code=false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServFacebook::GetOption("facebook_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServFacebook::GetOption("facebook_appsecret"));
		}

		parent::__construct($appID, $appSecret, $code);
	}

	public function GetRedirectURI()
	{
		return (string)(new Uri("/bitrix/tools/oauth/facebook.php"))->toAbsolute();
	}

	public function GetAuthUrl($redirect_uri, $state = '')
	{
/*		if(IsModuleInstalled('oauth'))
		{
			$_SESSION["FACEBOOK_OAUTH_LAST_REDIRECT_URI"] = $redirect_uri;
		}*/

		return self::AUTH_URL .
			"?client_id=" . $this->appID .
			"&redirect_uri=" . urlencode($redirect_uri) .
			"&scope=".$this->getScopeEncode()."&display=popup" .
			($state <> '' ? '&state=' . urlencode($state) : '');
	}

	public function getResult()
	{
		return $this->responseData;
	}

	public function GetAccessToken($redirect_uri)
	{
		$token = $this->getStorageTokens();
		if(is_array($token))
		{
			$this->access_token = $token["OATOKEN"];
			$this->accessTokenExpires = $token["OATOKEN_EXPIRES"];

			if($this->checkAccessToken())
			{
				return true;
			}
		}

		if($this->code === false)
		{
			$this->logger->error('oauth.token.exchange_failed', [
				'reason' => 'empty_code',
			]);

			return false;
		}

		$result = CHTTP::sGetHeader(self::GRAPH_URL.'/oauth/access_token?client_id='.$this->appID.'&client_secret='.$this->appSecret.'&redirect_uri='.urlencode($redirect_uri).'&code='.urlencode($this->code), array(), $this->httpTimeout);

		$arResult = Json::decode($result);
		$this->responseData = $arResult;

		if(isset($arResult["access_token"]) && $arResult["access_token"] <> '')
		{
			$result = CHTTP::sGetHeader(self::GRAPH_URL."/oauth/access_token?grant_type=fb_exchange_token&client_id=".$this->appID."&client_secret=".$this->appSecret."&fb_exchange_token=".$arResult["access_token"], array(), $this->httpTimeout);

			$arResultLongLive = Json::decode($result);

			if(isset($arResultLongLive["access_token"]) && $arResultLongLive["access_token"] <> '')
			{
				$arResult["access_token"] = $arResultLongLive["access_token"];
				$arResult["expires"] = isset($arResultLongLive["expires_in"]) ? $arResultLongLive["expires_in"] : 86400 * 60;
				$_SESSION["OAUTH_DATA"] = array(
					"OATOKEN" => $arResultLongLive["access_token"],
					"OATOKEN_EXPIRES" => time() + $arResultLongLive['expires'],
				);
			}

			$this->access_token = $arResult["access_token"];
			$this->accessTokenExpires = time() + $arResult["expires"];

			return true;
		}

		if (isset($this->responseData['error']))
		{
			$this->responseData = array(
				'error' => $this->responseData['error']['type'],
				'error_description' => $this->responseData['error']['message'],
			);
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

		$http = new HttpClient();
		$http->setTimeout($this->httpTimeout);

		$result = $http->get(self::GRAPH_URL.'/me?access_token='.$this->access_token."&fields=picture,id,name,first_name,last_name,gender,email");

		try
		{
			$decoded = Json::decode($result);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response',
			]);

			return [];
		}

		if (!is_array($decoded))
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'invalid_response_payload',
			]);
		}

		return $decoded;
	}

	public function GetAppInfo()
	{
		if($this->access_token === false)
			return false;

		$http = new HttpClient();
		$http->setTimeout($this->httpTimeout);

		$result = $http->get(self::GRAPH_URL.'/debug_token?input_token='.$this->access_token.'&access_token='.$this->appID."|".$this->appSecret);
		$result = Json::decode($result);

		if($result["data"]["app_id"])
		{
			$result["id"] = $result["data"]["app_id"];
		}

		return $result;
	}

	public function GetCurrentUserFriends($limit, &$next)
	{
		if($this->access_token === false)
			return false;

		if(empty($next))
		{
			$url = self::GRAPH_URL.'/me/friends?access_token='.$this->access_token."&fields=picture,id,name,first_name,last_name,gender,email";

			if($limit > 0)
			{
				$url .= "&limit=".intval($limit)."&offset=".intval($next);
			}
		}
		else
		{
			$url = $next;
		}

		$http = new HttpClient();
		$http->setTimeout($this->httpTimeout);

		$result = $http->get($url);

		$result = Json::decode($result);

		if(is_array($result['paging']) && !empty($result['paging']['next']))
		{
			$next = $result['paging']['next'];
		}
		else
		{
			$next = '';
		}

		return $result;
	}

	public function SendFeed($socServUserId, $message, $messageId)
	{
		$isSetOauthKeys = true;
		if(!$this->access_token || !$this->userId)
			$isSetOauthKeys = self::SetOauthKeys($socServUserId);

		if($isSetOauthKeys === false)
		{
			CSocServMessage::Delete($messageId);
			return false;
		}

		$arPost = array("access_token" => $this->access_token, "message"=> $message);
		$result = @CHTTP::sPostHeader($this::GRAPH_URL."/".$this->userId."/feed", $arPost, array(), $this->httpTimeout);
		if($result !== false)
		{
			return CUtil::JsObjectToPhp($result);
		}
		else
			return false;
	}

	public function sendMessage($uid, $message)
	{
		if($this->access_token === false)
			return false;

		$url = self::GRAPH_URL.'/'.$uid.'/apprequests';

		$arPost = array("access_token" => $this->access_token, "message"=> $message);

		$ob = new HttpClient();
		return $ob->post($url, $arPost);
	}

	public function getMessages($uid)
	{
		if($this->access_token === false)
			return false;

		$url = self::GRAPH_URL.'/'.$uid.'/apprequests?access_token='.$this->access_token;

		$ob = new HttpClient();
		return $ob->get($url);
	}

	private function SetOauthKeys($socServUserId)
	{
		$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
			'filter' => ['=ID' => $socServUserId],
			'select' => ["OATOKEN", "XML_ID"]
		]);
		while($arOauth = $dbSocservUser->fetch())
		{
			$this->access_token = $arOauth["OATOKEN"];
			$this->userId = $arOauth["XML_ID"];
		}
		if(!$this->access_token || !$this->userId)
			return false;
		return true;
	}
}
?>
