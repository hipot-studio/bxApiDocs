<?php

namespace Bitrix\Bizproc\Internal\Service;

use Bitrix\Bizproc\Internal\Service\Activity\ActionCatalogMap;
use Bitrix\Bizproc\Internal\Service\Activity\CapabilityCatalogService;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\ManualStartTemplateAvailabilityService;
use Bitrix\Bizproc\Internal\Trait\SingletonTrait;
use Bitrix\Bizproc\Public\Activity\Registry\FilterResultPropertyResolverRegistry;
use Bitrix\Bizproc\Public\Activity\Registry\RelationFieldResolverRegistry;
use Bitrix\Bizproc\Public\Activity\Registry\TargetDocumentAccessGuardRegistry;
use Bitrix\Bizproc\Public\Activity\Registry\TargetDocumentResolverRegistry;
use Bitrix\Bizproc\Public\Activity\Service\DocumentContextResolver;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\DI\ServiceLocator;
use Psr\Container\ContainerInterface;

class Container
{
	use SingletonTrait;

	private static ?ContainerInterface $serviceLocator = null;

	protected static function getServiceLocator(): ContainerInterface
	{
		if (self::$serviceLocator === null)
		{
			self::$serviceLocator = ServiceLocator::getInstance();
		}

		return self::$serviceLocator;
	}

	private static function getService(string $name): mixed
	{
		$prefix = 'bizproc.';
		if (mb_strpos($name, $prefix) !== 0)
		{
			$name = $prefix . $name;
		}

		$locator = self::getServiceLocator();

		return $locator->has($name)
			? $locator->get($name)
			: null
		;
	}

	public function getComplexActivityService(): ComplexActivityService
	{
		return self::getService('bizproc.service.activity.complex');
	}

	public function getCapabilityCatalogService(): CapabilityCatalogService
	{
		return self::getService('bizproc.service.activity.capabilityCatalog');
	}

	public function getUnifiedPanelDescriptorProvider(): UnifiedPanelDescriptorProvider
	{
		return self::getService('bizproc.service.activity.unifiedPanelDescriptor');
	}

	public function getActionCatalogMapService(): ActionCatalogMap
	{
		return self::getService('bizproc.service.activity.actionCatalogMap');
	}

	public function getActivitySearcherService(): Searcher
	{
		return static::getService('bizproc.runtime.activitysearcher.searcher');
	}

	public function getEvalService(): EvalService
	{
		return static::getService('bizproc.service.eval');
	}

	public function getTargetDocumentResolverRegistry(): TargetDocumentResolverRegistry
	{
		return static::getService('bizproc.service.activity.targetDocumentResolverRegistry');
	}

	public function getDocumentContextResolver(): DocumentContextResolver
	{
		return static::getService('bizproc.service.activity.documentContextResolver');
	}

	public function getTargetDocumentAccessGuardRegistry(): TargetDocumentAccessGuardRegistry
	{
		return static::getService('bizproc.service.activity.targetDocumentAccessGuardRegistry');
	}

	public function getFilterResultPropertyResolverRegistry(): FilterResultPropertyResolverRegistry
	{
		return static::getService('bizproc.service.activity.filterResultPropertyResolverRegistry');
	}

	public function getRelationFieldResolverRegistry(): RelationFieldResolverRegistry
	{
		return static::getService('bizproc.service.activity.relationFieldResolverRegistry');
	}

	public function getManualStartTemplateAvailabilityService(): ManualStartTemplateAvailabilityService
	{
		return static::getService('bizproc.service.workflowTemplate.manualStartAvailability');
	}
}
