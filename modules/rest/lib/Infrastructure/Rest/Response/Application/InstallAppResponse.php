<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Response\Application;

use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AppDto;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\OAuthTokenDto;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

class InstallAppResponse extends GetResponse
{
	public function __construct(AppDto $app, public OAuthTokenDto $oauthToken)
	{
		parent::__construct($app);
	}
}
