<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Push;

use Bitrix\Calendar\Sync\Connection\Connection;
use Bitrix\Calendar\Synchronization\Internal\Entity\Push\Push;
use Bitrix\Calendar\Synchronization\Internal\Entity\Push\PushId;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\ApiException;
use Bitrix\Calendar\Synchronization\Internal\Exception\DtoValidationException;
use Bitrix\Calendar\Synchronization\Internal\Exception\PushException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Repository\RepositoryReadException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\AccessDeniedException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\AuthorizationException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\BadRequestException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\Google\RateLimitExceededException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotAuthorizedException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotFoundException;
use Bitrix\Calendar\Synchronization\Internal\Repository\PushRepository;
use Bitrix\Calendar\Synchronization\Internal\Repository\SectionConnectionRepository;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\GoogleGatewayProvider;
use Bitrix\Calendar\Synchronization\Internal\Service\Push\AbstractPushManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Repository\Exception\PersistenceException;

class PushManager extends AbstractPushManager
{
	public function __construct(
		PushRepository $pushRepository,
		SectionConnectionRepository $sectionConnectionRepository,
		protected readonly GoogleGatewayProvider $gatewayProvider,
	)
	{
		parent::__construct($pushRepository, $sectionConnectionRepository);
	}

	/**
	 * @throws ArgumentException
	 * @throws RepositoryReadException
	 * @throws PushException
	 * @throws PersistenceException
	 * @throws ObjectException
	 * @throws NotAuthorizedException
	 * @throws AuthorizationException
	 */
	public function subscribeConnection(Connection $connection): void
	{
		$push = $this->pushRepository->getByConnectionId($connection->getId());

		if ($push && !$push->isExpired())
		{
			// Google has no renew method
			return;
		}

		$userId = $connection->getOwner()?->getId();

		if (!$userId)
		{
			return;
		}

		$gateway = $this->gatewayProvider->getPushGateway($connection->getOwner()->getId());

		if (!$gateway)
		{
			return;
		}

		try
		{
			$response = $gateway->addConnectionPush($connection);
		}
		catch (RateLimitExceededException $e)
		{
			throw new PushException(
				sprintf('Google rate limit exceeded: "%s"', $e->getMessage()),
				$e->getCode(),
				$e,
			);
		}
		catch (NotAuthorizedException $e)
		{
			throw $e;
		}
		catch (BadRequestException|AccessDeniedException|NotFoundException $e)
		{
			throw new PushException(
				sprintf('Google API exception on subscribe to connection push: "%s"', $e->getMessage()),
				$e->getCode(),
				$e,
				isRecoverable: false,
			);
		}
		catch (ApiException|DtoValidationException $e)
		{
			throw new PushException(
				sprintf('Google API exception on subscribe to connection push: "%s"', $e->getMessage()),
				$e->getCode(),
				$e,
			);
		}

		if ($push)
		{
			$this->updatePushData($push, $response);

			$this->pushRepository->update($push);
		}
		else
		{
			$push = new Push();

			$push->setId(PushId::buildForConnection($connection->getId()));

			$this->updatePushData($push, $response);

			$this->pushRepository->add($push);
		}
	}

	/**
	 * @throws ArgumentException
	 * @throws PushException
	 * @throws NotAuthorizedException
	 * @throws AuthorizationException
	 */
	public function subscribeSection(SectionConnection $sectionConnection): void
	{
		if (!$sectionConnection->getSection())
		{
			return;
		}

		$userId = $sectionConnection->getSection()->getOwner()?->getId();

		if (!$userId)
		{
			return;
		}

		$push = $this->pushRepository->getBySectionConnectionId($sectionConnection->getId());

		if ($push && !$push->isExpired())
		{
			// Google has no renew method
			return;
		}

		$gateway = $this->gatewayProvider->getPushGateway($userId);

		if (!$gateway)
		{
			return;
		}

		try
		{
			$response = $gateway->addSectionPush($sectionConnection);
		}
		catch (RateLimitExceededException $e)
		{
			throw new PushException(
				sprintf('Google rate limit exceeded: "%s"', $e->getMessage()),
				$e->getCode(),
				$e,
			);
		}
		catch (NotAuthorizedException $e)
		{
			throw $e;
		}
		catch (BadRequestException|AccessDeniedException|NotFoundException $e)
		{
			throw new PushException(
				sprintf('Google API exception on subscribe to connection push: "%s"', $e->getMessage()),
				$e->getCode(),
				$e,
				isRecoverable: false,
			);
		}
		catch (ApiException|DtoValidationException $e)
		{
			throw new PushException(
				sprintf('Google API exception on subscribe to connection push: "%s"', $e->getMessage()),
				$e->getCode(),
				$e,
			);
		}

		try
		{
			$this->addSectionPush($response, $push, $sectionConnection);
		}
		catch (ArgumentException|ObjectException|PersistenceException $e)
		{
			throw new PushException(
				sprintf('Unable to save push: "%s" (%s)', $e->getMessage(), $e->getCode()),
				$e->getCode(),
				$e,
			);
		}
	}
}
