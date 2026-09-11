<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\Repositories\ServerPersister\TokenWriterServerPersister;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Public\Dto\ServerConnectionDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

abstract class AbstractPublicEntryPoint extends AbstractSupersetContext
{
	public function __construct(
		ServerReferenceDto|ServerConnectionDto $server,
		?TokenWriterInterface $tokenWriter = null,
	)
	{
		$resolvedServer = (new ServerResolver())->resolve($server);

		parent::__construct(
			$resolvedServer,
			$tokenWriter === null
				? null
				: new SupersetInstance($resolvedServer, [], new TokenWriterServerPersister($tokenWriter))
			,
		);
	}
}
