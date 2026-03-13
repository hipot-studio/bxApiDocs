<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cache;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Cache\Mapper\MapperInterface;
use Bitrix\Im\V2\Chat\ChatClassResolver;
use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Main\DI\ServiceLocator;

/**
 * @implements MapperInterface<Chat>
 */
class ChatMapper implements MapperInterface
{
	/** @return Chat */
	public function __invoke(array $data): CacheableEntity
	{
		$chatClass = ServiceLocator::getInstance()->get(ChatClassResolver::class)->resolveForInit($data);

		return new $chatClass($data);
	}

	public function wrapRawData(mixed $rawData): ?array
	{
		return is_array($rawData) ? $rawData : null;
	}
}
