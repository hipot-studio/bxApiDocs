<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Resolvers;

use Bitrix\Anonymizer\Public\Providers\ProviderInterface;

/**
 * Works with a data provider and optional result data (replacements, etc.).
 * Provider is required; each result type has its own setter (setReplacements, etc.).
 *
 * @template-covariant TProvider of ProviderInterface = ProviderInterface
 * @template TDeanonymizeResult = mixed
 */
interface ResolverInterface
{
	/**
	 * Provider (source data). Passed at construction.
	 *
	 * @return TProvider
	 */
	public function getProvider(): ProviderInterface;
}
