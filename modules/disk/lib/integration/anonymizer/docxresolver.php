<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Anonymizer;

use Bitrix\Anonymizer\Internal\Exceptions\AnonymizerException;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;
use Bitrix\Anonymizer\Public\Resolvers\ReplaceResolver;

/**
 * Applies anonymize_text replacements to DOCX structure and restores placeholders on deanonymize.
 *
 * @extends ReplaceResolver<DocxProvider, int>
 */
final class DocxResolver extends ReplaceResolver
{
	/**
	 * @return DocxProvider
	 */
	public function getProvider(): DocxProvider
	{
		$provider = parent::getProvider();
		if (!$provider instanceof DocxProvider)
		{
			throw new AnonymizerException('DocxResolver requires ' . DocxProvider::class);
		}

		return $provider;
	}

	public function getAnonymized(): string
	{
		$provider = $this->getProvider();

		if ($this->replacements === null)
		{
			return $provider->getContent();
		}

		$provider->applyReplacements($this->replacements);

		return $provider->getContent();
	}

	/**
	 * Restores deanonymized DOCX to Disk. Returns Disk object ID.
	 *
	 * @param DocxProvider $providerWithAnonymizedContent
	 */
	public function restoreDeanonymized(ProviderInterface $providerWithAnonymizedContent): int
	{
		if (!$providerWithAnonymizedContent instanceof DocxProvider)
		{
			return 0;
		}

		if ($this->replacements !== null)
		{
			$providerWithAnonymizedContent->restorePlaceholders($this->replacements);
		}

		return $providerWithAnonymizedContent->save();
	}
}
