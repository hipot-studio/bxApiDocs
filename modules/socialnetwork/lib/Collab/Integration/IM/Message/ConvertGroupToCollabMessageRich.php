<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Feature;

class ConvertGroupToCollabMessageRich implements ActionMessageInterface
{
	use MessageTrait;

	private const COMPONENT_ID = 'ConvertToCollabMessage';

	protected int $collabId;
	protected int $senderId;

	public function __construct(int $collabId, int $senderId)
	{
		$this->collabId = $collabId;
		$this->senderId = $senderId;
	}

	public function send(array $recipientIds = [], array $parameters = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		$phraseCode = Feature::isNewProjectsOn()
			? 'SOCIALNETWORK_V2_PROJECT_CONVERT_GROUP_TO_COLLAB_RICH'
			: 'SOCIALNETWORK_COLLAB_CONVERT_GROUP_TO_COLLAB_RICH'
		;

		$message = (string)Loc::getMessage($phraseCode);

		return $this->sendMessage(
			message: $message,
			senderId: $this->senderId,
			groupId: $this->collabId,
			componentId: self::COMPONENT_ID,
			silent: self::SILENT_WITHOUT_RECENT,
		);
	}
}
