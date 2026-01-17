<?php

class CSocServUtil
{
	const OAUTH_PACK_PARAM = "oauth_proxy_params";
	private static $oAuthParams = array("redirect_uri", "client_id", "scope", "response_type", "state");

	public static function GetCurUrl($addParam="", $removeParam=false, $checkOAuthProxy=true)
	{
		global $APPLICATION;

		$arRemove = array("logout", "auth_service_error", "auth_service_id", "MUL_MODE");

		if($removeParam !== false)
		{
			$arRemove = array_merge($arRemove, $removeParam);
		}

		if($checkOAuthProxy !== false)
		{
			$proxyString = "";
			foreach(self::$oAuthParams as $param)
			{
				if (isset($_GET[$param]) && (is_string($_GET[$param]) || is_numeric($_GET[$param])))
				{
					$arRemove[] = $param;
					$proxyString .= ($proxyString == "" ? "" : "&").urlencode($param)."=".urlencode($_GET[$param]);
				}
			}

			if($proxyString != "")
			{
				$addParam .= ($addParam == "" ? "" : "&").self::packOAuthProxyString($proxyString);
			}
		}
		return \CHTTP::URN2URI($APPLICATION->GetCurPageParam($addParam, $arRemove));
	}

	/**
	 * @deprecated Use \CHTTP::URN2URI instead
	 */
	public static function ServerName($forceHttps = false)
	{
		$request = \Bitrix\Main\Context::getCurrent()->getRequest();

		$protocol = ($forceHttps || $request->isHttps()) ? "https" : "http";
		$serverName = $request->getHttpHost();

		// :-(
		if($protocol == "https")
		{
			$serverName = str_replace(":443", "", $serverName);
		}

		return $protocol.'://'.$serverName;
	}

	public static function packOAuthProxyString($proxyString)
	{
		return self::OAUTH_PACK_PARAM."=".urlencode(base64_encode($proxyString));
	}

	public static function getOAuthProxyString()
	{
		return isset($_REQUEST[self::OAUTH_PACK_PARAM]) ? self::OAUTH_PACK_PARAM."=".urlencode($_REQUEST[self::OAUTH_PACK_PARAM]) : '';
	}

	public static function checkOAuthProxyParams()
	{
		if(isset($_REQUEST[self::OAUTH_PACK_PARAM]) && $_REQUEST[self::OAUTH_PACK_PARAM] <> '')
		{
			$proxyString = base64_decode($_REQUEST[self::OAUTH_PACK_PARAM]);
			if($proxyString <> '')
			{
				$arVars = array();
				parse_str($proxyString, $arVars);
				foreach(self::$oAuthParams as $param)
				{
					if(isset($arVars[$param]))
					{
						$_GET[$param] = $_REQUEST[$param] = $arVars[$param];
					}
				}
			}

			unset($_REQUEST[self::OAUTH_PACK_PARAM]);
			unset($_GET[self::OAUTH_PACK_PARAM]);
		}
	}
}
