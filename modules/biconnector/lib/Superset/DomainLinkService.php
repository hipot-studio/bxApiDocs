<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Registrar;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class DomainLinkService
{
	private const OPTION_SUPERSET_UNLINKED = '~is_superset_unlinked';

	private static ?self $instance = null;
	private static ?bool $linkStatusCache = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Checks if superset is linked to the current portal domain.
	 *
	 * @return bool
	 */
	public function isLinked(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			// No case of 2 B24 instances linked to superset, cloud always has 1:1 relation
			return true;
		}

		if (self::$linkStatusCache !== null)
		{
			return self::$linkStatusCache;
		}

		if (!Registrar::getRegistrar()->isComplete())
		{
			return true;
		}

		$isUnlinked = Option::get('biconnector', self::OPTION_SUPERSET_UNLINKED, 'N') === 'Y';
		self::$linkStatusCache = !$isUnlinked;

		return self::$linkStatusCache;
	}

	/**
	 * Resets the unlinked status, allowing requests to proxy again.
	 */
	public function clearUnlinkedStatus(): void
	{
		Option::delete('biconnector', ['name' => self::OPTION_SUPERSET_UNLINKED]);
		self::$linkStatusCache = null;
	}

	/**
	 * Marks superset as unlinked (domain mismatch detected).
	 * Blocks all requests to proxy until admin explicitly re-links the domain.
	 */
	public function setUnlinked(): void
	{
		Option::set('biconnector', self::OPTION_SUPERSET_UNLINKED, 'Y');
		self::$linkStatusCache = false;
	}

	/**
	 * Links superset address to the current portal domain.
	 *
	 * @return Result
	 */
	public function linkAddress(): Result
	{
		$result = new Result();

		if (Loader::includeModule('bitrix24'))
		{
			return $result;
		}

		$response = Integrator::getInstance()->refreshDomainConnection();

		if (!$response->hasErrors())
		{
			$this->clearUnlinkedStatus();
		}
		else
		{
			$result->addErrors($response->getErrors());
		}

		return $result;
	}
}
