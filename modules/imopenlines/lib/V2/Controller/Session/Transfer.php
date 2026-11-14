<?php

namespace Bitrix\ImOpenLines\V2\Controller\Session;

use Bitrix\ImOpenLines\V2\Controller\BaseController;
use Bitrix\ImOpenLines\V2\Statistics\TransferListService;

class Transfer extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Session.Transfer.list
	 */
	public function listAction(array $sessionId): ?array
	{
		$currentUser = $this->getCurrentUser();
		$userId = (int)$currentUser?->getId();

		$service = new TransferListService();
		$result = $service->getList($userId, $sessionId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
