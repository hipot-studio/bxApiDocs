<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

final class EndpointResolver
{
	private const PATH_MICROSERVICE = '/microservice';
	private const PATH_EMBED_OPEN = '/embed/b24/open';
	private const PATH_STATUS = '/status';
	private const PATH_PUBLIC_KEY = '/static/public-key.pem';

	public function __construct(private readonly string $baseUrl)
	{
	}

	public function microservice(): string
	{
		return $this->join(self::PATH_MICROSERVICE);
	}

	public function embedOpen(): string
	{
		return $this->join(self::PATH_EMBED_OPEN);
	}

	public function status(): string
	{
		return $this->join(self::PATH_STATUS);
	}

	public function publicKey(): string
	{
		return $this->join(self::PATH_PUBLIC_KEY);
	}

	private function join(string $path): string
	{
		return rtrim($this->baseUrl, '/') . $path;
	}
}
