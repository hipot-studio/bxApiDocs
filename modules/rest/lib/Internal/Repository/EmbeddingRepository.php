<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main;
use Bitrix\Rest\EO_Placement_Collection;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingCollection;
use Bitrix\Rest\Internal\Repository\Mapper\EmbeddingMapper;
use Bitrix\Rest\PlacementTable;

class EmbeddingRepository implements Main\Repository\RepositoryInterface
{
	private EmbeddingMapper $mapper;

	public function __construct(
		?EmbeddingMapper $mapper = null,
	)
	{
		$this->mapper = $mapper ?? Main\DI\ServiceLocator::getInstance()->get(EmbeddingMapper::class);
	}

	public function getListByClientId(string $clientId, int $limit = 50, int $offset = 0): EmbeddingCollection
	{
		/**
		 * @var EO_Placement_Collection $ormCollection
		 */
		$ormCollection = PlacementTable::query()
			->setSelect(['*'])
			->where('REST_APP.CLIENT_ID', $clientId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->setOffset($offset)
			->fetchCollection()
		;

		$ormCollection->fillLangAll();

		return $this->mapper->convertCollectionFromOrm($ormCollection);
	}
}
