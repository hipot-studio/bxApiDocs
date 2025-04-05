<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\Internal;

use \Bitrix\Main;
use \Bitrix\Baas;
use Bitrix\Main\Localization\Loc;

class ConsumeService
{
	private const LOCK_NAME = 'baas_consumption';
	private const LOCK_LIMIT = 15;

	protected Baas\Entity\Service $service;

	public function __construct(
		protected Baas\Repository\ConsumptionRepositoryInterface $consumptionRepository,
	)
	{
	}

	public function __invoke(
		Request\ConsumeServiceRequest $request,
	): Response\ConsumeServiceResult
	{
		$service = $request->service;
		$units = $request->units;
		$force = $request->force;

		$connection = Main\Application::getConnection();
		$connection->lock(self::LOCK_NAME, self::LOCK_LIMIT);

		try
		{
			if (!$service->isAvailable())
			{
				throw new Main\SystemException(Loc::getMessage('B_BAAS_SERVICE_IS_NOT_AVAILABLE'), 'isNotAvailable');
			}
			if ($force !== true && $service->getValue() < $units)
			{
				throw new Main\SystemException(Loc::getMessage('B_BAAS_SERVICE_IS_EXHAUSTED'), 'isExhausted');
			}

			$consumptionId = $this->generateConsumptionId();

			$repoResult = $this->consumptionRepository->consume(
				$service->getCode(),
				$consumptionId,
				$units,
			);

			if (!$repoResult->isSuccess())
			{
				throw new Main\SystemException(implode('', $repoResult->getErrorMessages()));
			}
		}
		catch (Main\SystemException $e)
		{
			throw new Exception\ConsumeException($e->getMessage());
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		$service->getData()?->setCurrentValue($repoResult->currentValue);

		(new Main\Event('baas', 'onServiceBalanceChanged', ['service' => $service]))->send();

		return new Response\ConsumeServiceResult($consumptionId);
	}

	private function generateConsumptionId(): string
	{
		return time() . '_' . mt_rand();
	}
}
