<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Infrastructure\Agent;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

class DeleteExpiredAgent
{
	private const EXPIRY_INTERVAL = '-12 months';

	public function __construct(
		protected readonly CountersCache $cache
	) {}

	public static function execute(): string
	{
		ServiceLocator::getInstance()->get(self::class)->handle();
		return '\\Bitrix\\Im\\V2\\Reading\\Counter\\Infrastructure\\Agent\\DeleteExpiredAgent::execute();';
	}

	protected function handle(): void
	{
		$dateExpired = (new DateTime())->add(self::EXPIRY_INTERVAL);
		MessageUnreadTable::deleteByFilter(['<=DATE_CREATE' => $dateExpired]);

		$this->cache->removeAll();
	}
}
