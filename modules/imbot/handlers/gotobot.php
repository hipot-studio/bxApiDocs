<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!(\Bitrix\Main\Loader::includeModule('im') && \Bitrix\Main\Loader::includeModule('imbot')))
{
	return false;
}

/**
 * @global \CMain $APPLICATION
 */
if ($APPLICATION instanceof \CMain)
{
	$APPLICATION->RestartBuffer();
}

$botCode = $_GET['bot'] ?? '';
if (!$botCode)
{
	\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus('404 Not Found');
}

$url = \Bitrix\ImBot\Link::getChatUrlWithBot($botCode);
if ($url)
{
	header('Location: ' . $url);
}
else
{
	\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus('404 Not Found');
}
