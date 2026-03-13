<?php

namespace Bitrix\Im\Controller;

if (\Bitrix\Main\Loader::includeModule('call'))
{
	/** @deprecated  */
	final class Call extends \Bitrix\Call\Controller\CallManager {}
}

