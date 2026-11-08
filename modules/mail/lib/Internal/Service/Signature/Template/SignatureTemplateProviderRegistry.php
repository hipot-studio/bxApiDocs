<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

final class SignatureTemplateProviderRegistry
{
	/** @var array<string, SignatureTemplateProvider> */
	private array $providers = [];

	public static function createDefault(): self
	{
		return new self([new ImageSignatureTemplateProvider()]);
	}

	/** @param iterable<SignatureTemplateProvider> $providers */
	public function __construct(iterable $providers)
	{
		foreach ($providers as $provider)
		{
			$type = trim($provider->getType());
			if ($type === '')
			{
				throw new \LogicException('A signature template provider type must not be empty.');
			}
			if (isset($this->providers[$type]))
			{
				throw new \LogicException("A signature template provider for type {$type} is already registered.");
			}

			$this->providers[$type] = $provider;
		}
	}

	public function get(string $type): ?SignatureTemplateProvider
	{
		return $this->providers[$type] ?? null;
	}

	/** @return SignatureTemplateProvider[] */
	public function getAll(): array
	{
		return array_values($this->providers);
	}
}
