<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter;

use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Context;
use Bitrix\Main\Errorable;
use Bitrix\Main\Engine\ActionFilter\Access\AccessCheckStrategyInterface;
use Bitrix\Main\Engine\ActionFilter\Access\AccessDeniedEventResult;
use Bitrix\Main\Engine\ActionFilter\Access\ItemIdFromRequestStrategy;
use Bitrix\Main\Engine\Contract\AccessCheckControllerInterface;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\SystemException;

final class AccessCheck extends Base
{
	/**
	 * @param string|\UnitEnum $accessAction Action identifier from ActionDictionary
	 * @param class-string<AccessCheckStrategyInterface>|null $strategyClass
	 * @param array|null $strategyArgs
	 * @param bool $setHttpStatus
	 * @param string|null $errorMessage
	 */
	public function __construct(
		private readonly string|\UnitEnum $accessAction,
		private readonly ?string $strategyClass = null,
		private readonly ?array $strategyArgs = null,
		private readonly bool $setHttpStatus = false,
		private readonly ?string $errorMessage = null,
	)
	{
		parent::__construct();
	}

	/**
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$controller = $this->getAction()->getController();

		if (!($controller instanceof AccessCheckControllerInterface))
		{
			throw new SystemException(
				sprintf(
					'Controller %s must implement %s to use #[ActionAccess] attribute',
					$controller::class,
					AccessCheckControllerInterface::class,
				)
			);
		}

		$accessController = $controller->getAccessController();
		$strategy = $this->createStrategy($accessController);
		$requestData = $controller->getRequest()->getValues();

		$accessAction = match (true) {
			$this->accessAction instanceof \BackedEnum => (string)$this->accessAction->value,
			$this->accessAction instanceof \UnitEnum => $this->accessAction->name,
			default => $this->accessAction,
		};

		$isAllowed = $strategy->check(
			$accessAction,
			$requestData,
		);

		if (!$isAllowed)
		{
			$error = null;
			if ($accessController instanceof Errorable)
			{
				$error = $accessController->getErrors()[0] ?? null;
			}

			$result = AccessDeniedEventResult::create(
				$error,
				$this->errorMessage,
			);

			if ($this->setHttpStatus)
			{
				Context::getCurrent()->getResponse()->setStatus(403);
			}

			$this->addError($result->getAccessError());

			return $result;
		}

		return null;
	}

	/**
	 * @throws SystemException
	 */
	private function createStrategy(AccessibleController $accessController): AccessCheckStrategyInterface
	{
		$strategyClass = $this->strategyClass ?? ItemIdFromRequestStrategy::class;
		$config = $this->strategyArgs ?? [];

		if (!class_exists($strategyClass))
		{
			throw new SystemException(
				sprintf('Access check strategy class %s not found', $strategyClass)
			);
		}

		if (!is_subclass_of($strategyClass, AccessCheckStrategyInterface::class))
		{
			throw new SystemException(
				sprintf(
					'Strategy %s must implement %s',
					$strategyClass,
					AccessCheckStrategyInterface::class,
				)
			);
		}

		return $strategyClass::create($accessController, $config);
	}
}
