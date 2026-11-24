<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Response;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\AgentSessionDto;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * Response of bizprocdesigner.agent.session.get and .add.
 *
 * A response of its own rather than the crud GetResponse, for the same reason
 * {@see ConnectResponse} is one: the session is computed from the token store, not read as a stored
 * resource, so the action fills the dto itself and no mapper stands between them.
 */
final class SessionResponse extends Response
{
	public function __construct(public AgentSessionDto $item)
	{
	}
}
