<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserAttribute;
use Bitrix\Vibecodeconnector\Internal\Repository\User\UserAttributesRepository;

final class BaselineSettings
{
	private const MODULE_ID = 'vibecodeconnector';
	private const OPTION_GLOBAL_BASELINE_AT = 'catalog_new_apps_baseline_at';

	private UserAttributesRepository $userAttributes;

	/** @var array<int, ?DateTime> request-scoped memo so a single flow does not read the personal anchor twice */
	private array $personalBaseline = [];

	public function __construct(?UserAttributesRepository $userAttributes = null)
	{
		$this->userAttributes = $userAttributes ?? new UserAttributesRepository();
	}

	public function getGlobalBaselineAt(): ?DateTime
	{
		return $this->timestampToDateTime((int)Option::get(self::MODULE_ID, self::OPTION_GLOBAL_BASELINE_AT, '0'));
	}

	public function setGlobalBaselineAt(DateTime $dateTime): void
	{
		Option::set(self::MODULE_ID, self::OPTION_GLOBAL_BASELINE_AT, (string)$dateTime->getTimestamp());
	}

	public function getPersonalBaselineAt(int $userId): ?DateTime
	{
		if (!array_key_exists($userId, $this->personalBaseline))
		{
			$this->personalBaseline[$userId] = $this->userAttributes->get($userId, UserAttribute::FirstOpenedCatalog);
		}

		return $this->personalBaseline[$userId];
	}

	public function setPersonalBaselineAt(int $userId, DateTime $dateTime): void
	{
		$this->userAttributes->set($userId, UserAttribute::FirstOpenedCatalog, $dateTime);
		$this->personalBaseline[$userId] = $dateTime;
	}

	private function timestampToDateTime(int $timestamp): ?DateTime
	{
		return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
	}
}
