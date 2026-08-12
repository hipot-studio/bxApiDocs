<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ProxyType;

final class SearchResultLinkPolicy
{
	public function apply(BaseObject $object, array $data): array
	{
		if (!$this->shouldBuildDetailUrl($object))
		{
			$data['DETAIL_URL'] = null;
		}

		return $data;
	}

	public function shouldBuildDetailUrl(BaseObject $object): bool
	{
		$storage = $object->getStorage();

		return $storage !== null && $storage->getProxyType() instanceof ProxyType\Disk;
	}
}
