<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Folder;

use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Folder\FolderRecentProvider;
use Bitrix\Im\V2\Folder\Query\FolderProviderParams;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

class Recent extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new ExactParameter(
					FolderProviderParams::class,
					'params',
					static function ($className, array $filter = [], ?int $limit = null): FolderProviderParams {
						return FolderProviderParams::fromArray($filter, $limit);
					}
				),
			]
		);
	}

	/**
	 * @restMethod im.v2.Folder.Recent.tail
	 */
	public function tailAction(
		\Bitrix\Im\V2\Folder\Folder $folder,
		FolderRecentProvider $provider,
		FolderProviderParams $params
	): ?array
	{
		$requested = $params->limit ?? static::DEFAULT_LIMIT;
		$limit = max(static::DEFAULT_LIMIT, min(static::MAX_LIMIT, $requested));

		$result = $provider->getTail($folder, $params, $limit);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$recent = $result->getResult();

		return $this->toRestFormatWithPaginationData([$recent], $limit, $recent->count());
	}
}
