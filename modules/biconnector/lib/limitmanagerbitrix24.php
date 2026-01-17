<?php

namespace Bitrix\BIConnector;

use Bitrix\Bitrix24;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Type\Date;
use Bitrix\BIConnector\Services\ApacheSuperset;

class LimitManagerBitrix24 extends LimitManager
{
	/**
	 * Returns maximum allowed records count.
	 * 0 - unlimited.
	 *
	 * @return int
	 */
	public function getLimit(): int
	{
		$variableName = $this->isSuperset() ? 'biconnector_limit_superset' : 'biconnector_limit';

		return (int)Bitrix24\Feature::getVariable($variableName);
	}

	/**
	 * Returns a date when data export will be disabled.
	 *
	 * @return Date
	 */
	public function getLimitDate(): Date
	{
		if ($this->service === null)
		{
			$locked = $this->getLockedServicesList();
			if (!empty($locked))
			{
				$earliest = null;
				foreach ($locked as $serviceId)
				{
					if (!is_string($serviceId) || $serviceId === '')
					{
						continue;
					}
					$firstTs = (int)Option::get('biconnector', self::OPTION_PREFIX_OVER_LIMIT . '_' . $serviceId);
					if ($firstTs <= 0)
					{
						continue;
					}
					// Determine grace by service type (Superset has its own shorter grace)
					$graceDays = $serviceId === ApacheSuperset::getServiceId() ? self::GRACE_PERIOD_DAYS_SUPERSET : self::GRACE_PERIOD_DAYS;
					$candidate = DateTime::createFromTimestamp($firstTs);
					$candidate->add("{$graceDays} day");
					if ($earliest === null || $candidate < $earliest)
					{
						$earliest = $candidate;
					}
				}
				if ($earliest !== null)
				{
					return $earliest;
				}
			}
		}

		$date = $this->getFirstOverLimitDate();
		$date?->add("{$this->getGracePeriodDays()} day");

		return $date;
	}

	/**
	 * Returns true when there is no reason to show a warning.
	 * Logic:
	 *  - For a specific service: the service is not locked and there has been no first over‑limit event.
	 *  - For the general context (service == null): no global lock, the list of locked services is empty, and there has been no first over‑limit event.
	 */
	public function checkLimitWarning(): bool
	{
		$noFirstOver = ($this->getFirstOverLimitDate() === null);
		if ($this->service === null)
		{
			return !$this->isDataConnectionDisabled()
				&& empty($this->getLockedServicesList())
				&& $noFirstOver
			;
		}

		return !$this->isDataConnectionDisabled() && $noFirstOver;
	}

	/**
	 * Returns true if the data connection (and related functionality) is not locked.
	 *  - For a specific service: just verify that this service is not locked.
	 *  - For the general context: there is no global lock and there are no locked services.
	 */
	public function checkLimit(): bool
	{
		if ($this->service === null)
		{
			return !$this->isDataConnectionDisabled() && empty($this->getLockedServicesList());
		}

		return !$this->isDataConnectionDisabled();
	}

	/**
	 * Event OnAfterSetOption_~controller_group_name handler.
	 *
	 * @param \Bitrix\Main\Event $event Event parameters.
	 * @return void
	 */
	public function licenseChange(\Bitrix\Main\Event $event): void
	{
		Option::delete('biconnector', ['name' => self::OPTION_PREFIX_OVER_LIMIT]);
		Option::delete('biconnector', ['name' => self::OPTION_PREFIX_LAST_LIMIT]);
		Option::delete('biconnector', ['name' => self::OPTION_PREFIX_LOCK]);

		$lockedServicesRaw = Option::get('biconnector', self::LOCKED_SERVICES_OPTION_NAME, '[]');
		try
		{
			$lockedServices = \Bitrix\Main\Web\Json::decode($lockedServicesRaw);
			if (is_array($lockedServices))
			{
				foreach ($lockedServices as $serviceId)
				{
					if (!is_string($serviceId) || $serviceId === '')
					{
						continue;
					}
					Option::delete('biconnector', ['name' => self::OPTION_PREFIX_OVER_LIMIT . '_' . $serviceId]);
					Option::delete('biconnector', ['name' => self::OPTION_PREFIX_LAST_LIMIT . '_' . $serviceId]);
					Option::delete('biconnector', ['name' => self::OPTION_PREFIX_LOCK . '_' . $serviceId]);
					Option::delete('biconnector', ['name' => 'lock_date_ts_' . $serviceId]);
				}
			}
		}
		catch (\Throwable)
		{
		}
		finally
		{
			Option::delete('biconnector', ['name' => self::LOCKED_SERVICES_OPTION_NAME]);
		}
	}
}
