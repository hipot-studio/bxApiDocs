<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\VibecodePort;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceSyncCoordinator;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;

class Balance extends Base
{
	public function __construct(
		?Request $request = null,
		private readonly ?BalanceSyncCoordinator $balanceSyncCoordinator = null,
	)
	{
		parent::__construct($request);
	}

	public function changedAction(int $version): Response\AjaxJson
	{
		if ($version <= 0)
		{
			$this->addError(new Error('Balance version must be positive', 'BALANCE_VERSION_INVALID'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		$target = ServiceLocator::getInstance()->get(UserEventTargetProvider::class)
			->resolve((string)$this->incomingServerIss)
		;
		if ($target === null)
		{
			$this->addError(new Error('Pairing not found', 'PAIRING_NOT_FOUND'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		$coordinator = $this->balanceSyncCoordinator ?? new BalanceSyncCoordinator();
		if (!$coordinator->request($target, $version))
		{
			$this->addError(new Error('Unable to enqueue balance pull', 'BALANCE_PULL_ENQUEUE_FAILED'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		return Response\AjaxJson::createSuccess();
	}
}
