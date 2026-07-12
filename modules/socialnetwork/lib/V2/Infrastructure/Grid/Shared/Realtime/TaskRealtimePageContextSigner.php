<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime;

use Bitrix\Main\Component\ParameterSigner;
use Bitrix\Main\Security\Sign\BadSignatureException;

class TaskRealtimePageContextSigner
{
	private const SALT = 'socialnetwork.v2.project.list.rt.page.ctx';

	public static function sign(TaskRealtimePageContext $pageContext): string
	{
		return ParameterSigner::signParameters(self::SALT, $pageContext->toArray());
	}

	public static function unsign(string $signedPageContext): ?TaskRealtimePageContext
	{
		$signedPageContext = trim($signedPageContext);
		if ($signedPageContext === '')
		{
			return null;
		}

		try
		{
			$pageContext = ParameterSigner::unsignParameters(self::SALT, $signedPageContext);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		return is_array($pageContext)
			? TaskRealtimePageContext::mapFromArray($pageContext)
			: null
		;
	}
}
