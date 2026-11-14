<?php

namespace Bitrix\ImOpenLines\V2\Controller\Session;

use Bitrix\ImOpenLines\V2\Controller\BaseController;
use Bitrix\ImOpenLines\V2\Statistics\SessionStatService;

class Stat extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Session.Stat.get
	 */
	public function getAction(array $sessionId): ?array
	{
		$currentUser = $this->getCurrentUser();
		$userId = (int)$currentUser?->getId();

		$service = new SessionStatService();
		$result = $service->getStat($userId, $sessionId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
