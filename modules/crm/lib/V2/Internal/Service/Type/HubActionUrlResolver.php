<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Type;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Internal\Integration\Bizproc\WorkflowTemplateGateway;
use Bitrix\Crm\V2\Internal\Integration\DocumentGenerator\TemplateGateway;
use Bitrix\Crm\V2\Internal\Integration\Intranet\ToolsGateway;
use Bitrix\Crm\V2\Internal\Integration\Recyclebin\ModuleGateway;

/**
 * Builds the addresses of the hub actions of a smart process type for the user of the request.
 *
 * The addresses are legacy paths under the site directory: the router holds no templates for them.
 *
 * @internal
 */
class HubActionUrlResolver
{
	private readonly Container $container;
	private readonly UserPermissions $userPermissions;
	private readonly ToolsGateway $toolsGateway;
	private readonly WorkflowTemplateGateway $workflowTemplateGateway;
	private readonly TemplateGateway $templateGateway;
	private readonly ModuleGateway $recycleBinModuleGateway;

	public function __construct(
		?UserPermissions $userPermissions = null,
		?Container $container = null,
		?ToolsGateway $toolsGateway = null,
		?WorkflowTemplateGateway $workflowTemplateGateway = null,
		?TemplateGateway $templateGateway = null,
		?ModuleGateway $recycleBinModuleGateway = null,
	)
	{
		$this->container = $container ?? Container::getInstance();
		$this->userPermissions = $userPermissions ?? $this->container->getUserPermissions();
		$this->toolsGateway = $toolsGateway ?? new ToolsGateway();
		$this->workflowTemplateGateway = $workflowTemplateGateway ?? new WorkflowTemplateGateway();
		$this->templateGateway = $templateGateway ?? new TemplateGateway();
		$this->recycleBinModuleGateway = $recycleBinModuleGateway ?? new ModuleGateway();
	}

	/**
	 * The keys are the action keys the hub card emits into its handler: an action the user may not
	 * reach is missing from the map rather than carrying an empty address.
	 *
	 * @return array<string, string> action key => address to follow
	 */
	public function getActionUrls(int $entityTypeId): array
	{
		$userId = $this->container->getContext()->getUserId();

		$urls = [
			'bizProcDesigner' => $this->buildBizProcDesignerUrl($entityTypeId, $userId),
			'documentTemplates' => $this->buildDocumentTemplatesUrl($entityTypeId, $userId),
			'requisites' => $this->buildRequisitesUrl(),
			'recycleBin' => $this->buildRecycleBinUrl($entityTypeId),
		];

		return array_filter($urls, static fn(?string $url): bool => $url !== null);
	}

	protected function canManageBizprocTemplates(int $entityTypeId): bool
	{
		return $this->userPermissions->configuration()->canManageBizprocTemplates(
			ConfigurationCallSite::BizprocTemplateComponent,
			ConfigurationScope::ofEntityType($entityTypeId),
		);
	}

	/**
	 * The checks follow the order of the designer page itself: the sources of the designer, then
	 * the right, and only then the type and what the portal has saved about it.
	 */
	private function buildBizProcDesignerUrl(int $entityTypeId, int $userId): ?string
	{
		if (!$this->toolsGateway->isBizprocToolAvailable())
		{
			return null;
		}
		if (!$this->workflowTemplateGateway->isTemplateDesignerAvailable())
		{
			return null;
		}
		if (!$this->canManageBizprocTemplates($entityTypeId))
		{
			return null;
		}
		if (!$this->workflowTemplateGateway->canCreateTemplateForEntityType($entityTypeId, $userId))
		{
			return null;
		}
		if (!$this->isBizProcEnabledForEntityType($entityTypeId))
		{
			return null;
		}

		return $this->siteUrl('crm/configs/bp/CRM_' . \CCrmOwnerType::ResolveName($entityTypeId) . '/');
	}

	private function buildDocumentTemplatesUrl(int $entityTypeId, int $userId): ?string
	{
		return $this->templateGateway->getAddTemplateUrlForEntityType($entityTypeId, $userId);
	}

	/**
	 * The requisites page configures CRM itself, so the type takes no part in the answer.
	 */
	private function buildRequisitesUrl(): ?string
	{
		if (!$this->userPermissions->isCrmAdmin())
		{
			return null;
		}

		return $this->siteUrl('crm/configs/mycompany/');
	}

	/**
	 * The bin of a type is asked about from the cheapest source to the dearest: the module of the
	 * portal, then what the portal has saved about the type, and only then the right of the user.
	 */
	private function buildRecycleBinUrl(int $entityTypeId): ?string
	{
		if (!$this->recycleBinModuleGateway->isModuleAvailable())
		{
			return null;
		}
		if (!$this->isRecycleBinEnabledForEntityType($entityTypeId))
		{
			return null;
		}
		if (!$this->userPermissions->entityType()->canReadItems($entityTypeId))
		{
			return null;
		}

		return $this->siteUrl('crm/recyclebin/') . '?entityTypeId=' . $entityTypeId;
	}

	private function isBizProcEnabledForEntityType(int $entityTypeId): bool
	{
		return $this->container->getFactory($entityTypeId)?->isBizProcEnabled() ?? false;
	}

	private function isRecycleBinEnabledForEntityType(int $entityTypeId): bool
	{
		return $this->container->getFactory($entityTypeId)?->isRecyclebinEnabled() ?? false;
	}

	private function siteUrl(string $path): string
	{
		return rtrim(SITE_DIR, '/') . '/' . $path;
	}
}
