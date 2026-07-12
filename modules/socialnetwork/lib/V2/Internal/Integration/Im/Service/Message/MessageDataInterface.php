<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

interface MessageDataInterface
{
	public function getText(): string;
	public function getContextUserId(): int;
	public function getAuthorId(): int;
}
