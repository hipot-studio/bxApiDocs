<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Infrastructure\Controller;

use Bitrix\Main\Engine\JsonController;
use Bitrix\Mobile\Internal\Dismiss\Command\DismissCommand;
use Bitrix\Mobile\Internal\Dismiss\Command\DismissCommandHandler;
use Bitrix\Mobile\Internal\Dismiss\Provider\DismissProvider;

final class Dismiss extends JsonController
{
	/**
	 * @restMethod mobile.v2.Dismiss.getList
	 */
	public function getListAction(DismissProvider $provider): array
	{
		return $provider->getDismissedState($this->getUserId());
	}

	/**
	 * @restMethod mobile.v2.Dismiss.dismiss
	 */
	public function dismissAction(string $code, DismissCommandHandler $handler): ?array
	{
		$result = (new DismissCommand($this->getUserId(), $code, $handler))->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
