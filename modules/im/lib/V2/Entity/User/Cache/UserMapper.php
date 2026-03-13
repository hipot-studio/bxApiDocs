<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\User\Cache;

use Bitrix\Im\V2\Cache\Mapper\MapperInterface;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserClassResolver;
use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Main\DI\ServiceLocator;

/**
 * @implements MapperInterface<User>
 */
class UserMapper implements MapperInterface
{
	/** @return User */
	public function __invoke(array $data): CacheableEntity
	{
		return ServiceLocator::getInstance()->get(UserClassResolver::class)->resolve($data)::initByArray($data);
	}

	public function wrapRawData(mixed $rawData): ?array
	{
		return is_array($rawData) ? $rawData : null;
	}
}
