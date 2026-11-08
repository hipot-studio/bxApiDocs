<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\MailTemplate;

use Bitrix\Crm\V2\Internal\Repository\MailTemplate\LegacyMailTemplateRepository;
use Bitrix\Crm\V2\Internal\Service\MailTemplate\MailTemplateCreationService;
use Bitrix\Crm\V2\Internal\Service\MailTemplate\MailTemplatePreparationService;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\MailTemplate;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\MailTemplatePage;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\PersonalMailTemplateData;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\PreparedMailTemplate;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\PreparedMailTemplateAttachment;
use Bitrix\Main\Result;

final class MailTemplateProvider
{
	private LegacyMailTemplateRepository $repository;
	private MailTemplatePreparationService $preparationService;
	private MailTemplateCreationService $creationService;

	public function __construct()
	{
		$this->repository = new LegacyMailTemplateRepository();
		$this->preparationService = new MailTemplatePreparationService($this->repository);
		$this->creationService = new MailTemplateCreationService($this->repository);
	}

	public function getList(
		int $userId,
		int $offset = 0,
		int $limit = 20,
		bool $includeContextual = true,
	): MailTemplatePage
	{
		return $this->mapPage($this->repository->getList(
			userId: $userId,
			offset: $offset,
			limit: $limit,
			includeContextual: $includeContextual,
		));
	}

	public function search(
		string $query,
		int $userId,
		int $offset = 0,
		int $limit = 20,
		bool $includeContextual = true,
	): MailTemplatePage
	{
		return $this->mapPage($this->repository->getList(
			userId: $userId,
			search: $query,
			offset: $offset,
			limit: $limit,
			includeContextual: $includeContextual,
		));
	}

	/**
	 * The returned templates follow the order of the first occurrence of each valid requested id.
	 *
	 * @param int[] $templateIds
	 * @return list<MailTemplate>
	 */
	public function getByIds(array $templateIds, int $userId): array
	{
		return array_map(
			$this->mapTemplate(...),
			$this->repository->getByIds($templateIds, $userId),
		);
	}

	/**
	 * @return list<MailTemplate>
	 */
	public function getRecentlyCreated(
		int $userId,
		int $limit = 5,
		bool $includeContextual = true,
	): array
	{
		return array_map(
			$this->mapTemplate(...),
			$this->repository->getRecentlyCreated($userId, $limit, $includeContextual),
		);
	}

	/**
	 * Returns null for a missing, inactive, inaccessible or contextual template.
	 */
	public function prepareUniversal(int $templateId, int $userId): ?PreparedMailTemplate
	{
		$result = $this->preparationService->prepareUniversal($templateId, $userId);
		if (!$result->isSuccess())
		{
			return null;
		}

		$data = $result->getData();

		return new PreparedMailTemplate(
			id: (int)$data['id'],
			subject: (string)$data['subject'],
			bodyHtml: (string)$data['bodyHtml'],
			attachments: array_map($this->mapPreparedAttachment(...), $data['attachments'] ?? []),
		);
	}

	/**
	 * Creates an active personal universal HTML mail template for the user.
	 *
	 * @return Result Data shape on success: array{templateId: int}
	 */
	public function createPersonalUniversal(PersonalMailTemplateData $data, int $userId): Result
	{
		return $this->creationService->createPersonalUniversal(
			subject: $data->getSubject(),
			bodyHtml: $data->getBodyHtml(),
			emailFrom: $data->getEmailFrom(),
			diskObjectIds: $data->getDiskObjectIds(),
			userId: $userId,
		);
	}

	/**
	 * @param array{
	 *     items: list<array{
	 *         id: int,
	 *         title: string,
	 *         subject: string,
	 *         scope: int,
	 *         entityTypeId: int,
	 *         bodyType: int
	 *     }>,
	 *     hasMore: bool
	 * } $page
	 */
	private function mapPage(array $page): MailTemplatePage
	{
		return new MailTemplatePage(
			items: array_map($this->mapTemplate(...), $page['items']),
			hasMore: $page['hasMore'],
		);
	}

	/**
	 * @param array{
	 *     id: int,
	 *     title: string,
	 *     subject: string,
	 *     scope: int,
	 *     entityTypeId: int,
	 *     bodyType: int
	 * } $template
	 */
	private function mapTemplate(array $template): MailTemplate
	{
		return new MailTemplate(
			id: $template['id'],
			title: $template['title'],
			scope: $template['scope'],
			entityTypeId: $template['entityTypeId'],
			subject: $template['subject'],
		);
	}

	/**
	 * @param array{id: int, name: string, size: int, contentType: string|null} $attachment
	 */
	private function mapPreparedAttachment(array $attachment): PreparedMailTemplateAttachment
	{
		return new PreparedMailTemplateAttachment(
			id: (int)$attachment['id'],
			name: (string)$attachment['name'],
			size: (int)$attachment['size'],
			contentType: isset($attachment['contentType']) ? (string)$attachment['contentType'] : null,
		);
	}
}
