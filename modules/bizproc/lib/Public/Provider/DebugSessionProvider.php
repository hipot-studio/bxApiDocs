<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugSessionCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepository;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepositoryInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\SystemException;

class DebugSessionProvider
{
	private readonly DebugSessionRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = new DebugSessionRepository();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getById(int $id, bool $withTraces = true): ?DebugSession
	{
		return $this->repository->first($id, $withTraces);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getList(GridParams $gridParams): DebugSessionCollection
	{
		return $this->repository->getList(
			$gridParams->getLimit(),
			$gridParams->getOffset(),
			$gridParams->filter,
			$gridParams->getSort(),
			$gridParams->getSelect(),
		);
	}
}
