<?
use Bitrix\Socialservices\OAuth\OAuthErrorCode;
use Bitrix\Main\Web\Uri;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

IncludeModuleLangFile(__FILE__);

class CSocServOdnoklassniki extends CSocServAuth
{
	const ID = "Odnoklassniki";
	const CONTROLLER_URL = "https://www.bitrix24.ru/controller";

	protected $entityOAuth = null;

	public function GetSettings()
	{
		return array(
			array("odnoklassniki_appid", GetMessage("socserv_odnoklassniki_client_id"), "", Array("text", 40)),
			array("odnoklassniki_appkey", GetMessage("socserv_odnoklassniki_client_key"), "", Array("text", 40)),
			array("odnoklassniki_appsecret", GetMessage("socserv_odnoklassniki_client_secret"), "", Array("text", 40)),
			array("note"=>GetMessage("socserv_odnoklassniki_form_note", array('#URL#'=>(string)(new Uri("/bitrix/tools/oauth/odnoklassniki.php"))->toAbsolute()))),
		);
	}

	public function getEntityOAuth()
	{
		if ($this->entityOAuth === null)
		{
			$this->entityOAuth = new COdnoklassnikiInterface();
			$this->entityOAuth->setLogger($this->logger);
		}

		return $this->entityOAuth;
	}

	public function GetFormHtml($arParams)
	{
		$url = $this->getUrl('opener', null, $arParams);
		$phrase = ($arParams["FOR_INTRANET"]) ? GetMessage("MAIN_OPTION_COMMENT1_INTRANET") : GetMessage("MAIN_OPTION_COMMENT1");

		if($arParams["FOR_INTRANET"])
			return array("ON_CLICK" => 'onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)"');
		return '<a href="javascript:void(0)" onclick="BX.util.popup(\''.htmlspecialcharsbx(CUtil::JSEscape($url)).'\', 680, 800)" class="bx-ss-button odnoklassniki-button"></a><span class="bx-spacer"></span><span>'.$phrase.'</span>';
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

		if ($this->isCloudPortal())
		{
			$portalRedirectUri = (new Uri('/bitrix/tools/oauth/odnoklassniki.php'))
				->addParams(['state' => $state,])
				->toAbsolute()
			;

			$state = (string)$portalRedirectUri;
			$redirect_uri = self::CONTROLLER_URL . '/redirect.php';
		}
		else
		{
			$redirect_uri = (string)(new Uri('/bitrix/tools/oauth/odnoklassniki.php'))->toAbsolute();
		}

		return $this->getEntityOAuth()->GetAuthUrl($redirect_uri, $state);
	}

	public function Authorize()
	{
		global $APPLICATION;

		$APPLICATION->RestartBuffer();
		$bSuccess = SOCSERV_AUTHORISATION_ERROR;
		$bProcessState = false;
		$this->logger->info('oauth.auth.start');

		if (empty($_REQUEST['code']))
		{
			$this->logger->error('oauth.request.invalid_code');
			$this->sendOauthError(OAuthErrorCode::MissingCode);
		}
		elseif (CSocServAuthManager::CheckUniqueKey())
		{
			$bProcessState = true;

			if ($this->isCloudPortal())
				$redirect_uri = self::CONTROLLER_URL."/redirect.php";
			else
				$redirect_uri= (string)(new Uri("/bitrix/tools/oauth/odnoklassniki.php"))->toAbsolute();

			$appID = trim(self::GetOption("odnoklassniki_appid"));
			$appSecret = trim(self::GetOption("odnoklassniki_appsecret"));
			$appKey = trim(self::GetOption("odnoklassniki_appkey"));

			$gAuth = new COdnoklassnikiInterface($appID, $appSecret, $appKey, $_REQUEST["code"]);
			$gAuth->setLogger($this->logger);

			if($gAuth->GetAccessToken($redirect_uri) !== false)
			{
				$arOdnoklUser = $gAuth->GetCurrentUser();

				if(is_array($arOdnoklUser) && ($arOdnoklUser['uid'] <> ''))
				{
					$uid = $arOdnoklUser['uid'];
					$first_name = $last_name = $gender = "";
					if($arOdnoklUser['first_name'] <> '')
						$first_name = $arOdnoklUser['first_name'];
					if($arOdnoklUser['last_name'] <> '')
						$last_name = $arOdnoklUser['last_name'];
					if(isset($arOdnoklUser['gender']) && $arOdnoklUser['gender'] != '')
					{
						if($arOdnoklUser['gender'] == 'male')
							$gender = 'M';
						elseif($arOdnoklUser['gender'] == 'female')
							$gender = 'F';
					}

					$arFields = array(
						'EXTERNAL_AUTH_ID' => self::ID,
						'XML_ID' => "OK".$uid,
						'LOGIN' => "OKuser".$uid,
						'NAME'=> $first_name,
						'LAST_NAME'=> $last_name,
						'PERSONAL_GENDER' => $gender,
					);
					if(isset($arOdnoklUser['birthday']))
						if($date = MakeTimeStamp($arOdnoklUser['birthday'], "YYYY-MM-DD"))
							$arFields["PERSONAL_BIRTHDAY"] = ConvertTimeStamp($date);
					if(isset($arOdnoklUser['pic_2']) && self::CheckPhotoURI($arOdnoklUser['pic_2']))
					{
						if($arPic = CFile::MakeFileArray($arOdnoklUser['pic_2']))
						{
							$arPic['name'] = md5($arOdnoklUser['pic_2']).'.jpg';
							$arFields["PERSONAL_PHOTO"] = $arPic;
						}
					}
					$arFields["PERSONAL_WWW"] = "http://odnoklassniki.ru/profile/".$uid;
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

		$this->onAfterWebAuth(true, $mode, $url);

		CMain::FinalActions();
	}

	public static function SendUserFeed($userId, $message)
	{
		$appID = trim(self::GetOption("odnoklassniki_appid"));
		$appSecret = trim(self::GetOption("odnoklassniki_appsecret"));
		$appKey = trim(self::GetOption("odnoklassniki_appkey"));
		$gAuth = new COdnoklassnikiInterface($appID, $appSecret, $appKey);
		$result = $gAuth->SendFeed($userId, $message);
		return $result;
	}

}

class COdnoklassnikiInterface
{
	const SERVICE_ID = 'Odnoklassniki';

	const AUTH_URL = "https://www.odnoklassniki.ru/oauth/authorize";
	const TOKEN_URL = "https://api.odnoklassniki.ru/oauth/token.do";
	const CONTACTS_URL = "https://api.odnoklassniki.ru/fb.do";

	protected $appID;
	protected $appSecret;
	protected $appKey;
	protected $code = false;
	protected $access_token = false;
	protected $sign = false;
	protected $refresh_token = '';
	protected $userId = 0;

	protected LoggerInterface $logger;

	public function __construct($appID = false, $appSecret = false, $appKey = false, $code=false)
	{
		if($appID === false)
		{
			$appID = trim(CSocServLiveIDOAuth::GetOption("odnoklassniki_appid"));
		}

		if($appSecret === false)
		{
			$appSecret = trim(CSocServLiveIDOAuth::GetOption("odnoklassniki_appsecret"));
		}

		if($appKey === false)
		{
			$appKey = trim(CSocServLiveIDOAuth::GetOption("odnoklassniki_appkey"));
		}

		$this->httpTimeout = SOCSERV_DEFAULT_HTTP_TIMEOUT;
		$this->appID = $appID;
		$this->appSecret = $appSecret;
		$this->code = $code;
		$this->appKey = $appKey;
		$this->logger = new NullLogger();
	}

	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	public function GetAuthUrl($redirect_uri, $state='')
	{
		return self::AUTH_URL.
			"?client_id=".urlencode($this->appID).
			"&redirect_uri=".urlencode($redirect_uri).
			"&response_type=code".
			($state <> ''? '&state='.urlencode($state):'');
	}

	public function GetAccessToken($redirect_uri)
	{
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
			$_SESSION["OAUTH_DATA"] = array("OATOKEN" => $this->access_token);
			if(isset($arResult["refresh_token"]) && $arResult["refresh_token"] <> '')
			{
				$this->refresh_token = $arResult["refresh_token"];
				$_SESSION["OAUTH_DATA"]["REFRESH_TOKEN"] = $this->refresh_token;
			}

			$arguments = array();
			$arguments["application_key"] = $this->appKey;
			$arguments['method'] = 'users.getCurrentUser';
			ksort($arguments);
			$this->sign = mb_strtolower(md5('application_key='.$arguments["application_key"].'method='.$arguments['method'].md5($this->access_token.$this->appSecret)));
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

		$result = CHTTP::sGetHeader(self::CONTACTS_URL."?method=users.getCurrentUser&application_key=".$this->appKey."&access_token=".$this->access_token."&sig=".$this->sign, array(), $this->httpTimeout);

		$parsed = CUtil::JsObjectToPhp($result);
		if (!is_array($parsed) || !isset($parsed['uid']) || $parsed['uid'] === '')
		{
			$this->logger->error('oauth.user.fetch_failed', [
				'reason' => 'missing_user_id',
			]);
		}

		return $parsed;
	}

	public function SendFeed($socServUserId, $message, $getNewToken=true)
	{
		if(!$this->access_token || intval($this->userId) < 1)
			self::SetOauthKeys($socServUserId);

		$this->sign = mb_strtolower(md5('application_key='.$this->appKey.'method=users.setStatusstatus='.$message.md5($this->access_token.$this->appSecret)));
		$result = CHTTP::sGetHeader(self::CONTACTS_URL."?method=users.setStatus&application_key=".$this->appKey."&access_token=".$this->access_token."&sig=".$this->sign."&status=".urlencode($message), array(), $this->httpTimeout);

		$arResult = CUtil::JsObjectToPhp($result);
		if($getNewToken === true && isset($arResult["error_code"]) && $arResult["error_code"] == "102")
			{
				$newToken = self::RefreshToken($socServUserId);
				if($newToken === true)
					self::SendFeed($socServUserId, $message, false);
				else
					return false;
			}
		return $arResult;
	}

	private function SetOauthKeys($socServUserId)
	{
		$dbSocservUser = \Bitrix\Socialservices\UserTable::getList([
			'filter' => ['=ID' => $socServUserId],
			'select' => ["OATOKEN", "XML_ID", "REFRESH_TOKEN"]
		]);
		while($arOauth = $dbSocservUser->fetch())
		{
			$this->access_token = $arOauth["OATOKEN"];
			$this->userId = preg_replace("|\D|", '', $arOauth["XML_ID"]);
			$this->refresh_token = $arOauth["REFRESH_TOKEN"];
		}
	}

	private function RefreshToken($socServUserId)
	{
		$result = CHTTP::sPostHeader(self::TOKEN_URL, array(
			"refresh_token"=>$this->refresh_token,
			"client_id"=>$this->appID,
			"client_secret"=>$this->appSecret,
			"grant_type"=>"refresh_token",
		), array(), $this->httpTimeout);
		$arResult = CUtil::JsObjectToPhp($result);

		if(isset($arResult["access_token"]) && $arResult["access_token"] <> '')
		{
			$this->access_token = $arResult["access_token"];
			\Bitrix\Socialservices\UserTable::update($socServUserId, array("OATOKEN" => $arResult["access_token"]));
			return true;
		}
		return false;
	}
}
?>
