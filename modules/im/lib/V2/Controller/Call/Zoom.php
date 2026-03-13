<?php

namespace Bitrix\Im\V2\Controller\Call;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Main\Engine\CurrentUser;


class Zoom extends BaseController
{
	/**
	 * @restMethod im.v2.Call.Zoom.create
	 */
	public function createAction(Chat $chat, CurrentUser $user): ?array
	{
		if (!\Bitrix\Im\Integration\Socialservices\Zoom::isActive())
		{
			$this->addError(new Error('ZOOM_ACTIVE_ERROR'));

			return null;
		}

		if (!\Bitrix\Im\Integration\Socialservices\Zoom::isAvailable())
		{
			$this->addError(new Error('ZOOM_AVAILABLE_ERROR'));

			return null;
		}

		if (!\Bitrix\Im\Integration\Socialservices\Zoom::isConnected($user->getId()))
		{
			$this->addError(new Error('ZOOM_CONNECTED_ERROR'));

			return null;
		}

		if (!$chat->canDo(Action::Send))
		{
			$this->addError(new Chat\ChatError(Chat\ChatError::ACCESS_DENIED));

			return null;
		}

		$zoom = new \Bitrix\Im\Integration\Socialservices\Zoom($user->getId(), $chat->getDialogId());
		$link = $zoom->getImChatConferenceUrl();

		if (empty($link))
		{
			$this->addError(new Error('ZOOM_CREATE_ERROR'));

			return null;
		}

		$messageFields = $zoom->getRichMessageFields($chat->getDialogId(), $link, $user->getId());
		$messageFields['PARAMS']['COMPONENT_ID'] = 'ZoomInviteMessage';
		$messageFields['PARAMS']['COMPONENT_PARAMS'] = ['LINK' => $link];

		$messageId = \CIMMessenger::Add($messageFields);

		if (!$messageId)
		{
			$this->addError(new MessageError(MessageError::SENDING_FAILED));

			return null;
		}

		return [
			'link' => $link,
			'messageId' => $messageId,
		];
	}
}