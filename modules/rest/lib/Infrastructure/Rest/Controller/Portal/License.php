<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller\Portal;

use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\Internal\Entity;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

class License extends RestController
{
	#[Title('Gets portal license information')]
	#[Scope(RestDeveloper::SCOPE)]
	public function getAction(
		Entity\Portal\License $license,
		Entity\Portal\MarketSubscription $marketSubscription,
	): ArrayResponse
	{
		return new ArrayResponse(
			[
				'portal' => $license->toArray(),
				'market' => $marketSubscription->toArray(),
			],
		);
	}
}
