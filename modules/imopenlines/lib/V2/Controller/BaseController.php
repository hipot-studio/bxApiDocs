<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\Im;
use Bitrix\ImOpenLines\V2\Session\Session;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

Loader::requireModule('im');

abstract class BaseController extends Im\V2\Controller\BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				Session::class,
				'session',
				function ($className, int $sessionId) {
					$session = Session::getInstance($sessionId);
					if ($session === null || $session->getSessionId() === null)
					{
						$this->addError(new Error('Session not found', 'SESSION_NOT_FOUND'));

						return null;
					}

					return $session;
				}
			),
		], parent::getAutoWiredParameters());
	}

	protected function getDefaultPreFilters()
	{
		return array_merge(
			[
				new \Bitrix\Intranet\ActionFilter\IntranetUser(),
			],
			parent::getDefaultPreFilters(),
		);
	}

	/**
	 * Parses a documented REST boolean filter value into a strict bool, or null when it
	 * should not be applied as a filter at all.
	 *
	 * Unlike {@see parent::convertCharToBool()} (`Bitrix\Im\V2\Controller\BaseController`),
	 * which only recognizes the legacy 'Y'/'N' convention and otherwise silently falls
	 * back to a default, the imopenlines.v2 statistics REST methods document plain
	 * `true`/`false` boolean parameters. Those arrive here as a `?string` (PHP's weak
	 * scalar coercion turns a JSON `true`/`false` argument into `'1'`/`''` before this
	 * method's body even runs), so `''` is treated the same as an explicit `false`, not
	 * as "absent" (only an actual `null` means "absent").
	 *
	 * Recognized as true: 'Y', '1', 'TRUE' (case-insensitive).
	 * Recognized as false: 'N', '0', 'FALSE' (case-insensitive), '' (see above).
	 * Anything else (including unrecognized strings) returns null, meaning: do not
	 * apply the filter - the caller sent a value that cannot be trusted to mean either
	 * yes or no.
	 */
	protected function parseBoolFilter(?string $value): ?bool
	{
		if ($value === null)
		{
			return null;
		}

		if ($value === '')
		{
			return false;
		}

		$normalized = mb_strtoupper(trim($value));

		if (in_array($normalized, ['Y', '1', 'TRUE'], true))
		{
			return true;
		}

		if (in_array($normalized, ['N', '0', 'FALSE'], true))
		{
			return false;
		}

		return null;
	}
}
