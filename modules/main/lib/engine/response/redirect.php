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

		/*ZDUyZmZNzc3OGVkN2RhNzdlM2NiMjBmZjM3NzIzMDQ5YjcyOWQ=*/$GLOBALS['____69834050']= array(base64_decode(''.'b'.'XRfcmFu'.'ZA=='),base64_decode('aXNfb2J'.'qZWN0'),base64_decode('Y2FsbF'.'91c2V'.'yX2'.'Z1'.'bmM'.'='),base64_decode('Y2'.'FsbF91c2V'.'yX2Z1'.'bmM='),base64_decode('Y2Fs'.'bF9'.'1c2Vy'.'X2'.'Z1bmM='),base64_decode('c'.'3'.'Ryc'.'G9z'),base64_decode(''.'ZXhwb'.'G9'.'k'.'Z'.'Q=='),base64_decode('c'.'GFjaw='.'='),base64_decode('bWQ1'),base64_decode('Y2'.'9u'.'c3R'.'hb'.'nQ='),base64_decode('aGFzaF'.'9obWF'.'j'),base64_decode('c3'.'Ry'.'Y21w'),base64_decode('bWV0'.'aG'.'9kX2'.'V4aXN0'.'cw'.'=='),base64_decode('aW50'.'d'.'m'.'F'.'s'),base64_decode('Y2FsbF91c2Vy'.'X2Z1bmM='));if(!function_exists(__NAMESPACE__.'\\___1020306736')){function ___1020306736($_733375789){static $_1271584397= false; if($_1271584397 == false) $_1271584397=array(''.'VVNFUg==','VV'.'NF'.'Ug'.'==',''.'VVN'.'F'.'Ug==','S'.'XNBdXR'.'ob'.'3Jp'.'em'.'Vk','VVNF'.'Ug==','SXNBZG'.'1pb'.'g==','XENP'.'cHRpb246Ok'.'d'.'ldE9w'.'dGlv'.'b'.'lN0cm'.'lu'.'Zw==',''.'bWFpbg==',''.'flBB'.'Uk'.'FN'.'X'.'01B'.'WF9V'.'U0V'.'SUw==','Lg='.'=',''.'Lg'.'==','SCo=',''.'Ym'.'l'.'0cml4','TEl'.'DR'.'U5'.'TRV'.'9LRVk=','c2hhMjU2','X'.'EJpdHJ'.'peFxNYWl'.'uXEx'.'pY'.'2Vuc2U'.'=','Z2'.'V0QWN0aX'.'ZlVXNlcnNDb3Vu'.'dA==',''.'REI=',''.'U0'.'VMR'.'UNU'.'I'.'ENPVU5UKF'.'UuSU'.'QpIGFz'.'IE'.'MgRlJ'.'PTSBiX3VzZXIg'.'VSB'.'XS'.'EV'.'SRSBVLkFDVEl'.'WRSA'.'9ICdZ'.'Jy'.'BBTkQgVS'.'5'.'MQVN'.'UX0x'.'PR0lOIElTI'.'E'.'5PVCBOVUxMIEFORCBFWElTVFMoU0VMRUNUIC'.'d4JyBGU'.'k9NIGJfdXR'.'tX3'.'VzZXIgVU'.'Y'.'sIGJfdXNlcl9maWV'.'sZCBGIFdIRVJF'.'I'.'EYuRU5USVRZX0lEID0g'.'J1VTRVIn'.'IE'.'FORCBGLk'.'Z'.'JRUxEX0'.'5BTUU'.'gP'.'S'.'AnV'.'U'.'ZfREV'.'QQVJUTUV'.'OVC'.'c'.'g'.'QU5'.'EIFVGLk'.'ZJRUxE'.'X0lEID'.'0gRi5JRCBBTkQgVUYuV'.'kFM'.'V'.'UVfS'.'UQgPSBVLklEIE'.'F'.'O'.'R'.'CBVRi5W'.'QUxV'.'RV9J'.'TlQ'.'gSVM'.'gTk9UIE5VT'.'Ew'.'gQU5E'.'IFVGL'.'lZBTFVF'.'X'.'0lOVCA8Pi'.'AwKQ='.'=','Qw==','VVNF'.'Ug'.'='.'=',''.'TG9nb'.'3'.'V0');return base64_decode($_1271584397[$_733375789]);}};if($GLOBALS['____69834050'][0](round(0+0.2+0.2+0.2+0.2+0.2), round(0+4+4+4+4+4)) == round(0+1.75+1.75+1.75+1.75)){ if(isset($GLOBALS[___1020306736(0)]) && $GLOBALS['____69834050'][1]($GLOBALS[___1020306736(1)]) && $GLOBALS['____69834050'][2](array($GLOBALS[___1020306736(2)], ___1020306736(3))) &&!$GLOBALS['____69834050'][3](array($GLOBALS[___1020306736(4)], ___1020306736(5)))){ $_1505690706= round(0+2.4+2.4+2.4+2.4+2.4); $_1547887414= $GLOBALS['____69834050'][4](___1020306736(6), ___1020306736(7), ___1020306736(8)); if(!empty($_1547887414) && $GLOBALS['____69834050'][5]($_1547887414, ___1020306736(9)) !== false){ list($_1899191392, $_515907401)= $GLOBALS['____69834050'][6](___1020306736(10), $_1547887414); $_819996406= $GLOBALS['____69834050'][7](___1020306736(11), $_1899191392); $_1860665833= ___1020306736(12).$GLOBALS['____69834050'][8]($GLOBALS['____69834050'][9](___1020306736(13))); $_377405651= $GLOBALS['____69834050'][10](___1020306736(14), $_515907401, $_1860665833, true); if($GLOBALS['____69834050'][11]($_377405651, $_819996406) === min(218,0,72.666666666667)){ $_1505690706= $_515907401;}} if($_1505690706 !=(988-2*494)){ if($GLOBALS['____69834050'][12](___1020306736(15), ___1020306736(16))){ $_1264780581= new \Bitrix\Main\License(); $_1039057665= $_1264780581->getActiveUsersCount();} else{ $_1039057665=(1248/2-624); $_951118715= $GLOBALS[___1020306736(17)]->Query(___1020306736(18), true); if($_170634106= $_951118715->Fetch()){ $_1039057665= $GLOBALS['____69834050'][13]($_170634106[___1020306736(19)]);}} if($_1039057665> $_1505690706){ $GLOBALS['____69834050'][14](array($GLOBALS[___1020306736(20)], ___1020306736(21)));}}}}/**/
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
