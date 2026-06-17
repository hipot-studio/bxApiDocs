<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugTraceCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugTraceRepository;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugTraceRepositoryInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\SystemException;

class DebugTraceProvider
{
	private readonly DebugTraceRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = new DebugTraceRepository();
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(int $id): ?DebugTrace
	{
		return $this->repository->first($id);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getList(GridParams $gridParams): DebugTraceCollection
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
