<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Integration\Crm\CrmTemplateProvider;
use Bitrix\Mail\Internal\Entity\MailTemplate\PreparedTemplate;
use Bitrix\Mail\Internal\Entity\MailTemplate\PreparedTemplateAttachment;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateListItem;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateCatalog;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateProvider;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateUsageHistory;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use InvalidArgumentException;

class MailTemplate extends Controller
{
	public const ERROR_INVALID_ARGUMENT = 'MAIL_TEMPLATE_INVALID_ARGUMENT';

	private const DEFAULT_SEARCH_LIMIT = 20;
	private const MAX_SEARCH_LIMIT = 50;
	private const MAX_TEMPLATE_SUBJECT_LENGTH = 128;

	/**
	 * The query is looked up in the title of a template, and a title holds 128 characters, so a longer query
	 * matches nothing and has no business reaching the database.
	 */
	private const MAX_SEARCH_QUERY_LENGTH = 128;

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		if ($this->isIntranetModuleIncluded())
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	public function configureActions(): array
	{
		$get = [
			'-prefilters' => [ActionFilter\Csrf::class],
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET])],
		];
		$post = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return [
			'quickList' => $get,
			'search' => $get,
			'prepare' => $post,
			'create' => $post,
			'recordUsage' => $post,
		];
	}

	/**
	 * Neither parameter is declared with a type, and for one reason: the binder refuses a value the
	 * declaration does not fit before the action is reached, so `?array` would answer a scalar reference with
	 * an error of the binder instead of the code this action promises, and `bool` would let a `"false"` of
	 * form data reach a cast that turns it into `true` (`mixed` no binder accepts at all,
	 * `Bitrix\Main\Engine\AutoWire\TypeDeclarationChecker`). Both are read here instead, and an absent value
	 * is not a malformed one: only a value that is there is read, anything unreadable is refused.
	 *
	 * @param mixed $priorityReference
	 * @param mixed $pinRecentlyCreated
	 */
	public function quickListAction($priorityReference = null, $pinRecentlyCreated = null): ?array
	{
		if ($priorityReference !== null)
		{
			if (!is_array($priorityReference))
			{
				return $this->invalidArgument();
			}

			$priorityReference = $this->createReference($priorityReference);
			if ($priorityReference === null)
			{
				return null;
			}
		}

		$pinRecentlyCreated = $pinRecentlyCreated === null ? false : $this->readFlag($pinRecentlyCreated);
		if ($pinRecentlyCreated === null)
		{
			return $this->invalidArgument();
		}

		$items = $this->createCatalog()->quickList(
			$this->getUserId(),
			$priorityReference,
			$pinRecentlyCreated,
		);

		return ['items' => array_map($this->serializeListItem(...), $items)];
	}

	public function searchAction(
		string $query = '',
		int $offset = 0,
		int $limit = self::DEFAULT_SEARCH_LIMIT,
	): ?array
	{
		if ($offset < 0 || mb_strlen(trim($query)) > self::MAX_SEARCH_QUERY_LENGTH)
		{
			return $this->invalidArgument();
		}

		$limit = max(1, min(self::MAX_SEARCH_LIMIT, $limit));
		$page = $this->createCatalog()->search($query, $this->getUserId(), $offset, $limit);

		return [
			'items' => array_map($this->serializeListItem(...), $page->getItems()),
			'nextOffset' => $page->getNextOffset(),
		];
	}

	public function prepareAction(array $reference): ?array
	{
		$reference = $this->createReference($reference);
		if ($reference === null)
		{
			return null;
		}

		$result = $this->createCatalog()->prepare($reference, $this->getUserId());
		if (!$this->transferResultErrors($result))
		{
			return null;
		}

		$template = $result->getData()[TemplateProvider::RESULT_TEMPLATE] ?? null;
		if (!$template instanceof PreparedTemplate)
		{
			$this->addError(new Error(
				'Template preparation failed.',
				TemplateProvider::ERROR_PREPARATION_FAILED,
			));

			return null;
		}

		return ['template' => $this->serializePreparedTemplate($template)];
	}

	public function createAction(string $subject, string $bodyHtml = '', string $emailFrom = ''): ?array
	{
		$subject = trim($subject);
		if ($subject === '' || mb_strlen($subject) > self::MAX_TEMPLATE_SUBJECT_LENGTH)
		{
			return $this->invalidArgument();
		}

		$result = $this->createPersonalTemplate($subject, $bodyHtml, $emailFrom, $this->getUserId());
		if (!$this->transferCreationResultErrors($result))
		{
			return null;
		}

		$templateId = (int)($result->getData()['templateId'] ?? 0);
		if ($templateId <= 0)
		{
			$this->addError(new Error(
				'Template creation failed.',
				CrmTemplateProvider::ERROR_CREATION_FAILED,
			));

			return null;
		}

		return ['reference' => $this->serializeReference(
			new TemplateReference(CrmTemplateProvider::SOURCE, $templateId),
		)];
	}

	public function recordUsageAction(array $reference): ?array
	{
		$reference = $this->createReference($reference);
		if ($reference === null)
		{
			return null;
		}

		$result = $this->createCatalog()->recordUsage($reference, $this->getUserId());
		if (!$this->transferResultErrors($result))
		{
			return null;
		}

		return ['recorded' => true];
	}

	protected function createCatalog(): TemplateCatalog
	{
		return new TemplateCatalog(
			$this->createProviders(),
			$this->createUsageHistory(),
		);
	}

	/**
	 * @return array<string, TemplateProvider>
	 */
	protected function createProviders(): array
	{
		return [CrmTemplateProvider::SOURCE => new CrmTemplateProvider()];
	}

	protected function createPersonalTemplate(
		string $subject,
		string $bodyHtml,
		string $emailFrom,
		int $userId,
	): Result
	{
		return (new CrmTemplateProvider())->createPersonal($subject, $bodyHtml, $emailFrom, [], $userId);
	}

	protected function createUsageHistory(): TemplateUsageHistory
	{
		return new TemplateUsageHistory();
	}

	protected function isIntranetModuleIncluded(): bool
	{
		return Loader::includeModule('intranet');
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()->getId();
	}

	private function createReference(array $data): ?TemplateReference
	{
		$source = $data['source'] ?? null;
		$id = $data['id'] ?? null;
		if (is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1)
		{
			$id = (int)$id;
		}

		if (!is_string($source) || trim($source) === '' || !is_int($id) || $id <= 0)
		{
			$this->invalidArgument();

			return null;
		}

		try
		{
			return new TemplateReference($source, $id);
		}
		catch (InvalidArgumentException)
		{
			$this->invalidArgument();

			return null;
		}
	}

	/**
	 * Form data carries a flag as a string, so `"false"` must not become `true` by a cast. Anything but a
	 * boolean spelling PHP knows is an invalid argument rather than a guess.
	 */
	private function readFlag(mixed $value): ?bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		$isSpelled = is_int($value) || (is_string($value) && trim($value) !== '');

		return $isSpelled ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
	}

	private function invalidArgument(): null
	{
		$this->addError(new Error('Invalid mail template argument.', self::ERROR_INVALID_ARGUMENT));

		return null;
	}

	private function transferResultErrors(Result $result): bool
	{
		if ($result->isSuccess())
		{
			return true;
		}

		$allowedCodes = [
			TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE,
			TemplateProvider::ERROR_PROVIDER_UNAVAILABLE,
			TemplateProvider::ERROR_PREPARATION_FAILED,
		];
		foreach ($result->getErrors() as $error)
		{
			$code = in_array($error->getCode(), $allowedCodes, true)
				? (string)$error->getCode()
				: TemplateProvider::ERROR_PREPARATION_FAILED
			;
			$this->addError(new Error($this->errorMessage($code), $code));
		}

		return false;
	}

	private function transferCreationResultErrors(Result $result): bool
	{
		if ($result->isSuccess())
		{
			return true;
		}

		foreach ($result->getErrors() as $error)
		{
			$providerUnavailable = $error->getCode() === TemplateProvider::ERROR_PROVIDER_UNAVAILABLE;
			$this->addError(new Error(
				$providerUnavailable ? 'Template provider is unavailable.' : 'Template creation failed.',
				$providerUnavailable
					? TemplateProvider::ERROR_PROVIDER_UNAVAILABLE
					: CrmTemplateProvider::ERROR_CREATION_FAILED,
			));
		}

		return false;
	}

	private function errorMessage(string $code): string
	{
		return match ($code)
		{
			TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE => 'Template is not available.',
			TemplateProvider::ERROR_PROVIDER_UNAVAILABLE => 'Template provider is unavailable.',
			default => 'Template preparation failed.',
		};
	}

	private function serializeReference(TemplateReference $reference): array
	{
		return [
			'source' => $reference->getSource(),
			'id' => $reference->getId(),
		];
	}

	/**
	 * The subject belongs to a template the user can apply. For the rest the lists show the reason of the
	 * refusal in its place, so the value would reach no one and the answer carries the field empty.
	 */
	private function serializeListItem(TemplateListItem $item): array
	{
		return [
			'reference' => $this->serializeReference($item->getReference()),
			'title' => $item->getTitle(),
			'subject' => $item->canApply() ? $item->getSubject() : '',
			'canApply' => $item->canApply(),
			'disabledReason' => $item->getDisabledReason(),
		];
	}

	private function serializePreparedTemplate(PreparedTemplate $template): array
	{
		return [
			'reference' => $this->serializeReference($template->getReference()),
			'subject' => $template->getSubject(),
			'bodyHtml' => $template->getBodyHtml(),
			'attachments' => array_map(
				$this->serializePreparedAttachment(...),
				$template->getAttachments(),
			),
		];
	}

	private function serializePreparedAttachment(PreparedTemplateAttachment $attachment): array
	{
		return [
			'id' => $attachment->getId(),
			'name' => $attachment->getName(),
			'size' => $attachment->getSize(),
			'contentType' => $attachment->getContentType(),
		];
	}
}
