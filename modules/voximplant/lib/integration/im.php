<?php

namespace Bitrix\Voximplant\Integration;

use Bitrix\Main\Loader;
use Bitrix\Main\Text\Encoding;
use Bitrix\Voximplant\ConfigTable;
use Bitrix\Main\Localization\Loc;

Loc::loadLanguageFile(__FILE__);

/**
 * Class Im
 * Integration with bitrix im module.
 * @package Bitrix\Voximplant\Integration
 */
class Im
{
	/**
	 * Creates notification for portal admins of telephony events.
	 * @param string|array|null $notification Notification message. May contain BBCode.
	 * @return void.
	 */
	public static function notifyAdmins(string|array|null $notification, array $buttons = array())
	{
		if(!Loader::includeModule('im'))
			return;

		if ($notification === null || $notification === '' || $notification === [])
		{
			return;
		}

		$notification = static::normalizeNotification($notification);

		$admins = array();
		$cursor = \CGroup::GetGroupUserEx(1);
		while($user = $cursor->fetch())
		{
			$admins[] = $user["USER_ID"];
		}

		$messageFields = array(
			"FROM_USER_ID" => 0,
			"NOTIFY_TYPE" => IM_NOTIFY_SYSTEM,
			"NOTIFY_MODULE" => "voximplant",
			"NOTIFY_EVENT" => "notifications",
			//"NOTIFY_TAG" => "TELEPHONY_NOTIFICATION",
			'NOTIFY_TITLE' => $notification['NOTIFY_TITLE'],
			"NOTIFY_MESSAGE" => $notification['NOTIFY_MESSAGE'],
			"NOTIFY_MESSAGE_OUT" => strip_tags($notification['NOTIFY_MESSAGE']),
			"PARAMS" => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $notification['SUBJECT'],
					'PLAIN_TEXT' => $notification['PLAIN_TEXT'],
				],
			],
		);

		$attach = new \CIMMessageParamAttach();
		if(!empty($buttons))
		{
			foreach ($buttons as $button)
			{
				$attach->AddLink(array(
					"NAME" => Encoding::convertEncodingToCurrent($button['TEXT']),
					"LINK" => static::replaceLinkMacros($button['LINK'])
				));
			}

		}
		if (!$attach->IsEmpty())
		{
			$messageFields['ATTACH'] = $attach;
		}

		foreach ($admins as $adminId)
		{
			$message = $messageFields;
			$message['TO_USER_ID'] = $adminId;
			\CIMNotify::Add($message);
		}
	}

	public static function notifyChangeSipRegistrationStatus($notification)
	{
		if(!Loader::includeModule('im'))
		{
			return;
		}

		if ($notification === null || $notification === '' || $notification === [])
		{
			return;
		}

		$notification = static::normalizeNotification($notification);

		$admins = array();
		$cursor = \CGroup::GetGroupUserEx(1);
		while($user = $cursor->fetch())
		{
			$admins[] = $user["USER_ID"];
		}

		$messageFields = array(
			"FROM_USER_ID" => 0,
			"NOTIFY_TYPE" => IM_NOTIFY_SYSTEM,
			"NOTIFY_MODULE" => "voximplant",
			"NOTIFY_EVENT" => "status_notifications",
			//"NOTIFY_TAG" => "TELEPHONY_NOTIFICATION",
			'NOTIFY_TITLE' => $notification['NOTIFY_TITLE'],
			"NOTIFY_MESSAGE" => $notification['NOTIFY_MESSAGE'],
			"NOTIFY_MESSAGE_OUT" => strip_tags($notification['NOTIFY_MESSAGE']),
			"PARAMS" => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $notification['SUBJECT'],
					'PLAIN_TEXT' => $notification['PLAIN_TEXT'],
				],
			],
		);

		foreach ($admins as $adminId)
		{
			$message = $messageFields;
			$message['TO_USER_ID'] = $adminId;
			\CIMNotify::Add($message);
		}
	}

	private static function normalizeNotification(string|array $notification): array
	{
		if (is_string($notification))
		{
			$notification = [
				'NOTIFY_MESSAGE' => $notification,
			];
		}

		$notification['NOTIFY_MESSAGE'] = Encoding::convertEncodingToCurrent($notification['NOTIFY_MESSAGE']);
		if (isset($notification['PLAIN_TEXT']))
		{
			$notification['PLAIN_TEXT'] = Encoding::convertEncodingToCurrent($notification['PLAIN_TEXT']);
		}
		else
		{
			$notification['PLAIN_TEXT'] = \Bitrix\Im\Text::removeBbCodes($notification['NOTIFY_MESSAGE']);
		}
		if (isset($notification['NOTIFY_TITLE']))
		{
			$notification['NOTIFY_TITLE'] = Encoding::convertEncodingToCurrent($notification['NOTIFY_TITLE']);
		}
		else
		{
			$notification['NOTIFY_TITLE'] = Loc::getMessage("VOXIMPLANT_NOTIFICATION_TITLE");
		}
		if (isset($notification['SUBJECT']))
		{
			$notification['SUBJECT'] = Encoding::convertEncodingToCurrent($notification['SUBJECT']);
		}
		else
		{
			$notification['SUBJECT'] = Loc::getMessage("VOXIMPLANT_NOTIFICATION_TITLE");
		}

		return $notification;
	}

	/**
	 * Wrapper around CIMContactList::GetUserData.
	 * @param array $filter Options to pass to the CIMContactList::GetUserData.
	 * @return array
	 */
	public static function getUserData(array $filter)
	{
		if (Loader::IncludeModule('im'))
		{
			return \CIMContactList::GetUserData($filter);
		}
		else
		{
			return array();
		}
	}

	public static function convertCrmEntityToAttach($typeName, $entityId)
	{
		if(!Loader::includeModule('im'))
			return null;

		$entityData = \CVoxImplantCrmHelper::getEntityFields($typeName, $entityId);

		if(!$entityData)
			return null;

		$result = new \CIMMessageParamAttach();
		$result->AddLink(array(
			"NAME" => $entityData["DESCRIPTION"].": ".$entityData["NAME"],
			"DESC" => "...",
			"LINK" => $entityData["SHOW_URL"]
		));
		return $result;
	}

	protected static function replaceLinkMacros($link)
	{
		$replacements = array(
			'#BALANCE_TOP_UP#' =>  \CVoxImplantMain::GetRedirectToBuyLink()
		);

		if(mb_strpos($link, '#BASE_NUMBER_EDIT#') !== false)
		{
			$row = ConfigTable::getList(array(
				'select' => array('ID'),
				'filter' => array(
					'SEARCH_ID' =>  \CVoxImplantConfig::LINK_BASE_NUMBER
				)
			))->fetch();

			if($row != false)
			{
				$replacements['#BASE_NUMBER_EDIT#'] = \CVoxImplantMain::GetPublicFolder().'edit.php?ID='.$row['ID'];
			}
		}

		foreach($replacements as $search => $replacement)
		{
			$link = str_replace($search, $replacement, $link);
		}

		return $link;
	}
}