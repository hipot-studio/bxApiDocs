<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Pull\Event\SharingLink\SharingLinkGenerate;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\Dto\CreateDto;
use Bitrix\Im\V2\SharingLink\Params\SharingLinkFilter;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\Entity\ShareableEntity;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;

final class SharingLinkFactory
{
	use ContextCustomer;

	private const LINK_CODE_LENGTH = 16;
	private const MAX_CODE_GENERATION_ATTEMPTS = 5;
	private const LOCK_TIMEOUT = 3;

	protected static self $instance;

	private function __construct() {}

	public static function getInstance(): self
	{
		if (isset(self::$instance))
		{
			return self::$instance;
		}

		self::$instance = new self();

		return self::$instance;
	}

	public function getLinkByCode(string $code): ?SharingLink
	{
		$findResult = $this->findLink(SharingLinkFilter::initByCode($code));
		if (!$findResult->isSuccess())
		{
			return null;
		}

		return $this->initLink($findResult->getResult());
	}

	public function getLinkById(int $id): ?SharingLink
	{
		$findResult = $this->findLink(SharingLinkFilter::initById($id));
		if (!$findResult->isSuccess())
		{
			return null;
		}

		return $this->initLink($findResult->getResult());
	}

	public function getActivePrimaryLinkByEntityFields(LinkEntityType $entityType, string $entityId): ?SharingLink
	{
		$findResult = $this->findLink(
			SharingLinkFilter::initForPrimary(entityType: $entityType, entityId: $entityId)
		);

		if (!$findResult->isSuccess())
		{
			return null;
		}

		return $this->initLink($findResult->getResult());
	}

	public function getActiveIndividualLinkByEntityFields(LinkEntityType $entityType, string $entityId, int $authorId): ?SharingLink
	{
		$findResult = $this->findLink(
			SharingLinkFilter::initForIndividual(entityType: $entityType, entityId: $entityId, authorId: $authorId)
		);

		if (!$findResult->isSuccess())
		{
			return null;
		}

		return $this->initLink($findResult->getResult());
	}

	/**
	 * @return Result<SharingLink|null>
	 */
	public function getOrCreateActivePrimaryLink(
		ShareableEntity $shareableEntity,
		int $authorId,
		bool $generateIfNotExists = true
	): Result
	{
		$result = new Result();

		$primaryLink = $this->getActivePrimaryLinkByShareableEntity($shareableEntity);
		if (isset($primaryLink))
		{
			return $result->setResult($primaryLink);
		}

		if (!$generateIfNotExists)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		$dto = CreateDto::initForPrimary(
			entityId: (string)$shareableEntity->getId(),
			entityType: $shareableEntity::getSharingLinkEntityType(),
			authorId: $authorId,
		);

		return $this->generateLink($dto);
	}

	/**
	 * @return Result<SharingLink|null>
	 */
	public function getOrCreateActiveIndividualLink(ShareableEntity $shareableEntity, int $userId, bool $generateIfNotExists = true): Result
	{
		$result = new Result();

		$link = $this->getActiveIndividualLinkByShareableEntity($shareableEntity, $userId);
		if (isset($link))
		{
			return $result->setResult($link);
		}

		if (!$generateIfNotExists)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		$dto = CreateDto::initForIndividual(
			entityId: (string)$shareableEntity->getId(),
			entityType: $shareableEntity::getSharingLinkEntityType(),
			authorId: $userId,
		);

		return $this->generateLink($dto);
	}

	public function regenerateActiveIndividualLink(ShareableEntity $shareableEntity, int $userId): Result
	{
		$dto = CreateDto::initForIndividual(
			entityId: (string)$shareableEntity->getId(),
			entityType: $shareableEntity::getSharingLinkEntityType(),
			authorId: $userId,
		);

		return $this->generateLink(dto: $dto, forceGenerate: true);
	}

	public function getActivePrimaryLinkByShareableEntity(ShareableEntity $entity): ?SharingLink
	{
		$findResult = $this->findLink(
			SharingLinkFilter::initForPrimary(entityType: $entity::getSharingLinkEntityType(), entityId: (string)$entity->getId())
		);

		if (!$findResult->isSuccess())
		{
			return null;
		}

		return $this->initLink($findResult->getResult());
	}

	public function getActiveIndividualLinkByShareableEntity(ShareableEntity $entity, int $userId): ?SharingLink
	{
		$findResult = $this->findLink(
			SharingLinkFilter::initForIndividual(
				entityType: $entity::getSharingLinkEntityType(),
				entityId: (string)$entity->getId(),
				authorId: $userId
			)
		);

		if (!$findResult->isSuccess())
		{
			return null;
		}

		return $this->initLink($findResult->getResult());
	}

	/**
	 * @return Result<SharingLink|null>
	 */
	public function generateLink(CreateDto $dto, bool $forceGenerate = false): Result
	{
		$result = new Result();
		if (!$dto->type->isUnique())
		{
			// Now we can't create custom links
			return $result->addError(new SharingLinkError(SharingLinkError::CREATION_ERROR));
		}

		$lockName = self::getUniqueAdditionLockName($dto);
		$connection = Application::getConnection();

		$isLocked = $connection->lock($lockName, self::LOCK_TIMEOUT);
		if (!$isLocked)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::CREATION_ERROR));
		}

		try
		{
			return $this->processGenerateLink($dto, $forceGenerate);
		}
		catch (\Throwable)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::CREATION_ERROR));
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function processGenerateLink(CreateDto $dto, bool $forceGenerate = false): Result
	{
		$result = new Result();

		$currentLink = match ($dto->type)
		{
			Type::Primary => $this->getActivePrimaryLinkByEntityFields($dto->entityType, $dto->entityId),
			Type::Individual => $this->getActiveIndividualLinkByEntityFields($dto->entityType, $dto->entityId, $dto->authorId),
			default => null,
		};

		if (isset($currentLink) && !$forceGenerate)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::UNIQUE_ALREADY_EXISTS));
		}

		$revocationResult = null;
		if (isset($currentLink) && $forceGenerate)
		{
			$revocationResult = $currentLink->revoke();
		}

		if (isset($revocationResult) && !$revocationResult->isSuccess())
		{
			return $result->addErrors($revocationResult->getErrors());
		}

		$code = $this->generateUniqueCode();
		if (!isset($code))
		{
			return $result->addError(new SharingLinkError(SharingLinkError::CREATION_ERROR));
		}

		$createDto = $dto->withCode($code);

		return $this->createLink($createDto);
	}

	private static function getUniqueAdditionLockName(CreateDto $dto): string
	{
		$type = mb_strtolower($dto->type->value);
		$additionalString = $dto->type === Type::Individual ? "_{$dto->authorId}" : '';

		return "generate_{$type}_link_{$dto->entityType->value}_{$dto->entityId}" . $additionalString;
	}

	/**
	 * @return Result<null|array{ID: int, ENTITY_TYPE: string, ENTITY_ID: int, CODE: string, ...}>
	 */
	private function findLink(SharingLinkFilter $filter): Result
	{
		$result = new Result();
		if ($filter->isEmpty())
		{
			return $result->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		$query = SharingLinkTable::query()
			->setSelect(['*'])
			->where($filter->prepareFilter())
			->setLimit(1)
		;

		$queryResult = $query->fetch();
		if (!empty($queryResult))
		{
			$result->setResult($queryResult);
		}
		else
		{
			$result->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		return $result;
	}

	/**
	 * @return Result<SharingLink|null>
	 */
	private function createLink(CreateDto $dto): Result
	{
		$result = new Result();

		$link = $this->initLink($dto->toArray());
		$saveResult = $link?->save();

		if (!isset($link) || !$saveResult->isSuccess())
		{
			return $result->addError(new SharingLinkError(SharingLinkError::CREATION_ERROR));
		}

		(new SharingLinkGenerate($link))->send();

		return $result->setResult($link);
	}

	private function initLink(array $params): ?SharingLink
	{
		$entityType = LinkEntityType::tryFrom($params['ENTITY_TYPE'] ?? '');

		return match ($entityType)
		{
			LinkEntityType::Chat => (new ChatLink($params)),
			default => null,
		};
	}

	private function generateUniqueCode(): ?string
	{
		for ($i = 0; $i < self::MAX_CODE_GENERATION_ATTEMPTS; $i++)
		{
			$code = $this->generateRandomString();

			if ($this->checkCodeUniqueness($code))
			{
				return $code;
			}
		}

		return null;
	}

	private function checkCodeUniqueness(string $code): bool
	{
		if (mb_strlen($code) !== self::LINK_CODE_LENGTH)
		{
			return false;
		}

		$findResult = $this->findLink(SharingLinkFilter::initByCode($code));

		return !$findResult->isSuccess();
	}

	private function generateRandomString(): string
	{
		if (Loader::includeModule('security'))
		{
			return Random::getString(self::LINK_CODE_LENGTH, true);
		}

		return mb_substr(uniqid('', true), -self::LINK_CODE_LENGTH);
	}
}
