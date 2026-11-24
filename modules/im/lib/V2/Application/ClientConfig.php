<?php

namespace Bitrix\Im\V2\Application;

/**
 * The startup config for a client outside the web page. The active calls carry the call tokens,
 * so they are served only to a caller entitled to the "call" scope; otherwise the key stays empty.
 */
final class ClientConfig extends Config
{
	private readonly bool $withActiveCalls;

	public function __construct(Context $applicationContext, bool $withActiveCalls)
	{
		parent::__construct($applicationContext);
		$this->withActiveCalls = $withActiveCalls;
	}

	protected function getActiveCalls(): array
	{
		if (!$this->withActiveCalls)
		{
			return [];
		}

		return parent::getActiveCalls();
	}
}
