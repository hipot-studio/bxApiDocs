<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection\CompanyCollection;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Config\Option;

class CompanyFeedHashService
{
	private const MODULE_ID = 'booking';
	private const HASH_OPTION = 'yandex_company_feed_hash';

	public function calculate(CompanyCollection $companyCollection): string
	{
		return hash('sha256', Json::encode($companyCollection->toArray()));
	}

	public function save(CompanyCollection $companyCollection): void
	{
		Option::set(
			self::MODULE_ID,
			self::HASH_OPTION,
			$this->calculate($companyCollection)
		);
	}

	public function reset(): void
	{
		Option::delete(
			moduleId: self::MODULE_ID,
			filter: ['name' => self::HASH_OPTION],
		);
	}

	public function getCurrent(): string
	{
		return Option::get(self::MODULE_ID, self::HASH_OPTION);
	}
}
