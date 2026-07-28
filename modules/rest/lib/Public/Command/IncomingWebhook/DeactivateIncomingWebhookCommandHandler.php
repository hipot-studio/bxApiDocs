<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;

class DeactivateIncomingWebhookCommandHandler
{
	public function __construct(
		private IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ObjectNotFoundException
	 * @throws PersistenceException
	 */
	public function __invoke(DeactivateIncomingWebhookCommand $command): IncomingWebhook
	{
		$webhook = $this->repository->getById($command->passwordId);
		if ($webhook === null)
		{
			throw new IncomingWebhookNotFoundException();
		}

		if ($webhook->getUserId() !== $command->userId)
		{
			throw new AccessDeniedException('Incoming webhook does not belong to the user');
		}

		if ($webhook->isActive())
		{
			$webhook->setActive(false);
			$this->repository->save($webhook);
		}

		return $webhook;
	}
}
