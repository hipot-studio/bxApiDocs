<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;

class ProjectCreateActionMessageRich implements ActionMessageInterface
{
	use MessageTrait;

	private const COMPONENT_ID = 'CollabCreationMessage';

	public function __construct(protected int $collabId, protected int $senderId)
	{
	}

	/**
	 * @throws LoaderException
	 */
	public function send(array $recipientIds = [], array $parameters = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		$message = (string)Loc::getMessage('SOCIALNETWORK_CHAT_PROJECT_CREATE_RICH');

		return $this->sendMessage($message, $this->senderId, $this->collabId, self::COMPONENT_ID);
	}
}
