<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application;

use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class OAuthTokenDto extends Dto
{
	#[Title("OAuth access token")]
	public string $accessToken;

	#[Title("OAuth refresh token")]
	#[Optional]
	public ?string $refreshToken;

	#[Title("Access token lifetime in seconds")]
	#[Optional]
	public ?int $expiresIn;

	#[Title("REST server endpoint")]
	#[Optional]
	public ?string $serverEndpoint;
}
