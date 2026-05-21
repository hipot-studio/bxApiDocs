<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Entity;
use Bitrix\Booking\Entity\Client\ClientTypeCollection;
use Bitrix\Booking\Internals\Model\ClientTypeTable;
use Bitrix\Booking\Internals\Repository\ClientTypeRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ClientTypeMapper;

class ClientTypeRepository implements ClientTypeRepositoryInterface
{
	private ClientTypeMapper $mapper;
	private static ?ClientTypeCollection $cache = null;

	public function __construct(ClientTypeMapper $mapper)
	{
		$this->mapper = $mapper;
	}

	public function get(string $code, string $moduleId): Entity\Client\ClientType|null
	{
		foreach ($this->getList() as $clientType)
		{
			if ($clientType->getCode() === $code && $clientType->getModuleId() === $moduleId)
			{
				return $clientType;
			}
		}

		return null;
	}

	public function getList(): ClientTypeCollection
	{
		if (self::$cache !== null)
		{
			return self::$cache;
		}

		$clientTypeCollection = new ClientTypeCollection();

		$queryResult = ClientTypeTable::query()->setSelect(['*'])->exec();
		while ($ormResourceType = $queryResult->fetchObject())
		{
			$clientTypeCollection->add($this->mapper->convertFromOrm($ormResourceType));
		}

		self::$cache = $clientTypeCollection;

		return self::$cache;
	}
}
