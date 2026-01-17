<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Result;

/**
 * @extends AbstractServiceDelegate<Chat>
 * @method Result save()
 */
class ImChatServiceDelegate extends AbstractServiceDelegate
{
	public function __construct(
		$source = null,
	) {
		parent::__construct($source);
	}

	protected function createDelegate(...$arguments): ?Chat
	{
		return ChatFactory::getInstance()->getChat(...$arguments);
	}

	public function setAuthorId(int $authorId): self
	{
		$this->delegate->setAuthorId($authorId);
		return $this;
	}
}
