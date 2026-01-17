<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\FileUploader;

use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\ConstantsFileService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\UploaderController;

class SetupTemplateUploaderController extends UploaderController
{
	public const OPTION_TEMPLATE_ID = 'templateId';
	private readonly ConstantsFileService $constantsFileService;

	public function __construct(array $options = [])
	{
		$options[self::OPTION_TEMPLATE_ID] = (int)($options[self::OPTION_TEMPLATE_ID] ?? 0);
		$this->constantsFileService = ServiceLocator::getInstance()->get(ConstantsFileService::class);

		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		return CurrentUser::get()->getId() > 0;
	}

	public function getConfiguration(): Configuration
	{
		return new Configuration();
	}

	public function canUpload(): bool
	{
		return $this->isAvailable();
	}

	public function canView(): bool
	{
		return $this->isAvailable();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$ownFileIds = $this->getPersistentFileIds();
		foreach ($files as $file)
		{
			$file->markAsOwn(in_array($file->getId(), $ownFileIds, true));
		}
	}

	public function canRemove(): bool
	{
		return false;
	}

	public function getOptionTemplateId(): int
	{
		return (int)$this->getOption(self::OPTION_TEMPLATE_ID);
	}

	/**
	 * @return list<int>
	 */
	private function getPersistentFileIds(): array
	{
		$templateId = $this->getOptionTemplateId();
		if ($templateId <= 0)
		{
			return [];
		}

		if (!$this->isCurrentUserHasAccessToTemplate($templateId))
		{
			return [];
		}

		return $this->constantsFileService->getFileIdsByTemplateId($templateId);
	}

	private function isCurrentUserHasAccessToTemplate(int $templateId): bool
	{
		$template = WorkflowTemplateTable::query()
			 ->where('ID', $templateId)
			 ->setLimit(1)
			 ->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			 ->fetchObject()
		;

		if ($template === null)
		{
			return false;
		}

		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::StartWorkflow,
			$user->getId(),
			$template->getDocumentComplexType()
		);
	}
}