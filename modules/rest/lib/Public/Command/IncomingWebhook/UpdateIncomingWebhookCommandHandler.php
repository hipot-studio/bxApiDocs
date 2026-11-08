<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Exception\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Enum\Integration\ElementCodeType;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Internal\Service\PasswordPermission\PermissionListNormalizer;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Preset\EventController;
use Bitrix\Rest\Preset\Provider;
use Bitrix\Rest\Service\ServiceContainer;

class UpdateIncomingWebhookCommandHandler
{
	public function __construct(
		private IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
		private IntegrationRepository $integrationRepository = new IntegrationRepository(),
		private SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ObjectNotFoundException
	 * @throws IncomingWebhookNotFoundException
	 * @throws ArgumentException
	 * @throws PersistenceException
	 */
	public function __invoke(UpdateIncomingWebhookCommand $command): IncomingWebhook
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new ObjectNotFoundException(
				'User with ID ' . $command->userId . ' not found'
			);
		}

		$webhook = $this->repository->getByWebhookId($command->webHookPassword);
		if ($webhook === null)
		{
			throw new IncomingWebhookNotFoundException();
		}

		// Partial update: keep the webhook's current scopes only when the field is
		// omitted (null). An explicit empty list means "no scopes" and is rejected,
		// as is a list where none of the provided scopes is valid.
		if ($command->scopes === null)
		{
			$scopes = $webhook->getScopes();
		}
		else
		{
			$scopes = PermissionTable::cleanPermissionList($command->scopes);
			if (empty($scopes))
			{
				throw new ArgumentException('At least one valid scope is required', 'scopes');
			}
		}

		$accessChecker = new WebhookAccessChecker($command->userId);
		if (!$accessChecker->canManageIncomingWebhook($webhook))
		{
			throw new AccessDeniedException(
				'User does not have rights to edit incoming webhook'
			);
		}

		$integration = $this->integrationRepository->getByIncomingWebhookPasswordId($webhook->getId());

		$previousScopes = $webhook->getScopes();
		$newTitle = $command->title ?? $webhook->getTitle();

		$providerFailed = false;
		$providerErrors = [];

		if ($integration === null)
		{
			try
			{
				$this->updateWithoutIntegration(
					$webhook,
					$newTitle,
					$command->scopes === null ? null : $scopes,
				);
			}
			catch (\Throwable $exception)
			{
				// The direct path applies nothing on error, so both a refused privilege change
				// and a technical failure are audited before the exception leaves the handler.
				$this->securityAuditLogger->logWebhookUpdated(
					actingUserId: $command->userId,
					webhookId: (int)$webhook->getId(),
					ownerUserId: $webhook->getUserId(),
					previousScopes: $previousScopes,
					newScopes: $scopes,
					title: $webhook->getTitle(),
					outcome: SecurityAuditLogger::OUTCOME_FAILURE,
					webhookType: $webhook->getType(),
				);

				throw $exception;
			}
		}
		else
		{
			$providerResult = Provider::saveIntegration(
				[
					'PASSWORD_ID' => $webhook->getId(),
					'TITLE' => $newTitle,
					'SCOPE' => $scopes,
					'MODE' => 'INCOMING_WEBHOOK',
				],
				ElementCodeType::IN_WEBHOOK->value,
				$integration->getId(),
				$user->getId(),
			);
			$providerFailed = !($providerResult['status'] ?? false);

			// The provider reports a failure as a list of messages, where a single item may itself
			// be the message list of a nested result.
			$providerMessages = (array)($providerResult['errors'] ?? []);
			array_walk_recursive(
				$providerMessages,
				static function (mixed $message) use (&$providerErrors): void {
					$providerErrors[] = new Error((string)$message);
				},
			);
		}

		// Re-read to audit and return the stored title, not the requested one: an empty title is
		// left unapplied by the direct path and replaced with a generated one by the preset path.
		$updatedWebhook = $this->repository->getByWebhookId($command->webHookPassword);

		// A failed provider call is audited too: it may have already rewritten the scopes.
		$this->securityAuditLogger->logWebhookUpdated(
			actingUserId: $command->userId,
			webhookId: (int)$webhook->getId(),
			ownerUserId: $webhook->getUserId(),
			previousScopes: $previousScopes,
			newScopes: $scopes,
			title: $updatedWebhook?->getTitle() ?? $webhook->getTitle(),
			outcome: $providerFailed
				? SecurityAuditLogger::OUTCOME_PARTIAL_FAILURE
				: SecurityAuditLogger::OUTCOME_SUCCESS,
			webhookType: $webhook->getType(),
		);

		if ($providerFailed)
		{
			throw new PersistenceException(
				$this->describeFailure('Unable to update incoming webhook integration', $providerErrors),
				errors: $providerErrors,
			);
		}

		return $updatedWebhook;
	}

	private function updateWithoutIntegration(
		IncomingWebhook $webhook,
		?string $title,
		?array $scopes,
	): void
	{
		// A set equal to the current one is not a scope change: there is nothing to write and
		// nothing to invalidate, so it is handled exactly like an omitted field.
		if ($scopes !== null && !$this->scopesDiffer($scopes, $webhook->getScopes()))
		{
			$scopes = null;
		}

		if ($scopes !== null && $webhook->getType() === WebhookType::System)
		{
			if ($this->scopesDifferIgnoringCase($scopes, $webhook->getScopes()))
			{
				throw new ArgumentException(
					'Scopes of a system incoming webhook are managed by its issuer and cannot be changed',
					'scopes',
				);
			}

			$scopes = null;
		}

		$titleChanged = $title !== null && $title !== '' && $title !== $webhook->getTitle();
		if (!$titleChanged && $scopes === null)
		{
			return;
		}

		$passwordId = (int)$webhook->getId();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			if ($titleChanged)
			{
				$titleResult = PasswordTable::update($passwordId, ['TITLE' => $title]);
				if (!$titleResult->isSuccess())
				{
					throw new PersistenceException(
						$this->describeFailure(
							'Unable to update incoming webhook title',
							$titleResult->getErrors(),
						),
						errors: $titleResult->getErrors(),
					);
				}
			}

			if ($scopes !== null)
			{
				$this->replaceScopes($passwordId, $webhook->getScopes(), $scopes);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		// Repeat invalidation: the one done inside the transaction may be outrun by a
		// parallel request caching the uncommitted state for the whole cache TTL.
		if ($titleChanged)
		{
			PasswordTable::cleanCache();
			ServiceContainer::getInstance()->getAPAuthPasswordService()->clearCacheById($passwordId);
		}

		if ($scopes !== null)
		{
			PermissionTable::cleanCache();
		}
	}

	private function replaceScopes(int $passwordId, array $currentScopes, array $scopes): void
	{
		// Repeated scopes reach here from the request as is: the unique index on
		// (PASSWORD_ID, PERM) compares permission codes case-insensitively, so a second insert
		// of a pair differing only in case or trailing spaces would hit it.
		$scopes = (new PermissionListNormalizer())->normalize($scopes);

		// $currentScopes stays raw on purpose and the diffs below stay byte-wise: that is what
		// marks an already stored 'CRM' obsolete and deletes it before 'crm' is inserted.

		// A webhook without an integration has nothing for the preset handler to denormalize, so
		// the events would only cost an IntegrationTable lookup per added scope.
		$eventsWereDisabled = EventController::isEventsDisabled();
		EventController::disableEvents();
		try
		{
			$obsoleteScopes = array_diff($currentScopes, $scopes);
			if (!empty($obsoleteScopes))
			{
				PermissionTable::deleteByFilter([
					'=PASSWORD_ID' => $passwordId,
					'@PERM' => array_values($obsoleteScopes),
				]);
			}

			foreach (array_diff($scopes, $currentScopes) as $scope)
			{
				$addResult = PermissionTable::add([
					'PASSWORD_ID' => $passwordId,
					'PERM' => $scope,
				]);
				if (!$addResult->isSuccess())
				{
					throw new PersistenceException(
						$this->describeFailure('Unable to add incoming webhook scope', $addResult->getErrors()),
						errors: $addResult->getErrors(),
					);
				}
			}
		}
		finally
		{
			if (!$eventsWereDisabled)
			{
				EventController::enableEvents();
			}
		}
	}

	private function scopesDiffer(array $scopes, array $currentScopes): bool
	{
		return array_diff($scopes, $currentScopes) !== [] || array_diff($currentScopes, $scopes) !== [];
	}

	private function scopesDifferIgnoringCase(array $scopes, array $currentScopes): bool
	{
		return $this->scopesDiffer(
			$this->toCaseInsensitiveSet($scopes),
			$this->toCaseInsensitiveSet($currentScopes),
		);
	}

	private function toCaseInsensitiveSet(array $scopes): array
	{
		return array_map(
			static fn (string $scope): string => mb_strtolower($scope),
			(new PermissionListNormalizer())->normalize($scopes),
		);
	}

	/**
	 * The exception log prints only the message of every exception in the chain, so a reason kept
	 * apart in Error[] never reaches it.
	 *
	 * @param Error[] $errors
	 */
	private function describeFailure(string $message, array $errors): string
	{
		$reasons = [];
		foreach ($errors as $error)
		{
			$reason = trim($error->getMessage());
			if ($reason !== '')
			{
				$reasons[] = $reason;
			}
		}

		return $reasons === [] ? $message : $message . ': ' . implode('; ', $reasons);
	}
}
