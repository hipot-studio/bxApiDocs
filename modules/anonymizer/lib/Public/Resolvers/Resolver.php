<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Resolvers;

use Bitrix\Anonymizer\Public\Providers\ProviderInterface;

/**
 * Base Resolver. Receives only Provider in constructor.
 *
 * @template TProvider of ProviderInterface = ProviderInterface
 * @implements ResolverInterface<TProvider, mixed>
 */
abstract class Resolver implements ResolverInterface
{
	/** @var TProvider */
	protected ProviderInterface $provider;

	/**
	 * @param TProvider $provider
	 */
	public function __construct(ProviderInterface $provider)
	{
		$this->provider = $provider;
	}

	/**
	 * @return TProvider
	 */
	public function getProvider(): ProviderInterface
	{
		return $this->provider;
	}
}
