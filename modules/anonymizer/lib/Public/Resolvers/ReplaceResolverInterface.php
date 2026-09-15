<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Resolvers;

use Bitrix\Anonymizer\Internal\Services\Replacement\Storage;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;

/**
 * Resolver for replace-based anonymization: replacements + text in/out.
 *
 * @template TProvider of ProviderInterface
 * @template TDeanonymizeResult
 * @extends ResolverInterface<TProvider, TDeanonymizeResult>
 */
interface ReplaceResolverInterface extends ResolverInterface
{
	/**
	 * Sets the replacements result. Other result types may have their own setters.
	 */
	public function setReplacements(Storage $replacements): static;

	/**
	 * Builds anonymized text from the provider and any result data set via setters.
	 *
	 * @return string Final anonymized text (or result representation)
	 */
	public function getAnonymized(): string;

	/**
	 * Restores original content by replacing placeholders in the given provider.
	 * Uses replacements already set on the resolver (e.g. via setReplacements after AnonymizeText result).
	 *
	 * @param TProvider $providerWithAnonymizedContent Provider with anonymized content (placeholders).
	 * @return TDeanonymizeResult Restored (deanonymized) result.
	 */
	public function restoreDeanonymized(ProviderInterface $providerWithAnonymizedContent): mixed;
}
