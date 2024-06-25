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

		/*ZDUyZmZZjBlODA3YWUyNWEyM2I5OWYxNzY0ODYxNzY5NTczMzI=*/$GLOBALS['____2049715537']= array(base64_decode(''.'bX'.'R'.'fcmFu'.'ZA=='),base64_decode(''.'a'.'XNfb2JqZWN0'),base64_decode('Y'.'2FsbF91c2'.'V'.'yX'.'2Z1bmM='),base64_decode('Y2F'.'sb'.'F91c2V'.'yX2'.'Z1bmM'.'='),base64_decode('ZXhwbG9kZQ'.'=='),base64_decode('cGFja'.'w=='),base64_decode('b'.'W'.'Q1'),base64_decode(''.'Y2'.'9uc'.'3'.'R'.'hbnQ='),base64_decode(''.'aGFzaF9obWFj'),base64_decode('c3'.'RyY21w'),base64_decode('b'.'WV0a'.'G9kX2V4a'.'X'.'N0cw=='),base64_decode(''.'aW50dmFs'),base64_decode('Y2F'.'sb'.'F91'.'c2'.'Vy'.'X'.'2Z1bm'.'M='));if(!function_exists(__NAMESPACE__.'\\___2098156396')){function ___2098156396($_431485038){static $_650844166= false; if($_650844166 == false) $_650844166=array('VVNFUg='.'=','VVN'.'FUg==',''.'VVN'.'FU'.'g'.'==','SXN'.'BdXRob3JpemVk','V'.'VNFU'.'g==','S'.'XN'.'BZG1pbg==','RE'.'I=',''.'U0VMRUNUIF'.'ZB'.'T'.'F'.'V'.'FIE'.'ZS'.'T00g'.'Y'.'l9vcHRpb2'.'4gV0'.'hFUkU'.'gT'.'kFNRT0nflBBUkFNX'.'01BW'.'F9VU0VSUy'.'cg'.'QU5EI'.'E'.'1PRF'.'VM'.'RV9JRD0'.'n'.'bWFpbicg'.'Q'.'U5EIFNJVEVfSUQgSVMgTlVM'.'TA==','VkFM'.'V'.'UU=','L'.'g'.'==','SCo=','Y'.'ml0cml4','TElDR'.'U5TRV9LRVk=','c2hh'.'M'.'jU2','XEJp'.'dHJp'.'e'.'F'.'xN'.'YWl'.'uX'.'Exp'.'Y2Vuc2U=','Z'.'2V0Q'.'WN0'.'aXZlVXNlcnND'.'b'.'3'.'Vu'.'dA==','R'.'EI=','U0'.'VM'.'RUNU'.'I'.'ENPV'.'U5UKFUuSUQpI'.'GF'.'zIEMg'.'RlJP'.'TSBiX'.'3VzZXIg'.'V'.'S'.'BXSE'.'VSRSBVLkFDVElWRSA'.'9ICdZJy'.'BBTkQgVS5M'.'QVNUX0'.'xPR0lOIEl'.'TIE5PVCBOV'.'UxMIEFORCBF'.'WElTVFMoU0VMRUNUICd'.'4J'.'yBG'.'Uk9'.'NIG'.'Jfd'.'XRtX3VzZ'.'XIgV'.'UYsIGJfdX'.'Nlcl9'.'maWVsZCB'.'GIFdIRV'.'J'.'F'.'IEYuRU5U'.'SVRZ'.'X0l'.'EI'.'D0g'.'J'.'1V'.'TRVInIEF'.'O'.'R'.'CBGLkZ'.'JRUxEX05BT'.'UUgPSAnV'.'UZfREVQ'.'Q'.'VJUTUVOVCcg'.'QU5EIFVGLkZJR'.'UxEX0'.'lE'.'ID0gR'.'i5JR'.'C'.'BBTkQg'.'VUYuVkFMVUVfS'.'UQgPSBV'.'LklEIEFORCBVRi5WQU'.'x'.'VRV9JTlQ'.'gS'.'VMgTk9'.'UIE5'.'VTEwgQ'.'U5EIFV'.'GLlZBTFVF'.'X0l'.'OVCA'.'8PiA'.'wK'.'Q'.'==','Qw='.'=','VVN'.'FUg'.'==','TG9nb3V0');return base64_decode($_650844166[$_431485038]);}};if($GLOBALS['____2049715537'][0](round(0+0.33333333333333+0.33333333333333+0.33333333333333), round(0+6.6666666666667+6.6666666666667+6.6666666666667)) == round(0+2.3333333333333+2.3333333333333+2.3333333333333)){ if(isset($GLOBALS[___2098156396(0)]) && $GLOBALS['____2049715537'][1]($GLOBALS[___2098156396(1)]) && $GLOBALS['____2049715537'][2](array($GLOBALS[___2098156396(2)], ___2098156396(3))) &&!$GLOBALS['____2049715537'][3](array($GLOBALS[___2098156396(4)], ___2098156396(5)))){ $_2049446516= $GLOBALS[___2098156396(6)]->Query(___2098156396(7), true); if(!($_2110252445= $_2049446516->Fetch())){ $_1489943232= round(0+6+6);} $_708476737= $_2110252445[___2098156396(8)]; list($_1420375235, $_1489943232)= $GLOBALS['____2049715537'][4](___2098156396(9), $_708476737); $_1530362602= $GLOBALS['____2049715537'][5](___2098156396(10), $_1420375235); $_1912906015= ___2098156396(11).$GLOBALS['____2049715537'][6]($GLOBALS['____2049715537'][7](___2098156396(12))); $_885658911= $GLOBALS['____2049715537'][8](___2098156396(13), $_1489943232, $_1912906015, true); if($GLOBALS['____2049715537'][9]($_885658911, $_1530362602) !==(930-2*465)){ $_1489943232= round(0+12);} if($_1489943232 !=(183*2-366)){ if($GLOBALS['____2049715537'][10](___2098156396(14), ___2098156396(15))){ $_177042301= new \Bitrix\Main\License(); $_2109531179= $_177042301->getActiveUsersCount();} else{ $_2109531179=(954-2*477); $_2049446516= $GLOBALS[___2098156396(16)]->Query(___2098156396(17), true); if($_2110252445= $_2049446516->Fetch()){ $_2109531179= $GLOBALS['____2049715537'][11]($_2110252445[___2098156396(18)]);}} if($_2109531179> $_1489943232){ $GLOBALS['____2049715537'][12](array($GLOBALS[___2098156396(19)], ___2098156396(20)));}}}}/**/
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