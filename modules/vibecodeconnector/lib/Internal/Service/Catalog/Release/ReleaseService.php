<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Release;

use Bitrix\Main\Application;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;

final class ReleaseService
{
	public const STATUS_SUCCESS = 'success';
	public const STATUS_RETRYABLE = 'retryable';
	public const STATUS_PERMANENT_REJECT = 'permanent-reject';

	private const LOGGED_VALUE_LENGTH = 64;

	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
	) {}

	public function release(
		string $serverIss,
		string $externalId,
		int $versionNumber,
	): string {
		$isPairedPrincipal = $this->pairingRepository->findByIss($serverIss) !== null;
		$item = $this->repository->getReleaseTarget($externalId, $serverIss, $isPairedPrincipal);
		if ($item === null)
		{
			return $this->answerMissingTarget($externalId, $serverIss);
		}
		if ($item->getType() !== CatalogItemType::Application)
		{
			return $this->answerUnsupportedTarget((int)$item->getId(), $serverIss);
		}

		$catalogItemId = (int)$item->getId();
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->repository->lockById($catalogItemId);
			$item = $this->repository->getReleaseTarget($externalId, $serverIss, $isPairedPrincipal);
			if ($item === null || (int)$item->getId() !== $catalogItemId)
			{
				$connection->commitTransaction();

				return $this->answerMissingTarget($externalId, $serverIss);
			}
			if ($item->getType() !== CatalogItemType::Application)
			{
				$connection->commitTransaction();

				return $this->answerUnsupportedTarget($catalogItemId, $serverIss);
			}

			$publishedVersion = $this->repository->getLastReleaseVersion($catalogItemId);
			if ($publishedVersion !== null && $versionNumber < $publishedVersion)
			{
				$connection->commitTransaction();

				return self::STATUS_PERMANENT_REJECT;
			}

			$isOwner = $publishedVersion === null || $versionNumber > $publishedVersion;
			if (
				$isOwner
				&& !$this->repository->compareAndSetLastReleaseVersion(
					$catalogItemId,
					$publishedVersion,
					$versionNumber,
				)
			)
			{
				throw new \RuntimeException('The release version was changed concurrently');
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}
		return self::STATUS_SUCCESS;
	}

	private function answerMissingTarget(string $externalId, string $serverIss): string
	{
		$knownToThePortal = $this->repository->hasExactExternalId($externalId);
		if (!$knownToThePortal)
		{
			return self::STATUS_RETRYABLE;
		}

		\AddMessage2Log(
			'External id ' . $this->forLog($externalId) . ': release from ' . $this->forLog($serverIss)
			. ' is rejected for good, the portal has no card this server may publish to',
			'vibecodeconnector',
		);

		return self::STATUS_PERMANENT_REJECT;
	}

	private function forLog(string $value): string
	{
		return (string)preg_replace('/[[:cntrl:]]/', ' ', mb_substr($value, 0, self::LOGGED_VALUE_LENGTH));
	}

	private function answerUnsupportedTarget(int $catalogItemId, string $serverIss): string
	{
		\AddMessage2Log(
			'Catalog item ' . $catalogItemId . ': release from ' . $this->forLog($serverIss)
			. ' is rejected for good, the card is not an application and has no app chat',
			'vibecodeconnector',
		);

		return self::STATUS_PERMANENT_REJECT;
	}
}
