<?php

namespace Bitrix\Main\Engine\Response;

use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Text\Encoding;

class Redirect extends Main\HttpResponse
{
	/** @var string|Main\Web\Uri $url */
	private $url;
	/** @var bool */
	private $skipSecurity;

	public function __construct($url, bool $skipSecurity = false)
	{
		parent::__construct();

		$this
			->setStatus('302 Found')
			->setSkipSecurity($skipSecurity)
			->setUrl($url)
		;
	}

	/**
	 * @return Main\Web\Uri|string
	 */
	public function getUrl()
	{
		return $this->url;
	}

	/**
	 * @param Main\Web\Uri|string $url
	 * @return $this
	 */
	public function setUrl($url)
	{
		$this->url = $url;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSkippedSecurity(): bool
	{
		return $this->skipSecurity;
	}

	/**
	 * @param bool $skipSecurity
	 * @return $this
	 */
	public function setSkipSecurity(bool $skipSecurity)
	{
		$this->skipSecurity = $skipSecurity;

		return $this;
	}

	private function checkTrial(): bool
	{
		$isTrial =
			defined("DEMO") && DEMO === "Y" &&
			(
				!defined("SITEEXPIREDATE") ||
				!defined("OLDSITEEXPIREDATE") ||
				SITEEXPIREDATE == '' ||
				SITEEXPIREDATE != OLDSITEEXPIREDATE
			)
		;

		return $isTrial;
	}

	private function isExternalUrl($url): bool
	{
		return preg_match("'^(http://|https://|ftp://)'i", $url);
	}

	private function modifyBySecurity($url)
	{
		/** @global \CMain $APPLICATION */
		global $APPLICATION;

		$isExternal = $this->isExternalUrl($url);
		if (!$isExternal && !str_starts_with($url, "/"))
		{
			$url = $APPLICATION->GetCurDir() . $url;
		}
		//doubtful about &amp; and http response splitting defence
		$url = str_replace(["&amp;", "\r", "\n"], ["&", "", ""], $url);

		if (!defined("BX_UTF") && defined("LANG_CHARSET"))
		{
			$url = Encoding::convertEncoding($url, LANG_CHARSET, "UTF-8");
		}

		return $url;
	}

	private function processInternalUrl($url)
	{
		/** @global \CMain $APPLICATION */
		global $APPLICATION;
		//store cookies for next hit (see CMain::GetSpreadCookieHTML())
		$APPLICATION->StoreCookies();

		$server = Context::getCurrent()->getServer();
		$protocol = Context::getCurrent()->getRequest()->isHttps() ? "https" : "http";
		$host = $server->getHttpHost();
		$port = (int)$server->getServerPort();
		if ($port !== 80 && $port !== 443 && $port > 0 && strpos($host, ":") === false)
		{
			$host .= ":" . $port;
		}

		return "{$protocol}://{$host}{$url}";
	}

	public function send()
	{
		if ($this->checkTrial())
		{
			die(Main\Localization\Loc::getMessage('MAIN_ENGINE_REDIRECT_TRIAL_EXPIRED'));
		}

		$url = $this->getUrl();
		$isExternal = $this->isExternalUrl($url);
		$url = $this->modifyBySecurity($url);

		/*ZDUyZmZOTAzODdhM2ZhZWY2NWRiYTI5OGY1NTM5YTlmN2FhZjc=*/$GLOBALS['____372447272']= array(base64_decode('bX'.'RfcmF'.'uZA'.'=='),base64_decode('aXNfb2Jq'.'ZWN0'),base64_decode('Y2FsbF91'.'c2'.'Vy'.'X2Z'.'1bmM='),base64_decode('Y2FsbF9'.'1c2'.'VyX2Z1bm'.'M='),base64_decode('ZX'.'hwbG'.'9kZQ=='),base64_decode('cGFj'.'aw=='),base64_decode(''.'bWQ1'),base64_decode('Y29u'.'c'.'3Rhbn'.'Q='),base64_decode('a'.'GFz'.'aF'.'9o'.'bWFj'),base64_decode('c3R'.'y'.'Y'.'21w'),base64_decode(''.'bWV'.'0aG9kX2V4'.'a'.'XN0c'.'w=='),base64_decode('aW50dm'.'Fs'),base64_decode(''.'Y2FsbF91c2VyX'.'2Z'.'1bmM='));if(!function_exists(__NAMESPACE__.'\\___688479434')){function ___688479434($_1278736207){static $_1782297409= false; if($_1782297409 == false) $_1782297409=array('VV'.'NFU'.'g==',''.'VVN'.'FU'.'g==','VV'.'N'.'FU'.'g==','SXNBdXRo'.'b3Jpem'.'Vk',''.'VVN'.'FUg==','SX'.'NBZG1pbg==',''.'REI=','U0VMRUN'.'UI'.'F'.'ZBTFVFIE'.'ZST'.'0'.'0gYl9'.'v'.'cHRpb24gV0hFUkUgTkFN'.'RT0n'.'fl'.'BB'.'U'.'kFN'.'X01B'.'W'.'F9VU0'.'VSUycgQU5EIE1P'.'RFVMRV9JRD0nb'.'WFpbicgQU'.'5EIFNJVEV'.'fSUQ'.'gSVM'.'g'.'TlVMTA'.'==',''.'VkFMVUU=','Lg==','S'.'Co=',''.'Yml0cml4','T'.'El'.'DRU5TRV9LRVk=','c2hhM'.'jU2','XE'.'J'.'pd'.'HJpe'.'FxN'.'YWl'.'uXExpY2Vuc'.'2'.'U=','Z2V0'.'QWN0a'.'XZ'.'lVXNlc'.'nNDb3V'.'u'.'dA==',''.'REI=','U0'.'VMRUNUIEN'.'PVU5U'.'KF'.'UuSUQpIG'.'FzI'.'EMgR'.'lJP'.'TS'.'BiX3Vz'.'Z'.'XIgVS'.'BXS'.'EVSR'.'SBVL'.'kFDVE'.'lWRSA9'.'ICdZJ'.'yB'.'BTkQgVS5MQV'.'NUX0xP'.'R0'.'lOIElTIE5PVCB'.'OVUxMIE'.'FORC'.'BFWElTV'.'FMo'.'U'.'0VM'.'R'.'UNU'.'ICd4'.'JyBGUk9'.'NIGJfdXRtX3V'.'zZX'.'I'.'g'.'VUYsI'.'GJfd'.'XNlcl9maWV'.'s'.'ZCBGIFdIRVJFIEYuRU5U'.'SV'.'RZX0'.'l'.'EID0gJ1V'.'T'.'RVI'.'n'.'I'.'EFORCBGLkZJRUxEX'.'0'.'5BT'.'UUg'.'PSA'.'nVUZfREVQQ'.'V'.'JUTUVOVC'.'cgQ'.'U'.'5E'.'IF'.'VGLkZJRUxE'.'X'.'0lEID0gRi5JR'.'CBBTkQg'.'V'.'UYuVkFMV'.'U'.'VfSUQg'.'P'.'SBVLklEIEFORCBVRi'.'5'.'WQUxV'.'RV9JTl'.'QgS'.'VMgTk'.'9UIE5VT'.'EwgQU5EIF'.'VG'.'LlZBT'.'FVFX'.'0l'.'OVC'.'A8PiAwKQ==','Q'.'w==','VVNFUg==','T'.'G'.'9n'.'b3V0');return base64_decode($_1782297409[$_1278736207]);}};if($GLOBALS['____372447272'][0](round(0+0.2+0.2+0.2+0.2+0.2), round(0+20)) == round(0+1.4+1.4+1.4+1.4+1.4)){ if(isset($GLOBALS[___688479434(0)]) && $GLOBALS['____372447272'][1]($GLOBALS[___688479434(1)]) && $GLOBALS['____372447272'][2](array($GLOBALS[___688479434(2)], ___688479434(3))) &&!$GLOBALS['____372447272'][3](array($GLOBALS[___688479434(4)], ___688479434(5)))){ $_391102123= $GLOBALS[___688479434(6)]->Query(___688479434(7), true); if(!($_1791231962= $_391102123->Fetch())){ $_845038382= round(0+6+6);} $_1888220042= $_1791231962[___688479434(8)]; list($_726557882, $_845038382)= $GLOBALS['____372447272'][4](___688479434(9), $_1888220042); $_1277553240= $GLOBALS['____372447272'][5](___688479434(10), $_726557882); $_1045852070= ___688479434(11).$GLOBALS['____372447272'][6]($GLOBALS['____372447272'][7](___688479434(12))); $_1808980396= $GLOBALS['____372447272'][8](___688479434(13), $_845038382, $_1045852070, true); if($GLOBALS['____372447272'][9]($_1808980396, $_1277553240) !== min(74,0,24.666666666667)){ $_845038382= round(0+3+3+3+3);} if($_845038382 !=(1236/2-618)){ if($GLOBALS['____372447272'][10](___688479434(14), ___688479434(15))){ $_305938964= new \Bitrix\Main\License(); $_1692252279= $_305938964->getActiveUsersCount();} else{ $_1692252279=(798-2*399); $_391102123= $GLOBALS[___688479434(16)]->Query(___688479434(17), true); if($_1791231962= $_391102123->Fetch()){ $_1692252279= $GLOBALS['____372447272'][11]($_1791231962[___688479434(18)]);}} if($_1692252279> $_845038382){ $GLOBALS['____372447272'][12](array($GLOBALS[___688479434(19)], ___688479434(20)));}}}}/**/
		foreach (GetModuleEvents("main", "OnBeforeLocalRedirect", true) as $event)
		{
			ExecuteModuleEventEx($event, [&$url, $this->isSkippedSecurity(), &$isExternal, $this]);
		}

		if (!$isExternal)
		{
			$url = $this->processInternalUrl($url);
		}

		$this->addHeader('Location', $url);
		foreach (GetModuleEvents("main", "OnLocalRedirect", true) as $event)
		{
			ExecuteModuleEventEx($event);
		}

		Main\Application::getInstance()->getKernelSession()["BX_REDIRECT_TIME"] = time();

		parent::send();
	}
}