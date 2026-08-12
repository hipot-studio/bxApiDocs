<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\IncomingWebhook;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookAttributeCollection;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookExternalAttribute;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Preset\EventController;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;

final class SystemIncomingWebhookCreator
{
	public function __construct(
		private readonly IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
		private readonly SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),
	)
	{
	}

	/**
	 * @throws ArgumentException when no valid scope remains after cleaning
	 * @throws ObjectNotFoundException when the user does not exist or the webhook vanishes after creation
	 */
	public function create(
		int $userId,
		?string $title,
		array $scopes,
		array $attributes = [],
		?string $comment = null,
	): IncomingWebhook
	{
		$this->requireUser($userId);
		$scopes = $this->normalizeScopes($scopes);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			EventController::disableEvents();

			$webhook = new IncomingWebhook(
				userId: $userId,
				password: PasswordTable::generatePassword(),
				active: true,
				title: $title,
				comment: ($comment !== '' ? $comment : null),
				dateCreate: new DateTime(),
				scopes: $scopes,
				externalAttributes: $this->buildAttributeCollection($attributes),
				type: WebhookType::System,
			);

			$this->repository->save($webhook);

			$passwordId = $webhook->getId();
			if ($passwordId === null)
			{
				throw new ObjectNotFoundException('System incoming webhook was not found after creation');
			}

			foreach ($scopes as $scope)
			{
				PermissionTable::add([
					'PASSWORD_ID' => $passwordId,
					'PERM' => $scope,
				]);
			}

			$reloaded = $this->repository->getById($passwordId) ?? $webhook;
			EventController::enableEvents();
			$connection->commitTransaction();

			$this->securityAuditLogger->logWebhookCreated(
				actingUserId: $userId,
				webhookId: $passwordId,
				ownerUserId: $userId,
				scopes: $scopes,
				webhookType: WebhookType::System,
			);

			return $reloaded;
		}
		catch (\Throwable $exception)
		{
			EventController::enableEvents();
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	private function requireUser(int $userId): void
	{
		$user = RestUserModel::createFromId($userId);
		if ($user->getData() === null)
		{
			throw new ObjectNotFoundException('User with ID ' . $userId . ' not found');
		}
	}

	private function normalizeScopes(array $scopes): array
	{
		$scopes = PermissionTable::cleanPermissionList($scopes);
		if (empty($scopes))
		{
			throw new ArgumentException('At least one valid scope is required');
		}

		return $scopes;
	}

	private function buildAttributeCollection(array $attributes): IncomingWebhookAttributeCollection
	{
		$collection = new IncomingWebhookAttributeCollection();
		foreach ($attributes as $code => $value)
		{
			$collection->add(new IncomingWebhookExternalAttribute(null, $code, $value));
		}

		return $collection;
	}
}
