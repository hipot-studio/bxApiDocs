<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use CIMMessageParamAttach;

interface WithAttachInterface
{
	public function getAttach(): ?CIMMessageParamAttach;
}
