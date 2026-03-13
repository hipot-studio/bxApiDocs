<?php

namespace Bitrix\BIConnector;

use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Type\Date;

abstract class LimitManager
{
	public const GRACE_PERIOD_DAYS = 14;
	public const AUTO_RELEASE_DAYS = 7;
	public const GRACE_PERIOD_DAYS_SUPERSET = 1;
	public const AUTO_RELEASE_DAYS_SUPERSET = 1;
	public const LOCK_DURATION_DAYS_SUPERSET = 1.5;

	protected const DEPRECATED_LOCK_OPTION_NAME = 'disable_data_connection';

	protected const OPTION_GRACE_PERIOD_DAYS = 'biconnector_grace_period_days';
	protected const OPTION_PREFIX_OVER_LIMIT = 'over_limit_ts';
	protected const OPTION_PREFIX_LAST_LIMIT = 'last_limit_ts';
	protected const OPTION_PREFIX_LOCK_DATE = 'lock_date_ts';
	protected const OPTION_PREFIX_LOCK = 'disable_data_connection';
	protected const LOCKED_SERVICES_OPTION_NAME = 'disable_data_connection_services'; // JSON array of service IDs

	protected bool $softMode = false;
	protected ?Service $service = null;

	/**
	 * @return LimitManager
	 */
	public static function getInstance(): self
	{
		if (Loader::includeModule('bitrix24'))
		{
			$instance = new LimitManagerBitrix24();
		}
		else
		{
			$instance = new LimitManagerBox();
		}

		return $instance;
	}

	/**
	 * Called on data export end.
	 *
	 * @param int $rowsCount How many data rows was exported.
	 * @return bool Limit was exceeded or not.
	 */
	public function fixLimit(int $rowsCount): bool
	{
		$limit = $this->getLimit();
		$exceeded = ($limit > 0 && $rowsCount > $limit);
		$now = DateTime::createFromTimestamp(time());

		if ($this->isSoftMode())
		{
			$this->isDataConnectionDisabled()
				? $this->enableDataConnection()
				: $this->clearOverLimitTimestamps()
			;

			return true;
		}

		if ($exceeded)
		{
			$this->setLastOverLimitDate($now);
			$first = $this->getFirstOverLimitDate();
			if ($first === null)
			{
				$this->setFirstOverLimitDate($now);
			}
			else
			{
				$graceEnd = clone $first;
				$graceEnd->add($this->getGracePeriodDays() . ' days');
				if ($now > $graceEnd && !$this->isDataConnectionDisabled())
				{
					$this->disableDataConnection();
				}
			}

			return true;
		}

		$last = $this->getLastOverLimitDate();
		if (empty($last))
		{
			return false;
		}

		$last->add($this->getAutoReleaseDays() * 24 . ' hours');
		if ($now > $last)
		{
			$this->isDataConnectionDisabled()
				? $this->enableDataConnection()
				: $this->clearOverLimitTimestamps()
			;
		}

		return false;
	}

	public function isLimitByLicence(): bool
	{
		return false;
	}

	/**
	 * Returns maximum allowed records count.
	 * 0 - unlimited.
	 *
	 * @return int
	 */
	abstract public function getLimit(): int;

	/**
	 * Returns a date when data export will be disabled.
	 *
	 * @return Date
	 */
	abstract public function getLimitDate(): Date;

	/**
	 * Returns true if there is nothing to worry about.
	 *
	 * @return bool
	 */
	abstract public function checkLimitWarning(): bool;

	/**
	 * Returns true if data export and some functions is not disabled.
	 *
	 * @return bool
	 */
	abstract public function checkLimit(): bool;

	/**
	 * Event OnAfterSetOption_~controller_group_name handler.
	 *
	 * @param \Bitrix\Main\Event $event Event parameters.
	 *
	 * @return void
	 */
	abstract public function licenseChange(\Bitrix\Main\Event $event): void;

	/**
	 * Set service to manage limits for.
	 * If not set, common BI limits are used.
	 */
	public function setService(Service $service): self
	{
		$this->service = $service;

		return $this;
	}

	/**
	 * Check whether the query is from superset or not to use proper limits.
	 *
	 * @return bool
	 */
	public function isSuperset(): bool
	{
		return $this->service instanceof ApacheSuperset;
	}

	/**
	 * Should be used only if ensured this is superset but key is unknown.
	 * If the key is known, use setSupersetKey method.
	 * @see LimitManager::setSupersetKey
	 *
	 * @return $this
	 * @deprecated
	 */
	public function setIsSuperset(): self
	{
		if (!$this->service instanceof \Bitrix\BIConnector\Services\ApacheSuperset)
		{
			$this->service = \Bitrix\BIConnector\Manager::getInstance()->createService(\Bitrix\BIConnector\Services\ApacheSuperset::getServiceId());
		}

		if ($this->isDataConnectionDisabled())
		{
			$this->enableDataConnection();
		}

		return $this;
	}

	public function enableSoftMode(): self
	{
		$this->softMode = true;

		return $this;
	}

	public function isSoftMode(): bool
	{
		return $this->isSuperset() || $this->softMode;
	}

	protected function isDataConnectionDisabled(): bool
	{
		if (!$this->isSuperset() && Option::get('biconnector', self::DEPRECATED_LOCK_OPTION_NAME, 'N') === 'Y')
		{
			return true;
		}

		return Option::get('biconnector', $this->getLockOptionName(), 'N') === 'Y';
	}

	protected function getFirstOverLimitDate(): ?DateTime
	{
		$timestamp = (int)Option::get('biconnector', $this->getFirstOverLimitOptionName());
		if ($timestamp > 0)
		{
			return DateTime::createFromTimestamp($timestamp);
		}

		return null;
	}

	protected function getLastOverLimitDate(): ?DateTime
	{
		$timestamp = (int)Option::get('biconnector', $this->getLastOverLimitOptionName());
		if ($timestamp > 0)
		{
			return DateTime::createFromTimestamp($timestamp);
		}

		return null;
	}

	protected function getGracePeriodDays(): int
	{
		if ($this->isSuperset())
		{
			return self::GRACE_PERIOD_DAYS_SUPERSET;
		}

		return Option::get('biconnector', self::OPTION_GRACE_PERIOD_DAYS, self::GRACE_PERIOD_DAYS);
	}

	protected function getAutoReleaseDays(): int
	{
		return $this->isSuperset() ? self::AUTO_RELEASE_DAYS_SUPERSET : self::AUTO_RELEASE_DAYS;
	}

	protected function getSupersetLockDate(): ?DateTime
	{
		if (!$this->isSuperset())
		{
			return null;
		}

		$time = (int)Option::get('biconnector', $this->getLockDateOptionName());
		if ($time)
		{
			return DateTime::createFromTimestamp($time);
		}

		return null;
	}

	/** @deprecated  */
	public function getSupersetUnlockDate(): ?DateTime
	{
		if (!$this->isSuperset())
		{
			return null;
		}

		$lockDate = $this->getSupersetLockDate();
		if (
			$lockDate === null
			|| !$this->isDataConnectionDisabled()
		)
		{
			return null;
		}

		return $lockDate->add(self::LOCK_DURATION_DAYS_SUPERSET * 24 . " hours");
	}

	protected function setFirstOverLimitDate(?DateTime $date = null): void
	{
		if (!$date)
		{
			$date = DateTime::createFromTimestamp(time());
		}

		Option::set('biconnector', $this->getFirstOverLimitOptionName(), $date->getTimestamp());
	}

	protected function setLastOverLimitDate(?DateTime $date = null): void
	{
		if (!$date)
		{
			$date = DateTime::createFromTimestamp(time());
		}

		Option::set('biconnector', $this->getLastOverLimitOptionName(), $date->getTimestamp());
	}

	protected function disableDataConnection(): void
	{
		if (!$this->service)
		{
			return;
		}

		Option::set('biconnector', $this->getLockOptionName(), 'Y');
		if ($this->isSuperset())
		{
			Option::set('biconnector', $this->getLockDateOptionName(), time());
		}

		$serviceId = $this->service::getServiceId();
		$locked = $this->getLockedServicesList();
		if (!empty($serviceId) && !in_array($serviceId, $locked, true))
		{
			$locked[] = $serviceId;
			$this->setLockedServicesList($locked);
		}
	}

	protected function enableDataConnection(): void
	{
		if (!$this->isSuperset())
		{
			Option::delete('biconnector', ['name' => self::DEPRECATED_LOCK_OPTION_NAME]);
		}

		if (!$this->service)
		{
			return;
		}

		Option::delete('biconnector', ['name' => $this->getLockOptionName()]);
		$this->clearOverLimitTimestamps();

		$serviceId = $this->service::getServiceId();
		$locked = $this->getLockedServicesList();
		if (!empty($serviceId) && in_array($serviceId, $locked, true))
		{
			$newLockedList = array_values(array_filter($locked, static fn($id) => $id !== $serviceId));
			$this->setLockedServicesList($newLockedList);
		}
	}

	public function clearOverLimitTimestamps(): void
	{
		Option::delete('biconnector', ['name' => $this->getFirstOverLimitOptionName()]);
		Option::delete('biconnector', ['name' => $this->getLastOverLimitOptionName()]);
		Option::delete('biconnector', ['name' => $this->getLockDateOptionName()]);
	}

	protected function getFirstOverLimitOptionName(): string
	{
		return $this->buildServiceOptionName(self::OPTION_PREFIX_OVER_LIMIT);
	}

	protected function getLastOverLimitOptionName(): string
	{
		return $this->buildServiceOptionName(self::OPTION_PREFIX_LAST_LIMIT);
	}

	protected function getLockOptionName(): string
	{
		return $this->buildServiceOptionName(self::OPTION_PREFIX_LOCK);
	}

	protected function getLockDateOptionName(): string
	{
		return $this->buildServiceOptionName(self::OPTION_PREFIX_LOCK_DATE);
	}

	protected function buildServiceOptionName(string $prefix): string
	{
		if ($this->service)
		{
			return $prefix . '_' . $this->service::getServiceId();
		}

		return $prefix;
	}

	protected function getLockedServicesList(): array
	{
		$value = Option::get('biconnector', self::LOCKED_SERVICES_OPTION_NAME, '[]');
		try
		{
			$decoded = \Bitrix\Main\Web\Json::decode($value);

			return is_array($decoded) ? $decoded : [];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	protected function setLockedServicesList(array $list): void
	{
		$list = array_values(array_unique(array_filter($list, 'is_string')));
		if (empty($list))
		{
			Option::delete('biconnector', ['name' => self::LOCKED_SERVICES_OPTION_NAME]);

			return;
		}

		Option::set('biconnector', self::LOCKED_SERVICES_OPTION_NAME, \Bitrix\Main\Web\Json::encode($list));
	}

	/**
	 * Event OnAfterSetOption_~controller_group_name handler.
	 *
	 * @param \Bitrix\Main\Event $event Event parameters.
	 *
	 * @return void
	 */
	public static function onBitrix24LicenseChange(\Bitrix\Main\Event $event)
	{
		static::getInstance()->licenseChange($event);
	}

	/**
	 * Release current data connection lock and clear over-limit state.
	 * Can be used by services that switch to soft-limit (v2) mode.
	 */
	public function releaseLock(): void
	{
		$this->enableSoftMode();
		$this->enableDataConnection();
	}
}
