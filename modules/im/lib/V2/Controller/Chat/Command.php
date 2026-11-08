<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Entity\Command\ChatCommandList;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Rest\RestAdapter;

class Command extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Command.list
	 */
	public function listAction(Chat $chat): ?array
	{
		// CheckEntityAccess lets a non-member read an open chat/channel; the command list
		// is members-only, so require an active relation on top of the read access check.
		// A hidden relation stays after leaving an open chat, so it does not count as membership.
		$selfRelation = $chat->getSelfRelation();
		if ($selfRelation === null || $selfRelation->isHidden() === true)
		{
			$this->addError(new ChatError(ChatError::ACCESS_DENIED));

			return null;
		}

		$commandList = new ChatCommandList($chat);
		$users = UserCollection::initByUserIds($commandList->getBotIds());

		return (new RestAdapter($commandList, $users))->toRestFormat([
			'IDS_AS_KEY' => true,
			'USER_SHORT_FORMAT' => true,
			'WITHOUT_ONLINE' => true,
		]);
	}
}
