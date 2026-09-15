<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Resolvers;

use Bitrix\Anonymizer\Internal\Services\Replacement\Storage;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;

/**
 * Resolver that works with replacements.
 * Takes only provider; replacements are set via setReplacements() when available (e.g. in callback).
 *
 * @template TProvider of ProviderInterface
 * @template TDeanonymizeResult
 * @extends Resolver<TProvider>
 * @implements ReplaceResolverInterface<TProvider, TDeanonymizeResult>
 */
abstract class ReplaceResolver extends Resolver implements ReplaceResolverInterface
{
	protected ?Storage $replacements = null;

	/**
	 * @param TProvider $provider
	 */
	public function __construct(ProviderInterface $provider)
	{
		parent::__construct($provider);
	}

	public function setReplacements(Storage $replacements): static
	{
		$this->replacements = $replacements;

		return $this;
	}
}
