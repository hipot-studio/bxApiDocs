<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\IncomingWebhook;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;

final class Invalidator
{
	public function __construct(
		private IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
	)
	{
	}

	public function invalidateByPasswordId(int $passwordId): Result
	{
		$result = new Result();

		if ($passwordId <= 0)
		{
			$result->addError(new Error('Invalid webhook password id'));

			return $result;
		}

		try
		{
			$this->repository->deactivateIfExpired($passwordId, new Date());
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}
}
