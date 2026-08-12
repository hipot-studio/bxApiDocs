<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Enum\Integration\ElementCodeType;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookAttributeCollection;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookExternalAttribute;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Preset\Provider;

/**
 * Base for user-owned incoming webhooks (TYPE=user).
 *
 * {@see CreateIncomingWebhookCommandHandler}
 * {@see ForceCreateIncomingWebhookCommandHandler}
 *
 * System webhooks (TYPE=system) are created outside this hierarchy by
 * {@see \Bitrix\Rest\Internal\Service\IncomingWebhook\SystemIncomingWebhookCreator}.
 */
abstract class AbstractCreateIncomingWebhookCommandHandler
{
	public function __construct(
		protected IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
		protected IntegrationRepository $integrationRepository = new IntegrationRepository(),
		protected SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),
	)
	{
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	protected function requireUser(int $userId): RestUserModel
	{
		$user = RestUserModel::createFromId($userId);
		if ($user->getData() === null)
		{
			throw new ObjectNotFoundException(
				'User with ID ' . $userId . ' not found'
			);
		}

		return $user;
	}

	/**
	 * @param string[] $scopes
	 *
	 * @return string[]
	 *
	 * @throws ArgumentException
	 */
	protected function normalizeScopes(array $scopes): array
	{
		$scopes = PermissionTable::cleanPermissionList($scopes);
		if (empty($scopes))
		{
			throw new ArgumentException('At least one valid scope is required');
		}

		return $scopes;
	}

	protected function createIntegration(
		int $initiatorUserId,
		int $ownerUserId,
		?string $title,
		array $scopes,
		array $attributes,
		?string $comment = null,
	): IncomingWebhook {
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$integrationResult = Provider::saveIntegration(
				[
					'TITLE' => $title,
					'SCOPE' => $scopes,
				],
				ElementCodeType::IN_WEBHOOK->value,
				null,
				$initiatorUserId
			);

			if (($integrationResult['status'] ?? false) !== true || empty($integrationResult['ID']))
			{
				throw new SystemException(
					implode('; ', $integrationResult['errors'] ?? ['Failed to create incoming webhook integration'])
				);
			}

			$integration = $this->integrationRepository->getById($integrationResult['ID']);
			if ($integration === null)
			{
				throw new ObjectNotFoundException('Integration for incoming webhook was not found after creation');
			}

			$savedWebhookId = $integration->getPasswordId();
			if ($savedWebhookId === null)
			{
				throw new ObjectNotFoundException('Incoming webhook password was not found after creation');
			}

			$savedWebhook = $this->repository->getById($savedWebhookId);
			if ($savedWebhook === null)
			{
				throw new ObjectNotFoundException('Incoming webhook was not found after creation');
			}

			$needsSave = false;
			$hasExternalAttributes = !empty($attributes);

			if ($comment !== null && $comment !== '')
			{
				$savedWebhook->setComment($comment);
				$needsSave = true;
			}

			if ($ownerUserId !== $initiatorUserId)
			{
				$integration->setUserId($ownerUserId);
				$this->integrationRepository->save($integration);

				$savedWebhook->setUserId($ownerUserId);
				if (!$hasExternalAttributes && !$needsSave)
				{
					try
					{
						$updateResult = PasswordTable::update($savedWebhookId, ['USER_ID' => $ownerUserId]);
					}
					catch (\Exception $exception)
					{
						throw new PersistenceException($exception->getMessage(), previous: $exception);
					}

					if (!$updateResult->isSuccess())
					{
						throw new PersistenceException('Unable to change incoming webhook owner');
					}
				}
			}

			if ($hasExternalAttributes)
			{
				$externalAttributes = new IncomingWebhookAttributeCollection();
				foreach ($attributes as $code => $value)
				{
					$externalAttributes->add(new IncomingWebhookExternalAttribute(null, $code, $value));
				}

				$savedWebhook->setExternalAttributes($externalAttributes);
				$needsSave = true;
			}

			if ($needsSave)
			{
				$this->repository->save($savedWebhook);
				$savedWebhook = $this->repository->getById($savedWebhookId) ?? $savedWebhook;
			}

			$connection->commitTransaction();

			$this->securityAuditLogger->logWebhookCreated(
				actingUserId: $initiatorUserId,
				webhookId: (int)$savedWebhook->getId(),
				ownerUserId: $ownerUserId,
				scopes: $scopes,
			);

			return $savedWebhook;
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}
}
