<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\Internal;

use Bitrix\Main;
use Bitrix\Baas;

class RefundService
{
	private const LOCK_NAME = 'baas_refunds';
	private const LOCK_LIMIT = 15;

	public function __construct(protected Baas\Repository\ConsumptionRepositoryInterface $consumptionRepository)
	{
	}

	/**
	 * @param Request\RefundServiceRequest $request
	 * @return Response\RefundServiceResult
	 * @throws Exception\RefundException
	 */
	public function __invoke(Request\RefundServiceRequest $request): Response\RefundServiceResult
	{
		$service = $request->service;
		$consumptionId = $request->consumptionId;

		$connection = Main\Application::getConnection();
		$connection->lock(self::LOCK_NAME, self::LOCK_LIMIT);

		try
		{
			$result = $this->consumptionRepository->refund($service->getCode(), $consumptionId);

			if (!$result->isSuccess())
			{
				throw new Main\SystemException(implode('', $result->getErrorMessages()));
			}
		}
		catch (Main\SystemException $e)
		{
			throw new Exception\RefundException($e->getMessage());
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		$service->getData()?->setCurrentValue($result->currentValue);

		(new Main\Event('baas', 'onServiceBalanceChanged', ['service' => $service]))->send();

		return new Response\RefundServiceResult();
	}
}
