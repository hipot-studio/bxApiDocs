<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Embedding;

use Bitrix\Main;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Exceptions\ArgumentException;
use Bitrix\Rest\Internal;
use Bitrix\Rest\Internal\Repository\EmbeddingRepository;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingValidationService;
use Bitrix\Rest\PlacementTable;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingLanguageDto;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingOwnerProviderNotFoundException;
use Bitrix\Rest\Public\Provider\EmbeddingOwnerProviderInterface;
use Bitrix\Rest\Public\Provider\EmbeddingOwnerProviderRegistry;
use Bitrix\Rest\Public\Services\EmbeddingAccessService;

final class BindEmbeddingCommandHandler
{
	private EmbeddingValidationService $validationService;
	private EmbeddingAccessService $embeddingAccessService;

	public function __construct(
		private EmbeddingRepository $repository = new EmbeddingRepository(),
		private EmbeddingOwnerProviderRegistry $providerRegistry = new EmbeddingOwnerProviderRegistry(),
		?EmbeddingValidationService $validationService = null,
		?EmbeddingAccessService $embeddingAccessService = null,
	)
	{
		$this->validationService = $validationService ?? new EmbeddingValidationService($this->repository);
		$this->embeddingAccessService = $embeddingAccessService ?? new EmbeddingAccessService();
	}

	public function __invoke(BindEmbeddingCommand $command): int
	{
		$provider = $this->resolveProvider($command->owner);
		$accessCodes = $provider->getEmbeddingAccessCodes($command->owner);

		$prepared = $this->validationService->prepare(
			$command->owner->moduleId,
			$command->owner->entityType,
			$command->placement,
			$command->handler,
			$command->targetUserId,
			$command->options,
			$this->makeInternalLanguages($command->languages),
		);

		$this->validationService->validateLimitAndDuplicate(
			$command->owner->moduleId,
			$command->owner->entityType,
			$command->owner->entityId,
			$prepared['placement'],
			$prepared['handler'],
			$prepared['targetUserId'],
			$prepared['placementInfo'],
		);
		$this->validationService->validateRegisterCallback(
			$command->owner->moduleId,
			$command->owner->entityType,
			$command->owner->entityId,
			$prepared['placement'],
			$prepared['handler'],
			$prepared['targetUserId'],
			$prepared['options'],
			$prepared['placementInfo'],
		);

		$copiedIconId = $command->iconId !== null ? $this->copyIcon($command->iconId) : null;
		$connection = Main\Application::getConnection();

		try
		{
			$connection->startTransaction();

			$embeddingId = $this->repository->create(
				$command->owner->moduleId,
				$command->owner->entityType,
				$command->owner->entityId,
				$prepared['placement'],
				$prepared['handler'],
				$prepared['targetUserId'],
				$prepared['options'],
				$prepared['languages'],
				$copiedIconId,
			);
			$this->embeddingAccessService->set(
				$command->owner->moduleId,
				$command->owner->entityType,
				$embeddingId,
				$accessCodes,
			);

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);

			if ($copiedIconId !== null)
			{
				$this->deleteIconAfterFailedBind($copiedIconId);
			}

			throw $exception;
		}

		$this->runAfterCommit($command->owner, $prepared['placement'], $embeddingId);

		return $embeddingId;
	}

	private function runAfterCommit(EmbeddingOwnerDto $owner, string $placement, int $embeddingId): void
	{
		PlacementTable::clearHandlerCache();

		if (EmbeddingOwnerResolver::isAppOwnerType($owner->moduleId, $owner->entityType))
		{
			return;
		}

		try
		{
			$this->publishPlacementAdd($placement, $embeddingId);
		}
		catch (\Throwable $exception)
		{
			Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	private function publishPlacementAdd(string $placement, int $embeddingId): void
	{
		Main\EventManager::getInstance()->send(new Main\Event(
			'rest',
			PlacementTable::PREFIX_EVENT_ON_AFTER_ADD . $placement,
			[
				'ID' => $embeddingId,
				'PLACEMENT' => $placement,
			],
		));
	}

	private function resolveProvider(EmbeddingOwnerDto $owner): EmbeddingOwnerProviderInterface
	{
		$provider = $this->providerRegistry->resolve($owner->moduleId, $owner->entityType);
		if ($provider === null)
		{
			throw new EmbeddingOwnerProviderNotFoundException();
		}

		return $provider;
	}

	/**
	 * @param list<EmbeddingLanguageDto> $languages
	 *
	 * @return list<Internal\Service\Embedding\EmbeddingLanguageDto>
	 */
	private function makeInternalLanguages(array $languages): array
	{
		$result = [];
		foreach ($languages as $language)
		{
			$result[] = new Internal\Service\Embedding\EmbeddingLanguageDto(
				languageId: $language->languageId,
				title: $language->title,
				description: $language->description,
				groupName: $language->groupName,
			);
		}

		return $result;
	}

	private function copyIcon(int $iconId): int
	{
		$file = \CFile::MakeFileArray($iconId);
		if (!is_array($file))
		{
			throw new ArgumentException('Embedding icon was not found', 'iconId');
		}

		$error = \CFile::CheckImageFile($file);
		if ($error !== null && $error !== '')
		{
			throw new ArgumentException((string)$error, 'iconId');
		}

		$copiedIconId = \CFile::CloneFile($iconId);
		if ($copiedIconId === null || $copiedIconId <= 0)
		{
			throw new PersistenceException('Unable to copy embedding icon');
		}

		return $copiedIconId;
	}

	private function deleteIconAfterFailedBind(int $iconId): void
	{
		try
		{
			\CFile::Delete($iconId);
		}
		catch (\Throwable $exception)
		{
			try
			{
				Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			}
			catch (\Throwable)
			{
			}
		}
	}

	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}
}
