<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Crm\V2\Public\Entity\MailTemplate\MailTemplate;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\MailTemplatePage as CrmTemplatePage;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\PersonalMailTemplateData;
use Bitrix\Crm\V2\Public\Provider\MailTemplate\MailTemplateProvider as PublicCrmTemplateProvider;
use Bitrix\Mail\Internal\Entity\MailTemplate\PreparedTemplate;
use Bitrix\Mail\Internal\Entity\MailTemplate\PreparedTemplateAttachment;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateListItem;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplatePage;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateBody;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Closure;
use Throwable;

final class CrmTemplateProvider implements TemplateProvider
{
	public const SOURCE = 'crm';
	public const DISABLED_REASON_CONTEXT_REQUIRED = 'CRM_CONTEXT_REQUIRED';
	public const ERROR_CREATION_FAILED = 'MAIL_TEMPLATE_CREATION_FAILED';

	private const MAX_OFFSET = 1000;

	/**
	 * A page wider than the quick list, asked of a CRM that cannot read the recently created: the order of the
	 * section may hold hand-sorted templates ahead of the one written last.
	 */
	private const RECENT_FALLBACK_SCAN = 50;

	private ?object $crmProvider = null;

	/** @var array<int, bool> */
	private array $crmAccessByUser = [];

	public function __construct(
		private readonly ?Closure $moduleLoader = null,
		private readonly ?Closure $providerFactory = null,
		private readonly ?Closure $contractAvailability = null,
		private readonly ?Closure $accessChecker = null,
	)
	{
	}

	/**
	 * The templates belong to CRM, so the user is let in on the terms its own section lets them in on:
	 * the module has to be there with the contract the templates come through, and CRM access has to be
	 * open to the user. Without the last question a user CRM is closed for would read shared templates
	 * through the mail form.
	 */
	public function isAvailable(int $userId): bool
	{
		$moduleAvailable = $this->moduleLoader !== null
			? (bool)($this->moduleLoader)()
			: Loader::includeModule('crm')
		;
		if (!$moduleAvailable)
		{
			return false;
		}

		$contractAvailable = $this->contractAvailability !== null
			? (bool)($this->contractAvailability)()
			: class_exists(PublicCrmTemplateProvider::class)
		;

		return $contractAvailable && $this->hasCrmAccess($userId);
	}

	public function canCreatePersonal(int $userId): bool
	{
		return $this->isAvailable($userId)
			&& class_exists(PersonalMailTemplateData::class)
			&& method_exists($this->getCrmProvider(), 'createPersonalUniversal')
		;
	}

	/**
	 * @param list<int> $diskObjectIds
	 */
	public function createPersonal(
		string $subject,
		string $bodyHtml,
		string $emailFrom,
		array $diskObjectIds,
		int $userId,
	): Result
	{
		$result = new Result();
		if (!$this->canCreatePersonal($userId))
		{
			return $result->addError(new Error(
				'Template provider is unavailable.',
				self::ERROR_PROVIDER_UNAVAILABLE,
			));
		}

		try
		{
			return $this->getCrmProvider()->createPersonalUniversal(
				new PersonalMailTemplateData(
					subject: $subject,
					bodyHtml: $bodyHtml,
					emailFrom: $emailFrom,
					diskObjectIds: $diskObjectIds,
				),
				$userId,
			);
		}
		catch (Throwable)
		{
			return $result->addError(new Error(
				'Template creation failed.',
				self::ERROR_CREATION_FAILED,
			));
		}
	}

	public function search(
		string $query,
		int $userId,
		int $offset = 0,
		int $limit = 20,
	): TemplatePage
	{
		if (!$this->isAvailable($userId))
		{
			return new TemplatePage([], null, false);
		}

		$offset = max(0, $offset);
		if ($offset > self::MAX_OFFSET)
		{
			return new TemplatePage([], null, false);
		}

		/** @var CrmTemplatePage $page */
		$page = $this->getCrmProvider()->search($query, $userId, $offset, $limit);
		$items = array_map($this->mapTemplate(...), $page->getItems());
		$nextOffset = $page->hasMore() && !empty($items) ? $offset + count($items) : null;
		if ($nextOffset !== null && $nextOffset > self::MAX_OFFSET)
		{
			$nextOffset = null;
		}

		return new TemplatePage(
			items: $items,
			nextOffset: $nextOffset,
			hasMore: $nextOffset !== null,
		);
	}

	public function getByIds(array $references, int $userId): array
	{
		if (!$this->isAvailable($userId))
		{
			return [];
		}

		$templateIds = [];
		foreach ($references as $reference)
		{
			if (!$reference instanceof TemplateReference || $reference->getSource() !== self::SOURCE)
			{
				continue;
			}

			$templateIds[] = $reference->getId();
		}

		if (empty($templateIds))
		{
			return [];
		}

		return array_map(
			$this->mapTemplate(...),
			$this->getCrmProvider()->getByIds($templateIds, $userId),
		);
	}

	public function getRecentlyCreated(int $userId, int $limit = 5): array
	{
		if (!$this->isAvailable($userId))
		{
			return [];
		}

		$crmProvider = $this->getCrmProvider();
		if (!method_exists($crmProvider, 'getRecentlyCreated'))
		{
			return $this->recentlyCreatedByScan($crmProvider, $userId, $limit);
		}

		return array_map(
			$this->mapTemplate(...),
			$crmProvider->getRecentlyCreated($userId, $limit, false),
		);
	}

	/**
	 * A CRM that cannot read the recently created is asked the question it does know: a page of the templates
	 * of the user, contextual ones left out by the query itself and not after the limit has already cut the
	 * page down. The page comes in the order of the section, so the newest are taken by identifier, which
	 * grows along with the templates being written.
	 *
	 * A CRM whose search knows nothing of the last argument drops it instead of failing, and answers with the
	 * contextual templates in place, hence the applicable ones are picked out here as well.
	 *
	 * @return list<TemplateListItem>
	 */
	private function recentlyCreatedByScan(object $crmProvider, int $userId, int $limit): array
	{
		/** @var CrmTemplatePage $page */
		$page = $crmProvider->search('', $userId, 0, self::RECENT_FALLBACK_SCAN, false);
		$items = array_values(array_filter(
			array_map($this->mapTemplate(...), $page->getItems()),
			static fn(TemplateListItem $item): bool => $item->canApply(),
		));
		usort(
			$items,
			static fn(TemplateListItem $first, TemplateListItem $second): int => (
				$second->getReference()->getId() <=> $first->getReference()->getId()
			),
		);

		return array_slice($items, 0, max(1, $limit));
	}

	public function prepare(TemplateReference $reference, int $userId): Result
	{
		$result = new Result();
		if (!$this->isAvailable($userId))
		{
			return $result->addError(new Error(
				'Template provider is unavailable.',
				self::ERROR_PROVIDER_UNAVAILABLE,
			));
		}

		if ($reference->getSource() !== self::SOURCE)
		{
			return $result->addError($this->createNotAvailableError());
		}

		try
		{
			$templates = $this->getCrmProvider()->getByIds([$reference->getId()], $userId);
			$template = $templates[0] ?? null;
			if (!$template instanceof MailTemplate || !$template->isUniversal())
			{
				return $result->addError($this->createNotAvailableError());
			}

			$prepared = $this->getCrmProvider()->prepareUniversal($reference->getId(), $userId);
			if ($prepared === null)
			{
				return $result->addError(new Error(
					'Template preparation failed.',
					self::ERROR_PREPARATION_FAILED,
				));
			}

			return $result->setData([
				self::RESULT_TEMPLATE => new PreparedTemplate(
					reference: $reference,
					subject: $prepared->getSubject(),
					// Templates written before the signature stopped reaching the body still carry it,
					// and so do those of the old form and of CRM itself.
					bodyHtml: TemplateBody::stripSignature($prepared->getBodyHtml()),
					attachments: $this->mapPreparedAttachments($prepared),
				),
			]);
		}
		catch (Throwable)
		{
			return $result->addError(new Error(
				'Template preparation failed.',
				self::ERROR_PREPARATION_FAILED,
			));
		}
	}

	/**
	 * Availability is asked again on every action, while the rights of a user hold for the whole
	 * request, so the answer is remembered by the user it was given for.
	 */
	private function hasCrmAccess(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->crmAccessByUser[$userId] ??= $this->accessChecker !== null
			? (bool)($this->accessChecker)($userId)
			: (new Permissions())->hasAccessToCrm($userId)
		;
	}

	private function getCrmProvider(): object
	{
		if ($this->crmProvider === null)
		{
			$this->crmProvider = $this->providerFactory !== null
				? ($this->providerFactory)()
				: new PublicCrmTemplateProvider()
			;
		}

		return $this->crmProvider;
	}

	private function mapTemplate(MailTemplate $template): TemplateListItem
	{
		$canApply = $template->isUniversal();

		return new TemplateListItem(
			reference: new TemplateReference(self::SOURCE, $template->getId()),
			title: $template->getTitle(),
			canApply: $canApply,
			disabledReason: $canApply ? null : self::DISABLED_REASON_CONTEXT_REQUIRED,
			subject: $template->getSubject(),
		);
	}

	/**
	 * @return list<PreparedTemplateAttachment>
	 */
	private function mapPreparedAttachments(object $prepared): array
	{
		if (!method_exists($prepared, 'getAttachments'))
		{
			return [];
		}

		return array_map(
			static fn(object $attachment): PreparedTemplateAttachment => new PreparedTemplateAttachment(
				id: $attachment->getId(),
				name: $attachment->getName(),
				size: $attachment->getSize(),
				contentType: $attachment->getContentType(),
			),
			$prepared->getAttachments(),
		);
	}

	private function createNotAvailableError(): Error
	{
		return new Error('Template is not available.', self::ERROR_TEMPLATE_NOT_AVAILABLE);
	}
}
