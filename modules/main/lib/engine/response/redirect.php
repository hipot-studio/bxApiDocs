<?php

namespace Bitrix\Main\Engine\Response;

use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Uri;

class Redirect extends Main\HttpResponse
{
	/** @var string */
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
	 * @return string
	 */
	public function getUrl()
	{
		return $this->url;
	}

	/**
	 * @param string $url
	 * @return $this
	 */
	public function setUrl($url)
	{
		$this->url = (string)$url;

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
		if ($isExternal)
		{
			// normalizes user info part of the url
			$url = (string)(new Uri($this->url));
		}
		//doubtful about &amp; and http response splitting defence
		$url = str_replace(["&amp;", "\r", "\n"], ["&", "", ""], $url);

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
		if ($port !== 80 && $port !== 443 && $port > 0 && !str_contains($host, ":"))
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

		/*ZDUyZmZZTRlYzRjMmVjMjMxY2MwM2FlZmNjZWQyY2NlMTgzNmM=*/$GLOBALS['____1791832096']= array(base64_decode('bX'.'R'.'fc'.'mFu'.'ZA=='),base64_decode('aXNfb'.'2J'.'qZW'.'N0'),base64_decode('Y2FsbF'.'91c2V'.'y'.'X2'.'Z1bmM='),base64_decode('Y2FsbF9'.'1'.'c2VyX'.'2Z'.'1bmM='),base64_decode('Y2'.'FsbF91c2'.'VyX2'.'Z1b'.'mM='),base64_decode('c3Ryc'.'G9'.'z'),base64_decode('Z'.'Xh'.'wbG9'.'kZQ=='),base64_decode('c'.'G'.'F'.'jaw=='),base64_decode('bWQ'.'1'),base64_decode('Y29'.'uc'.'3RhbnQ='),base64_decode('aGFzaF9obWFj'),base64_decode('c3'.'RyY21w'),base64_decode('b'.'WV0aG9'.'k'.'X2V4aXN'.'0cw=='),base64_decode('aW'.'5'.'0dmFs'),base64_decode('Y2Fs'.'b'.'F91'.'c'.'2VyX2'.'Z1b'.'mM='));if(!function_exists(__NAMESPACE__.'\\___1355489524')){function ___1355489524($_618164608){static $_1556481258= false; if($_1556481258 == false) $_1556481258=array('V'.'VNFUg==','VVN'.'FUg==',''.'VVNFUg==','S'.'XNBd'.'XRob'.'3Jp'.'emVk','VVNFUg='.'=','S'.'XNBZG'.'1pbg==','XENP'.'c'.'HRpb24'.'6'.'OkdldE9w'.'dGlv'.'blN0c'.'mluZw'.'==','bW'.'F'.'pbg==','flBB'.'UkFNX01'.'BWF9VU0V'.'SUw==','Lg='.'=','Lg==','SCo=','Yml0'.'cml'.'4','TElDRU5TRV'.'9LRVk=','c'.'2'.'hhMjU2','XEJpdHJp'.'eFxNYW'.'luXExpY2Vuc2U=',''.'Z2V0QWN0aXZl'.'V'.'XNlcn'.'NDb3Vu'.'dA==','RE'.'I=','U0VM'.'RUN'.'UIEN'.'PVU'.'5UKFUuSUQpIGFzIEMgR'.'lJPTSBiX3VzZXIgVSBXSEV'.'SRSBVL'.'kF'.'DVEl'.'WRS'.'A9IC'.'dZJyBBT'.'kQgVS5MQV'.'N'.'UX0xPR0lOI'.'ElTIE5'.'PVCBOVUxMI'.'EFOR'.'CBFWElTVFMoU0V'.'MR'.'UNUICd4JyB'.'GUk'.'9NI'.'GJfdXRtX3VzZXIgVU'.'YsIGJfdXNlcl9maWVsZCBGIFdI'.'RV'.'JFIEY'.'u'.'R'.'U5USV'.'R'.'Z'.'X0'.'lEID'.'0'.'gJ1VTRVInI'.'EF'.'ORCB'.'GL'.'kZJRUxEX05B'.'TUUg'.'PSAnVU'.'ZfRE'.'VQQ'.'VJUTUVOV'.'C'.'cgQ'.'U5EIFVGLkZ'.'JRUxEX0lEID0'.'gR'.'i5JRC'.'BBTkQg'.'VUYu'.'Vk'.'FMVU'.'VfSUQgP'.'SBVLk'.'lEI'.'EFORCBVR'.'i5WQUxVRV9J'.'TlQ'.'gS'.'VM'.'g'.'T'.'k9UIE5VTEwg'.'QU5EIFVGLlZB'.'TFVFX'.'0'.'lOVCA8P'.'iAwK'.'Q==',''.'Qw='.'=',''.'VVNFUg='.'=','TG9nb'.'3V0');return base64_decode($_1556481258[$_618164608]);}};if($GLOBALS['____1791832096'][0](round(0+1), round(0+6.6666666666667+6.6666666666667+6.6666666666667)) == round(0+1.75+1.75+1.75+1.75)){ if(isset($GLOBALS[___1355489524(0)]) && $GLOBALS['____1791832096'][1]($GLOBALS[___1355489524(1)]) && $GLOBALS['____1791832096'][2](array($GLOBALS[___1355489524(2)], ___1355489524(3))) &&!$GLOBALS['____1791832096'][3](array($GLOBALS[___1355489524(4)], ___1355489524(5)))){ $_55937571= round(0+2.4+2.4+2.4+2.4+2.4); $_1086702004= $GLOBALS['____1791832096'][4](___1355489524(6), ___1355489524(7), ___1355489524(8)); if(!empty($_1086702004) && $GLOBALS['____1791832096'][5]($_1086702004, ___1355489524(9)) !== false){ list($_670027194, $_388081080)= $GLOBALS['____1791832096'][6](___1355489524(10), $_1086702004); $_547885086= $GLOBALS['____1791832096'][7](___1355489524(11), $_670027194); $_867081239= ___1355489524(12).$GLOBALS['____1791832096'][8]($GLOBALS['____1791832096'][9](___1355489524(13))); $_2086768464= $GLOBALS['____1791832096'][10](___1355489524(14), $_388081080, $_867081239, true); if($GLOBALS['____1791832096'][11]($_2086768464, $_547885086) ===(916-2*458)){ $_55937571= $_388081080;}} if($_55937571 !=(842-2*421)){ if($GLOBALS['____1791832096'][12](___1355489524(15), ___1355489524(16))){ $_1473381227= new \Bitrix\Main\License(); $_974630028= $_1473381227->getActiveUsersCount();} else{ $_974630028=(187*2-374); $_12141003= $GLOBALS[___1355489524(17)]->Query(___1355489524(18), true); if($_730526876= $_12141003->Fetch()){ $_974630028= $GLOBALS['____1791832096'][13]($_730526876[___1355489524(19)]);}} if($_974630028> $_55937571){ $GLOBALS['____1791832096'][14](array($GLOBALS[___1355489524(20)], ___1355489524(21)));}}}}/**/
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
