<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Service\DataView\ColumnResolver;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRelation;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\SourceAvailabilityCapableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\StampConstantProvider;
use Bitrix\Bizproc\Public\DataView\Interface\TemplateContextCapableProvider;
use Bitrix\Bizproc\Public\DataView\Registry\DataSourceProviderRegistry;
use Bitrix\Main\DI\ServiceLocator;

final class SourceCatalogProvider
{
	private DataSourceProviderRegistry $providerRegistry;

	public function __construct()
	{
		$this->providerRegistry = ServiceLocator::getInstance()->get('bizproc.service.dataView.providerRegistry');
	}

	/**
	 * @param int|null $templateId context of the catalog; null or a non-positive value means none
	 * @return SourceDescriptor[]
	 */
	public function getSources(int $actorId, ?int $templateId = null): array
	{
		$sources = [];

		foreach ($this->providerRegistry->getAvailableProviders() as $provider)
		{
			foreach ($this->collectSources($provider, $actorId, $templateId) as $descriptor)
			{
				$sources[] = $descriptor;
			}
		}

		return $sources;
	}

	public function getStampConstants(int $actorId, ?int $templateId = null): array
	{
		$constants = [];

		foreach ($this->providerRegistry->getAvailableProviders() as $provider)
		{
			if ($provider instanceof StampConstantProvider)
			{
				array_push(
					$constants,
					...$provider->getAvailableStampConstants($actorId, $templateId),
				);
			}
		}

		return $constants;
	}

	/**
	 * @param int|null $templateId context the source is requested in; availability is checked
	 *                             against the catalog of that very context
	 * @return array{fields: SourceField[], relations: SourceRelation[]}
	 * @throws SourceUnavailableException
	 */
	public function getSchema(SourceRef $source, int $actorId, ?int $templateId = null): array
	{
		if (!$this->isSourceAvailableToActor($source, $actorId, $templateId))
		{
			throw SourceUnavailableException::sourceNotAvailableToActor($source->module, $source->entity);
		}

		$provider = $this->providerRegistry->get($source->module);
		if ($provider === null)
		{
			throw new SourceUnavailableException(
				sprintf('DataView source provider for module "%s" is unavailable', $source->module)
			);
		}

		return [
			'fields' => $provider->getSourceSchema($source)->getFields(),
			'relations' => $provider->getRelations($source),
		];
	}

	/**
	 * Reading rows of a definition is no weaker a right than reading the schema of a source: every
	 * source the definition draws rows from is checked against the catalog of the very context the
	 * definition belongs to.
	 *
	 * @param int|null $ownerTemplateId template the definition belongs to; null means no context
	 * @throws SourceUnavailableException
	 */
	public function assertDefinitionSourcesAvailable(array $definition, int $actorId, ?int $ownerTemplateId): void
	{
		foreach (ColumnResolver::collectRowSourceRefs($definition) as $source)
		{
			if (!$this->isSourceAvailableToActor($source, $actorId, $ownerTemplateId))
			{
				throw SourceUnavailableException::sourceNotAvailableToActor($source->module, $source->entity);
			}
		}
	}

	/**
	 * A provider able to answer about a single source is asked about it directly: building the whole
	 * catalog to look one source up in it is what a provider of a large catalog cannot afford.
	 */
	private function isSourceAvailableToActor(SourceRef $source, int $actorId, ?int $templateId): bool
	{
		$provider = $this->providerRegistry->get($source->module);
		if ($provider === null)
		{
			return false;
		}

		if ($provider instanceof SourceAvailabilityCapableProvider)
		{
			return $provider->isSourceAvailable($source, $actorId, $templateId);
		}

		$identity = $source->identity();
		foreach ($this->collectSources($provider, $actorId, $templateId) as $descriptor)
		{
			if ($descriptor->toSourceRef()->identity() === $identity)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A provider aware of a template context answers with its contextual catalog; everything else,
	 * including every external provider, is asked exactly as it was before the context existed.
	 *
	 * @return SourceDescriptor[]
	 */
	private function collectSources(DataSourceProvider $provider, int $actorId, ?int $templateId): array
	{
		if ($templateId !== null && $templateId > 0 && $provider instanceof TemplateContextCapableProvider)
		{
			return $provider->getAvailableSourcesForTemplate($templateId, $actorId);
		}

		return $provider->getAvailableSources($actorId);
	}
}
