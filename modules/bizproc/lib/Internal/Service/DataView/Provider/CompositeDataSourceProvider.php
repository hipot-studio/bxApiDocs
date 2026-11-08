<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Provider;

use Bitrix\Bizproc\Public\DataView\Dto\ExtractQuery;
use Bitrix\Bizproc\Public\DataView\Dto\ResolvedStampConstant;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Dto\StampConstantDescriptor;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\PresentableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\SourceAvailabilityCapableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\StampConstantProvider;
use Bitrix\Bizproc\Public\DataView\Interface\TemplateContextCapableProvider;

/**
 * The single native provider of the module: the registry resolves one provider per module,
 * while bizproc serves several kinds of sources. Dispatches by the entity of the reference and
 * keeps the composition invisible to the registry, to the engine and to external modules.
 */
final class CompositeDataSourceProvider implements
	DataSourceProvider,
	TemplateContextCapableProvider,
	StampConstantProvider,
	PresentableProvider,
	SourceAvailabilityCapableProvider
{
	public const MODULE_ID = 'bizproc';

	public function __construct(
		private readonly DataSourceProvider $storageProvider,
		private readonly DataSourceProvider $variablesProvider,
	) {
	}

	public function getModuleId(): string
	{
		return self::MODULE_ID;
	}

	/**
	 * @return SourceDescriptor[]
	 */
	public function getAvailableSources(int $actorId): array
	{
		return array_merge(
			$this->storageProvider->getAvailableSources($actorId),
			$this->variablesProvider->getAvailableSources($actorId),
		);
	}

	/**
	 * Each composed provider contributes its own catalog for the context: the ones aware of a
	 * template answer with the contextual catalog, the rest with their context-free one.
	 *
	 * @return SourceDescriptor[]
	 */
	public function getAvailableSourcesForTemplate(int $templateId, int $actorId): array
	{
		return array_merge(
			$this->collectSources($this->storageProvider, $templateId, $actorId),
			$this->collectSources($this->variablesProvider, $templateId, $actorId),
		);
	}

	/**
	 * The composed provider that owns the entity answers about the source itself when it can;
	 * otherwise the source is looked up in the catalog of that provider alone, not of the whole module.
	 */
	public function isSourceAvailable(SourceRef $source, int $actorId, ?int $templateId): bool
	{
		try
		{
			$provider = $this->resolveProvider($source);
		}
		catch (SourceUnavailableException)
		{
			return false;
		}

		if ($provider instanceof SourceAvailabilityCapableProvider)
		{
			return $provider->isSourceAvailable($source, $actorId, $templateId);
		}

		$descriptors = ($templateId !== null && $templateId > 0)
			? $this->collectSources($provider, $templateId, $actorId)
			: $provider->getAvailableSources($actorId)
		;

		$identity = $source->identity();
		foreach ($descriptors as $descriptor)
		{
			if ($descriptor->toSourceRef()->identity() === $identity)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function getSourceSchema(SourceRef $source): SourceSchema
	{
		return $this->resolveProvider($source)->getSourceSchema($source);
	}

	/**
	 * @return iterable<array<string, scalar|null|array>>
	 * @throws SourceUnavailableException
	 */
	public function extract(SourceRef $source, ExtractQuery $query, int $actorId): iterable
	{
		return $this->resolveProvider($source)->extract($source, $query, $actorId);
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function getRelations(SourceRef $source): array
	{
		return $this->resolveProvider($source)->getRelations($source);
	}

	/**
	 * @param iterable<int|string, array<string, mixed>> $rows
	 * @return iterable<int|string, array<string, string>>
	 * @throws SourceUnavailableException
	 */
	public function present(SourceRef $source, iterable $rows, int $actorId = 0): iterable
	{
		$provider = $this->resolveProvider($source);

		// Trailing actor argument outside the declared contract, as in {@see PresentableProvider::present()}.
		return $provider instanceof PresentableProvider ? $provider->present($source, $rows, $actorId) : [];
	}

	/**
	 * @return StampConstantDescriptor[]
	 */
	public function getAvailableStampConstants(int $actorId, ?int $templateId = null): array
	{
		$constants = [];
		foreach ([$this->storageProvider, $this->variablesProvider] as $provider)
		{
			if ($provider instanceof StampConstantProvider)
			{
				array_push($constants, ...$provider->getAvailableStampConstants($actorId, $templateId));
			}
		}

		return $constants;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function resolveStampConstant(SourceRef $constant): ResolvedStampConstant
	{
		$provider = $this->resolveProvider($constant);
		if (!$provider instanceof StampConstantProvider)
		{
			throw SourceUnavailableException::sourceEntityUnknown($constant->entity);
		}

		return $provider->resolveStampConstant($constant);
	}

	/**
	 * @return SourceDescriptor[]
	 */
	private function collectSources(DataSourceProvider $provider, int $templateId, int $actorId): array
	{
		return $provider instanceof TemplateContextCapableProvider
			? $provider->getAvailableSourcesForTemplate($templateId, $actorId)
			: $provider->getAvailableSources($actorId)
		;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function resolveProvider(SourceRef $source): DataSourceProvider
	{
		if ($source->entity === StorageDataSourceProvider::ENTITY)
		{
			return $this->storageProvider;
		}

		if (in_array($source->entity, VariablesDataSourceProvider::ENTITIES, true))
		{
			return $this->variablesProvider;
		}

		throw SourceUnavailableException::sourceEntityUnknown($source->entity);
	}
}
