<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Contract\Application;

interface OAuthToken
{
	public function getAccessToken(): string;

	public function getRefreshToken(): ?string;

	public function getUserId(): int;

//	public function getClientId(): string;
//
	public function getExpiresIn(): ?int;
//
//	public function getDateFinish(): ?int;
//
//	public function getDomain(): ?string;
//
//	public function getMemberId(): ?string;
//
	public function getServerEndpoint(): ?string;
//
//	public function getClientEndpoint(): ?string;
//
//	public function getStatus(): ?string;
//
//	public function getScope(): ?string;
}
