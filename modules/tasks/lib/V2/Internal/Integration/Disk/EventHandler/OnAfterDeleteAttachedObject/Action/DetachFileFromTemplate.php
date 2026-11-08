<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject\Action;

use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\FileBbCodeService;
use Bitrix\Tasks\V2\Internal\Repository\Compatibility\TemplateRepository;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTemplateService;
use Throwable;

class DetachFileFromTemplate
{
	use DetachFileFromUserFieldTrait;

	public function __construct(
		private readonly ?TemplateRepository $compatibilityRepository = null,
		private readonly ?FileBbCodeService $fileBbCodeService = null,
		private readonly ?UpdateTemplateService $updateService = null,
	)
	{
	}

	public function __invoke(int $templateId, int $attachId, int $objectId): void
	{
		$this->detachFileFromUserField(UserField::TEMPLATE, $templateId, $attachId);
		$this->detachFileFromDescription($templateId, $attachId, $objectId);
	}

	private function detachFileFromDescription(int $templateId, int $attachId, int $objectId): void
	{
		try
		{
			$container = Container::getInstance();
			$compatibilityRepository = $this->compatibilityRepository ?? $container->getTemplateCompatabilityRepository();
			$fileBbCodeService = $this->fileBbCodeService ?? $container->get(FileBbCodeService::class);
			$updateService = $this->updateService ?? $container->getUpdateTemplateService();

			$fullData = $compatibilityRepository->getTemplateData($templateId);

			$cleanedDescription = $this->stripDetachedFileFromDescription(
				$fileBbCodeService,
				(string)($fullData['DESCRIPTION'] ?? ''),
				$attachId,
				$objectId,
			);
			if ($cleanedDescription === null)
			{
				return;
			}

			$template = new Entity\Template(
				id: $templateId,
				description: $cleanedDescription,
			);

			$config = new UpdateConfig(
				userId: (int)($fullData['CREATED_BY'] ?? 0),
				skipAgent: true,
			);

			$updateService->update($template, $config);
		}
		catch (TemplateNotFoundException)
		{
			return;
		}
		catch (Throwable $exception)
		{
			LogFacade::logThrowable($exception);
		}
	}
}
