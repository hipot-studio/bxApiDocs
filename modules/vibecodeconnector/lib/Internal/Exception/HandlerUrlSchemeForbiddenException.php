<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

final class HandlerUrlSchemeForbiddenException extends ProvisioningFailedException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(
			'Handler URL scheme must be http or https',
			'APPKEY_HANDLER_URL_SCHEME_FORBIDDEN',
			previous: $previous,
		);
	}
}
