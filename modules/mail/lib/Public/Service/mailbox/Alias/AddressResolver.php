<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Mailbox\Alias;

use Bitrix\Mail\Internals\Service\Mailbox\AddressBindingResolver;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;

final class AddressResolver
{
	private readonly AddressBindingResolver $resolver;

	public function __construct(
		?EmailNormalizer $emailNormalizer = null,
		?AddressBindingResolver $resolver = null,
	)
	{
		$this->resolver = $resolver ?? new AddressBindingResolver($emailNormalizer);
	}

	public function resolveForUser(int $userId, string $siteId, string $email): ?ResolvedMailbox
	{
		$binding = $this->resolver->resolveForUser($userId, $siteId, $email);

		return $binding === null ? null : $this->mapBinding($binding);
	}

	public function findActiveMailboxesByIds(array $mailboxIds, ?string $serverType = null): array
	{
		return $this->resolver->findActiveMailboxesByIds($mailboxIds, $serverType);
	}

	public function findMailboxesByIds(array $mailboxIds): array
	{
		return $this->resolver->findMailboxesByIds($mailboxIds);
	}

	public function findBindings(string $email, ?int $userId = null, ?string $siteId = null): array
	{
		return array_map($this->mapBinding(...), $this->resolver->findBindings($email, $userId, $siteId));
	}

	public function findBindingsForEmails(array $emails, ?int $userId = null, ?string $siteId = null): array
	{
		return array_map(
			fn(array $bindings): array => array_map($this->mapBinding(...), $bindings),
			$this->resolver->findBindingsForEmails($emails, $userId, $siteId),
		);
	}

	private function mapBinding(\Bitrix\Mail\Internals\Service\Mailbox\ResolvedMailboxBinding $binding): ResolvedMailbox
	{
		return new ResolvedMailbox($binding->mailboxId, $binding->currentEmail, $binding->isAlias);
	}
}
