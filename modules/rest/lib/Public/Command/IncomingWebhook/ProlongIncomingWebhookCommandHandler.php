<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\CannotProlongIncomingWebhookException;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\CannotReduceIncomingWebhookExpirationException;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Service\IncomingWebhook\ExpirationChecker;

class ProlongIncomingWebhookCommandHandler
{
	public function __construct(
		private IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
		private ExpirationChecker $expirationChecker = new ExpirationChecker(),
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ObjectNotFoundException
	 * @throws CannotProlongIncomingWebhookException
	 * @throws CannotReduceIncomingWebhookExpirationException
	 * @throws \Bitrix\Main\Repository\Exception\PersistenceException
	 */
	public function __invoke(ProlongIncomingWebhookCommand $command): IncomingWebhook
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new ObjectNotFoundException(
				'User with ID ' . $command->userId . ' not found',
			);
		}

		$webhook = $this->findWebhook($command->webHookPassword);

		$accessChecker = new WebhookAccessChecker($command->userId);
		if (!$accessChecker->canManageIncomingWebhook($webhook))
		{
			throw new AccessDeniedException(
				'User does not have rights to edit incoming webhook',
			);
		}

		$this->assertProlongable($webhook, $command->dateExpire);

		$isUpdated = $this->repository->prolong(
			(int)$webhook->getId(),
			$command->dateExpire,
			$this->expirationChecker->getCurrentDate(),
		);

		if (!$isUpdated)
		{
			// Either the webhook changed between the read and the write, or it already holds the requested date.
			$this->assertProlongable($this->findWebhook($command->webHookPassword), $command->dateExpire);
		}

		$webhook
			->setDateExpire($command->dateExpire)
			->setActive(true)
		;

		return $webhook;
	}

	/**
	 * @throws IncomingWebhookNotFoundException
	 */
	private function findWebhook(string $webHookPassword): IncomingWebhook
	{
		$webhook = $this->repository->getByWebhookId($webHookPassword);
		if ($webhook === null)
		{
			throw new IncomingWebhookNotFoundException();
		}

		return $webhook;
	}

	/**
	 * @throws CannotProlongIncomingWebhookException
	 * @throws CannotReduceIncomingWebhookExpirationException
	 */
	private function assertProlongable(IncomingWebhook $webhook, Date $dateExpire): void
	{
		$currentDateExpire = $webhook->getDateExpire();
		$isExpired = $this->expirationChecker->isExpired($webhook);

		if ($currentDateExpire === null || (!$webhook->isActive() && !$isExpired))
		{
			throw new CannotProlongIncomingWebhookException();
		}

		if ($dateExpire < $currentDateExpire)
		{
			throw new CannotReduceIncomingWebhookExpirationException();
		}
	}
}
