<?php

namespace Bitrix\Call\Rest;

use Bitrix\Im\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\RestException;

if (!Loader::includeModule('rest'))
{
	return;
}

/**
 * @internal
 */
class Handler extends \IRestService
{
	public static function onRestServiceBuildDescription(): array
	{
		return [
			'call' => [
				'call.user.register' => ['callback' => [__CLASS__, 'registerCallUser'], 'options' => ['private' => true]],
				'call.user.update' => ['callback' => [__CLASS__, 'updateCallUser'], 'options' => ['private' => true]],
				'call.user.force.rename' => ['callback' => [__CLASS__, 'forceRenameCallUser'], 'options' => ['private' => true]],
				'call.channel.public.list' => ['callback' => [__CLASS__, 'getCallChannelPublicList'], 'options' => ['private' => true]],

				'call.videoconf.share.change' => ['callback' => [__CLASS__, 'changeVideoconfShare'], 'options' => ['private' => true]],
				'call.videoconf.password.check' => ['callback' => [__CLASS__, 'checkVideoconfPassword'], 'options' => ['private' => true]],
			]
		];
	}

	/**
	 * @restMethod call.user.register
	 * @return array
	 */
	public static function registerCallUser($params, $n, \CRestServer $server)
	{
		global $APPLICATION;

		if ($server->getAuthType() !== Auth::AUTH_TYPE)
		{
			throw new RestException("Access for this method allowed only by call authorization.", "WRONG_AUTH_TYPE", \CRestServer::STATUS_FORBIDDEN);
		}

		$params = array_change_key_case($params, \CASE_UPPER);

		//1. check session info $_SESSION['LIVECHAT']['REGISTER'] - already registered?
		if (
			isset($_SESSION['CALL']['REGISTER'])
			&& $_SESSION['CALL']['REGISTER']
			&&
			!(
				isset($params['USER_HASH'])
				&& trim($params['USER_HASH'])
				&& preg_match("/^[a-fA-F0-9]{32}$/i", $params['USER_HASH'])
			)
		)
		{
			$params['USER_HASH'] = $_SESSION['CALL']['REGISTER']['hash'];
		}

		//2. register user
		$userData = User::register([
			'NAME' => $params['NAME'] ?? '',
			'LAST_NAME' => $params['LAST_NAME'] ?? '',
			'AVATAR' => $params['AVATAR'] ?? '',
			'EMAIL' => $params['EMAIL'] ?? '',
			'PERSONAL_WWW' => $params['WWW'] ?? '',
			'PERSONAL_GENDER' => $params['GENDER'] ?? '',
			'WORK_POSITION' => $params['POSITION'] ?? '',
			'USER_HASH' => $params['USER_HASH'] ?? '',
		]);
		if (!$userData)
		{
			throw new RestException(
				User::getError()->msg,
				User::getError()->code,
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		Loader::includeModule('im');

		$aliasData = \Bitrix\Im\Alias::get($params['ALIAS']);
		if (!$aliasData)
		{
			throw new RestException("Wrong alias.", "WRONG_ALIAS", \CRestServer::STATUS_FORBIDDEN);
		}

		//3. authorize
		Auth::authorizeById($userData['ID'], true, true);

		//4. add to dialog
		$chat = new \CIMChat(0);
		$chat->AddUser(
			chatId: $aliasData['ENTITY_ID'],
			userId: $userData['ID'],
			hideHistory: null,
			skipMessage: true,
			skipRecent: true
		);
		if ($exception = $APPLICATION->GetException())
		{
			if ($exception->GetID() !== 'NOTHING_TO_ADD')
			{
				throw new RestException(
					"You don't have access",
					"WRONG_REQUEST",
					\CRestServer::STATUS_WRONG_REQUEST
				);
			}
		}

		//5. return id and hash
		$result = [
			'id' => (int)$userData['ID'],
			'hash' => $userData['HASH'],
			'created' => $userData['CREATED'],
			'userToken' => \Bitrix\Call\JwtCall::getUserJwt((int)$userData['ID']),
		];

		$_SESSION['CALL']['REGISTER'] = $result;

		//6. send notification to chat owner
		$chatData = \CIMChat::GetChatData(['ID' => $aliasData['ENTITY_ID']]);
		$chatTitle = $chatData['chat'][$aliasData['ENTITY_ID']]['name'];
		$chatOwnerId = $chatData['chat'][$aliasData['ENTITY_ID']]['owner'];

		$publicLink = $aliasData['LINK'];
		\CIMNotify::Add([
			'TO_USER_ID' => $chatOwnerId,
			'NOTIFY_MODULE' => 'im',
			'NOTIFY_EVENT' => 'videconf_new_guest',
			'MESSAGE' => fn (?string $languageId = null) => Loc::getMessage(
					"IM_VIDEOCONF_NEW_GUEST",
					['#CHAT_TITLE#' => $chatTitle],
					$languageId
				) . "[br]" . "<a href='{$publicLink}'>" . Loc::getMessage("IM_VIDEOCONF_JOIN_LINK", null, $languageId) . "</a>",
		]);

		return $result;
	}

	/**
	 * @restMethod call.user.update
	 */
	public static function updateCallUser($params, $n, \CRestServer $server): array
	{
		if ($server->getAuthType() !== Auth::AUTH_TYPE)
		{
			throw new RestException(
				"Access for this method allowed only by call authorization.",
				"WRONG_AUTH_TYPE",
				\CRestServer::STATUS_FORBIDDEN
			);
		}

		$params = array_change_key_case($params, \CASE_UPPER);
		if (empty($params['NAME']))
		{
			throw new RestException("User NAME can't be empty", "USER_NAME_EMPTY", \CRestServer::STATUS_WRONG_REQUEST);
		}

		/** @var \CUser $USER */
		global $USER;
		if ($USER->GetParam("NAME") != $params['NAME'])
		{
			$userManager = new \CUser;
			$userManager->Update($USER->GetID(), [
				'NAME' => $params['NAME']
			]);

			Loader::includeModule('im');

			$relations = \Bitrix\Im\Chat::getRelation($params['CHAT_ID'], ['WITHOUT_COUNTERS' => 'Y']);

			if (Loader::includeModule('pull'))
			{
				\Bitrix\Pull\Event::add(array_keys($relations), [
					'module_id' => 'im',
					'command' => 'callUserNameUpdate',
					'params' => [
						'userId' => $USER->GetID(),
						'name' => $params['NAME']
					],
					'extra' => \Bitrix\Im\Common::getPullExtra()
				]);
			}
		}

		return [
			'id' => $USER->GetID(),
			'hash' => mb_substr($USER->GetParam('XML_ID'), mb_strlen(Auth::AUTH_TYPE) + 1),
			'userToken' => \Bitrix\Call\JwtCall::getUserJwt($USER->GetID()),
		];
	}

	/**
	 * @restMethod call.user.force.rename
	 */
	public static function forceRenameCallUser($params, $n, \CRestServer $server): void
	{
		global $USER;

		$params = array_change_key_case($params, CASE_UPPER);
		$params['CHAT_ID'] = (int)$params['CHAT_ID'];
		$params['USER_ID'] = (int)$params['USER_ID'];

		if ($params['CHAT_ID'] <= 0)
		{
			throw new RestException("Chat ID can't be empty", "CHAT_ID_EMPTY", \CRestServer::STATUS_WRONG_REQUEST);
		}
		if ($params['USER_ID'] <= 0)
		{
			throw new RestException("User ID can't be empty", "USER_ID_EMPTY", \CRestServer::STATUS_WRONG_REQUEST);
		}

		Loader::includeModule('im');

		//check if current user if owner of chat
		$chat = \Bitrix\Im\Model\ChatTable::getRowById($params['CHAT_ID']);
		if (!$chat)
		{
			throw new RestException("Chat was not found", "CHAT_NOT_FOUND", \CRestServer::STATUS_WRONG_REQUEST);
		}
		$owner = (int)$chat['AUTHOR_ID'];
		if ((int)$USER->GetID() !== $owner)
		{
			throw new RestException("You cannot perform this operation", "NO_ACCESS", \CRestServer::STATUS_WRONG_REQUEST);
		}

		//check if renamed user is call auth
		$userToRename = \Bitrix\Im\User::getInstance($params['USER_ID']);
		if (!$userToRename)
		{
			throw new RestException("User was not found", "USER_NOT_FOUND", \CRestServer::STATUS_WRONG_REQUEST);
		}
		$externalAuth = $userToRename->getExternalAuthId();
		if ($externalAuth !== Auth::AUTH_TYPE)
		{
			throw new RestException("You cannot rename this user", "WRONG_USER_AUTH_TYPE", \CRestServer::STATUS_WRONG_REQUEST);
		}

		$userManager = new \CUser;
		$userManager->Update($params['USER_ID'], [
			'NAME' => $params['NAME']
		]);

		$relations = \Bitrix\Im\Chat::getRelation($params['CHAT_ID'], ['WITHOUT_COUNTERS' => 'Y']);

		if (Loader::includeModule('pull'))
		{
			\Bitrix\Pull\Event::add(array_keys($relations), [
				'module_id' => 'im',
				'command' => 'callUserNameUpdate',
				'params' => [
					'userId' => $params['USER_ID'],
					'name' => $params['NAME']
				],
				'extra' => \Bitrix\Im\Common::getPullExtra()
			]);
		}
	}

	/**
	 * @restMethod call.channel.public.list
	 */
	public static function getCallChannelPublicList($params, $n, \CRestServer $server)
	{
		$params = array_change_key_case($params, \CASE_UPPER);

		Loader::includeModule('pull');

		$type = \CPullChannel::TYPE_PRIVATE;
		if ($params['APPLICATION'] == 'Y')
		{
			$clientId = $server->getClientId();
			if (!$clientId)
			{
				throw new RestException("Get application public channel available only for application authorization.", "WRONG_AUTH_TYPE", \CRestServer::STATUS_WRONG_REQUEST);
			}
			$type = $clientId;
		}

		$users = [];
		if (is_string($params['USERS']))
		{
			$params['USERS'] = \CUtil::JsObjectToPhp($params['USERS']);
		}
		if (is_array($params['USERS']))
		{
			foreach ($params['USERS'] as $userId)
			{
				$userId = intval($userId);
				if ($userId > 0)
				{
					$users[$userId] = $userId;
				}
			}
		}

		if (empty($users))
		{
			throw new RestException("A wrong format for the USERS field is passed", "INVALID_FORMAT", \CRestServer::STATUS_WRONG_REQUEST);
		}

		$chatId = (int)$params['CALL_CHAT_ID'];

		if (!$chatId)
		{
			throw new RestException("No chat id", "INVALID_FORMAT", \CRestServer::STATUS_WRONG_REQUEST);
		}

		$configParams = [];
		$configParams['TYPE'] = $type;
		$configParams['USERS'] = $users;
		$configParams['JSON'] = true;

		$config = \Bitrix\Pull\Channel::getPublicIds($configParams);
		if ($config === false)
		{
			throw new RestException("Push & Pull server is not configured", "SERVER_ERROR", \CRestServer::STATUS_INTERNAL);
		}

		return $config;
	}

	/**
	 * @restMethod call.videoconf.share.change
	 */
	public static function changeVideoconfShare($params, $n, \CRestServer $server)
	{
		global $USER;
		$userName = $USER->GetFullName();
		$params = array_change_key_case($params, \CASE_UPPER);

		Loader::includeModule('im');

		if (!\Bitrix\Im\Common::isDialogId($params['DIALOG_ID']))
		{
			throw new RestException("Dialog ID can't be empty", "DIALOG_ID_EMPTY", \CRestServer::STATUS_WRONG_REQUEST);
		}

		//check owner
		$chatId = \Bitrix\Im\Dialog::getChatId($params['DIALOG_ID']);
		$chatData = \Bitrix\Im\Chat::getById($chatId);

		if ($USER->GetID() != $chatData['OWNER'])
		{
			throw new RestException("You don't have access to this chat", "ACCESS_ERROR", \CRestServer::STATUS_WRONG_REQUEST);
		}

		//get chat users and delete guests
		$chatUsers = \Bitrix\Im\Chat::getUsers($chatId);
		$externalTypes = \Bitrix\Main\UserTable::getExternalUserTypes();

		$chat = new \CIMChat($USER->GetId());
		foreach ($chatUsers as $user)
		{
			if (in_array($user['external_auth_id'], $externalTypes, true))
			{
				$chat->DeleteUser($chatId, $user['id']);
			}
		}

		//get alias
		$aliasData = \Bitrix\Im\Alias::getByEntity('VIDEOCONF', $chatId);

		//generate new alias and update
		$newCode = \Bitrix\Im\Alias::generateUnique();
		$updateResult = \Bitrix\Im\Alias::update($aliasData['ID'], [
			'ALIAS' => $newCode,
			'ENTITY_TYPE' => 'VIDEOCONF',
			'ENTITY_ID' => $chatId
		]);

		if (!$updateResult)
		{
			throw new RestException("Can't update alias", "ACCESS_ERROR", \CRestServer::STATUS_WRONG_REQUEST);
		}

		$newLink = \Bitrix\Im\Alias::get($newCode)['LINK'];

		//add message to chat
		$attach = new \CIMMessageParamAttach(null);
		$attach->AddLink(
			[
				"NAME" => $newLink,
				"DESC" => GetMessage("IM_VIDEOCONF_SHARE_UPDATED_LINK", ['#USER_NAME#' => htmlspecialcharsback($userName)]),
				"LINK" => $newLink
			]
		);

		\CIMChat::AddMessage(
			[
				"TO_CHAT_ID" => $chatId,
				"SYSTEM" => 'Y',
				"FROM_USER_ID" => $USER->GetID(),
				"MESSAGE" => GetMessage("IM_VIDEOCONF_LINK_TITLE"),
				"ATTACH" => $attach
			]
		);

		//send pull with changed alias

		$relations = \Bitrix\Im\Chat::getRelation($chatId, ['WITHOUT_COUNTERS' => 'Y']);
		if (Loader::includeModule('pull'))
		{
			\Bitrix\Pull\Event::add(array_keys($relations), [
				'module_id' => 'im',
				'command' => 'videoconfShareUpdate',
				'params' => [
					'newCode' => $newCode,
					'newLink' => $newLink,
					'dialogId' => $params['DIALOG_ID']
				],
				'extra' => \Bitrix\Im\Common::getPullExtra()
			]);
		}

		return true;
	}

	/**
	 * @restMethod call.videoconf.password.check
	 */
	public static function checkVideoconfPassword($params, $n, \CRestServer $server)
	{
		$params = array_change_key_case($params, \CASE_UPPER);

		if (!$params['PASSWORD'])
		{
			throw new RestException("Password can't be empty", "PASSWORD_EMPTY", \CRestServer::STATUS_WRONG_REQUEST);
		}
		if (!$params['ALIAS'])
		{
			throw new RestException("Alias can't be empty", "ALIAS_EMPTY", \CRestServer::STATUS_WRONG_REQUEST);
		}

		$conference = \Bitrix\Call\Conference::getByAlias($params['ALIAS']);
		if ($conference && $conference->getPassword() === $params['PASSWORD'])
		{
			//create cache for current confId and sessId
			$storage = \Bitrix\Main\Application::getInstance()->getLocalSession('conference_check_' . $conference->getId());
			$storage->set('checked', true);

			Loader::includeModule('im');

			//add user to chat
			$currentUserId = \Bitrix\Main\Engine\CurrentUser::get()->getId();
			$isUserInChat = Chat::isUserInChat($conference->getChatId());
			if ($currentUserId && !$isUserInChat)
			{
				$chat = new \CIMChat(0);
				$addingResult = $chat->AddUser(
					chatId: $conference->getChatId(),
					userId: $currentUserId,
					hideHistory: null,
					skipMessage: true,
					skipRecent: true
				);
				if (!$addingResult)
				{
					throw new RestException("Error during adding user to chat", "ADDING_TO_CHAT_ERROR", \CRestServer::STATUS_WRONG_REQUEST);
				}
			}

			return true;
		}

		return false;
	}
}
