<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;

interface FileRepositoryInterface
{
	public function getById(int $id): ?File;

	public function getByIds(array $ids): FileCollection;
}
