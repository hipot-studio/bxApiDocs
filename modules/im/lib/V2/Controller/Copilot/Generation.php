<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Copilot;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Copilot\GenerationStopService;
use Bitrix\Im\V2\Controller\BaseController;

class Generation extends BaseController
{
	/**
	 * @restMethod im.v2.Copilot.Generation.stop
	 */
	public function stopAction(Chat $chat, GenerationStopService $service): ?array
	{
		$result = $service->stop($chat, (int)$this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getResult();
	}
}
