<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

abstract class AbstractPublicEntryPoint extends AbstractSupersetContext
{
	public function __construct(ServerReferenceDto $server)
	{
		parent::__construct((new ServerResolver())->resolve($server));
	}
}
