<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\IncomingWebhook;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class IncomingWebhook implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private int $userId,
		private string $password,
		private bool $active = true,
		private ?string $title = null,
		private ?string $comment = null,
		private ?DateTime $dateCreate = null,
		private ?DateTime $dateLogin = null,
		private ?string $lastIp = null,
		private array $scopes = [],
		private ?IncomingWebhookAttributeCollection $externalAttributes = null,
		private WebhookType $type = WebhookType::User,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getPassword(): string
	{
		return $this->password;
	}

	public function setPassword(string $password): self
	{
		$this->password = $password;

		return $this;
	}

	public function isActive(): bool
	{
		return $this->active;
	}

	public function setActive(bool $active): self
	{
		$this->active = $active;

		return $this;
	}

	public function getType(): WebhookType
	{
		return $this->type;
	}

	public function setType(WebhookType $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getComment(): ?string
	{
		return $this->comment;
	}

	public function setComment(?string $comment): self
	{
		$this->comment = $comment;

		return $this;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(?DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}

	public function getDateLogin(): ?DateTime
	{
		return $this->dateLogin;
	}

	public function setDateLogin(?DateTime $dateLogin): self
	{
		$this->dateLogin = $dateLogin;

		return $this;
	}

	public function getLastIp(): ?string
	{
		return $this->lastIp;
	}

	public function setLastIp(?string $lastIp): self
	{
		$this->lastIp = $lastIp;

		return $this;
	}

	public function getScopes(): array
	{
		return $this->scopes;
	}

	public function setScopes(array $scopes): self
	{
		$this->scopes = $scopes;

		return $this;
	}

	public function getExternalAttributes(): IncomingWebhookAttributeCollection
	{
		$this->externalAttributes ??= new IncomingWebhookAttributeCollection();

		return $this->externalAttributes;
	}

	public function setExternalAttributes(IncomingWebhookAttributeCollection $externalAttributes): self
	{
		$this->externalAttributes = $externalAttributes;

		return $this;
	}

	public function getExternalAttribute(string $code): ?IncomingWebhookAttribute
	{
		return $this->getExternalAttributes()->getByCode($code);
	}
}
