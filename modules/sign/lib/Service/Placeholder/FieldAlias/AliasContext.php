<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias;

use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Service\Container;

class AliasContext
{
	private readonly AliasRoleResolver $aliasRoleResolver;

	public function __construct(
		?AliasRoleResolver $aliasRoleResolver = null,
		public readonly ?int $documentId = null,
		public readonly ?int $memberId = null,
		public readonly ?int $party = null,
		public readonly ?string $role = null,
		public readonly ?int $hcmLinkCompanyId = null,
	)
	{
		$this->aliasRoleResolver = $aliasRoleResolver ?? Container::instance()->getAliasRoleResolver();
	}

	public static function fromDocument(Document $document, ?AliasRoleResolver $aliasRoleResolver = null): self
	{
		return new self(
			aliasRoleResolver: $aliasRoleResolver,
			documentId: $document->id,
			hcmLinkCompanyId: $document->hcmLinkCompanyId,
		);
	}
	
	public static function fromDocumentAndMember(
		Document $document,
		Member $member,
		?AliasRoleResolver $aliasRoleResolver = null,
	): self
	{
		$context = new self(
			aliasRoleResolver: $aliasRoleResolver,
			documentId: $document->id,
			memberId: $member->id,
			party: $member->party,
			role: $member->role,
			hcmLinkCompanyId: $document->hcmLinkCompanyId,
		);

		if ($context->role === null && $context->party !== null)
		{
			return $context->withParty($context->party);
		}

		return $context;
	}
	
	public static function empty(): self
	{
		return new self();
	}

	public function withParty(int $party): self
	{
		return $this->with(
			party: $party,
			role: $this->aliasRoleResolver->resolveMemberRoleByParty($party),
		);
	}

	public function with(
		?int $documentId = null,
		?int $memberId = null,
		?int $party = null,
		?string $role = null,
		?int $hcmLinkCompanyId = null,
	): self
	{
		return new self(
			aliasRoleResolver: $this->aliasRoleResolver,
			documentId: $documentId ?? $this->documentId,
			memberId: $memberId ?? $this->memberId,
			party: $party ?? $this->party,
			role: $role ?? $this->role,
			hcmLinkCompanyId: $hcmLinkCompanyId ?? $this->hcmLinkCompanyId,
		);
	}
}
