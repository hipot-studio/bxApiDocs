<?php

namespace Bitrix\BIConnector\Superset\Error;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Logger\PresentedErrorLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

final class IntegrationErrorPresenter
{
	public const
		ERROR_NOT_READY = 'SUPERSET_NOT_READY',
		ERROR_LIMIT_EXCEEDED = 'SUPERSET_LIMIT_EXCEEDED',
		ERROR_INTEGRATION = 'INTEGRATION_ERROR';

	/**
	 * @param Error[] $sourceErrors
	 */
	public static function present(array $sourceErrors, string $actionMessage): UserFacingError
	{
		if (!empty($sourceErrors))
		{
			PresentedErrorLogger::logErrors($sourceErrors, ['PRESENTED_AS' => $actionMessage]);
		}

		$status = SupersetInitializer::getSupersetStatus();

		if ($status === SupersetInitializer::SUPERSET_STATUS_LOAD)
		{
			return new UserFacingError(
				Loc::getMessage('BICONNECTOR_SUPERSET_ERROR_PRESENTER_NOT_READY'),
				self::ERROR_NOT_READY,
			);
		}

		if ($status === SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED)
		{
			return new UserFacingError(
				Loc::getMessage('BICONNECTOR_SUPERSET_ERROR_PRESENTER_LIMIT_EXCEEDED'),
				self::ERROR_LIMIT_EXCEEDED,
			);
		}

		return new UserFacingError($actionMessage, self::ERROR_INTEGRATION);
	}
}
