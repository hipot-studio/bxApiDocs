<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugRepository;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugRepositoryInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Exception;

final class DebugService implements DebugServiceInterface
{
	private DebugRepositoryInterface $repository;

	public function __construct(?DebugRepositoryInterface $repository = null)
	{
		$this->repository = $repository ?? new DebugRepository();
	}

	/**
	 * @throws Exception
	 */
	public function enableForTemplate(int $userId, int $templateId): Debug
	{
		$debug = $this->repository->find($userId, $templateId);

		if ($debug === null)
		{
			$debug = Debug::create($userId, $templateId);
			$this->repository->save($debug);

			return $debug;
		}

		if (!$debug->isEnabled()) {
			$this->repository->save($debug->setEnabled(true));
		}

		return $debug;
	}

	/**
	 * @param array<array-key, string> $documentId
	 *
	 * @throws Exception
	 */
	public function enableForDocument(int $userId, int $templateId, array $documentId): Debug {
		$debug = Debug::create($userId, $templateId, $documentId);
		$this->repository->save($debug);

		return $debug;
	}

	public function disable(int $userId, int $templateId, ?array $documentId = null): void
	{
		$this->repository->disable($userId, $templateId, $documentId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function disableAll(int $userId): void
	{
		$this->repository->disableAllForUser($userId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function isEnabled(int $userId, int $templateId, ?array $documentId = null): bool
	{
		$debug = $this->repository->find($userId, $templateId, $documentId);

		return $debug !== null && $debug->isEnabled();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getDebug(int $userId, int $templateId, ?array $documentId = null, ?bool $isEnabled = null): ?Debug
	{
		return $this->repository->find($userId, $templateId, $documentId, $isEnabled);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getActiveDebugForUser(int $userId): ?Debug
	{
		return $this->repository->findByUserId($userId);
	}
}
