<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;

/**
 * Typed proof that the push entry authorized a subject tuple (TPL-05). It collapses the former double push
 * authorization: {@see AgentDraftService::saveAndPush()} consumes this token instead of re-running the
 * document-access check, because holding a token already means the check passed.
 *
 * Unforgeable by construction: the constructor is private and the only producer is {@see self::authorize()},
 * which mints an instance solely on an allow verdict from {@see OperationAuthorizationGuard}, so a subject cannot
 * exist without a passing check.
 *
 * draftId is not part of the authorization tuple (canManageDocument never consults it); it rides along so the push
 * consumer can rebuild the {@see WorkflowTemplateIdentifier} and keep updating the current draft.
 * The source identifier is retained only for its resource metadata; the authorized tuple below remains the source
 * of ids and document type when it is rebuilt.
 */
final readonly class AuthorizedTemplateSubject
{
	private function __construct(
		public int $userId,
		public DocumentDescription $documentDescription,
		public int $templateId,
		public bool $isUserAdmin,
		public ?int $draftId,
		private WorkflowTemplateIdentifier $identifier,
	) {}

	/**
	 * Sole producer: mints the token only on an allow verdict, null on refusal.
	 */
	public static function authorize(
		OperationAuthorizationGuard $guard,
		int $userId,
		WorkflowTemplateIdentifier $identifier,
		bool $isUserAdmin,
	): ?self
	{
		$templateId = (int)($identifier->templateId ?? 0);

		$verdict = $guard->authorize($userId, $identifier->documentDescription, $templateId, $isUserAdmin);
		if (!$verdict->allowed)
		{
			return null;
		}

		return new self(
			$userId,
			$identifier->documentDescription,
			$templateId,
			$isUserAdmin,
			$identifier->draftId,
			$identifier,
		);
	}

	public function toIdentifier(): WorkflowTemplateIdentifier
	{
		return new WorkflowTemplateIdentifier(
			$this->documentDescription,
			$this->templateId,
			$this->draftId,
			$this->identifier->name,
			$this->identifier->modified,
		);
	}
}
