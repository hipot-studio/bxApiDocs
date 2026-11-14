<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\DockedUploader;

use Bitrix\Disk\Configuration;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Error;

/**
 * Server-side kill switch for the whole docked uploader API surface (#11).
 *
 * The feature is gated by the \Bitrix\Disk\Config\Feature\DockedUploaderFlag feature
 * flag, which is off by default (see Configuration::isEnabledDockedUploader()). Because
 * both the Queue and BizProc controllers are plain authenticated endpoints, without this
 * gate any logged-in user could drive them while the feature is switched off.
 *
 * The check runs in processBeforeAction, i.e. once per controller before ANY
 * action executes (create, updateItem, validateParameters, ...). When the flag
 * is off the action is not run at all: we add a domain error and return false so
 * the dispatcher answers with the standard error envelope. When the flag is on we
 * defer to the engine Controller pipeline (Diag + prefilters) unchanged.
 */
trait DockedUploaderGate
{
	/** Error code returned when the docked uploader feature is switched off. */
	public const ERROR_FEATURE_DISABLED = 'DISK_DOCKED_DISABLED';

	protected function processBeforeAction(Action $action)
	{
		if (!Configuration::isEnabledDockedUploader())
		{
			$this->addError(new Error('Docked uploader is disabled', self::ERROR_FEATURE_DISABLED));

			return false;
		}

		return parent::processBeforeAction($action);
	}
}
