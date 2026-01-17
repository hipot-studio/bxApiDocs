<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor;

final class Layout implements \JsonSerializable
{
	private bool $isHeaderShown = true;
	private bool $isFooterShown = true;
	private bool $isSendButtonShown = true;
	private bool $isCancelButtonShown = true;
	private bool $isMessagePreviewShown = true;
	private bool $isContentProvidersShown = true;
	private bool $isEmojiButtonShown = true;
	private bool $isMessageTextReadOnly = false;
	private string $padding = 'var(--ui-space-inset-lg)';
	private ?string $paddingLeft = null;
	private ?string $paddingRight = null;
	private ?string $paddingTop = null;
	private ?string $paddingBottom = null;

	public function setHeaderShown(bool $isHeaderShown): self
	{
		$this->isHeaderShown = $isHeaderShown;

		return $this;
	}

	public function isHeaderShown(): bool
	{
		return $this->isHeaderShown;
	}

	public function setFooterShown(bool $isFooterShown): self
	{
		$this->isFooterShown = $isFooterShown;

		return $this;
	}

	public function isFooterShown(): bool
	{
		return $this->isFooterShown;
	}

	public function isSendButtonShown(): bool
	{
		return $this->isFooterShown() && $this->isSendButtonShown;
	}

	public function setSendButtonShown(bool $isSendButtonShown): self
	{
		$this->isSendButtonShown = $isSendButtonShown;

		return $this;
	}

	public function isCancelButtonShown(): bool
	{
		return $this->isFooterShown() && $this->isCancelButtonShown;
	}

	public function setCancelButtonShown(bool $isCancelButtonShown): self
	{
		$this->isCancelButtonShown = $isCancelButtonShown;

		return $this;
	}

	public function isMessagePreviewShown(): bool
	{
		return $this->isMessagePreviewShown;
	}

	public function setMessagePreviewShown(bool $isMessagePreviewShown): self
	{
		$this->isMessagePreviewShown = $isMessagePreviewShown;

		return $this;
	}

	public function isContentProvidersShown(): bool
	{
		return $this->isContentProvidersShown;
	}

	public function setContentProvidersShown(bool $isContentProvidersShown): self
	{
		$this->isContentProvidersShown = $isContentProvidersShown;

		return $this;
	}

	public function isEmojiButtonShown(): bool
	{
		return $this->isEmojiButtonShown;
	}

	public function setEmojiButtonShown(bool $isEmojiButtonShown): self
	{
		$this->isEmojiButtonShown = $isEmojiButtonShown;

		return $this;
	}

	public function isMessageTextReadOnly(): bool
	{
		return $this->isMessageTextReadOnly;
	}

	public function setMessageTextReadOnly(bool $isMessageTextReadOnly): self
	{
		$this->isMessageTextReadOnly = $isMessageTextReadOnly;

		return $this;
	}

	public function setPadding(string $padding): self
	{
		$this->padding = $padding;

		return $this;
	}

	public function getPadding(): string
	{
		return $this->padding;
	}

	public function setPaddingTop(?string $paddingTop): self
	{
		$this->paddingTop = $paddingTop;

		return $this;
	}

	public function getPaddingTop(): string
	{
		return $this->paddingTop ?? $this->getPadding();
	}

	public function setPaddingBottom(?string $paddingBottom): self
	{
		$this->paddingBottom = $paddingBottom;

		return $this;
	}

	public function getPaddingBottom(): string
	{
		return $this->paddingBottom ?? $this->getPadding();
	}

	public function setPaddingLeft(?string $paddingLeft): self
	{
		$this->paddingLeft = $paddingLeft;

		return $this;
	}

	public function getPaddingLeft(): string
	{
		return $this->paddingLeft ?? $this->getPadding();
	}

	public function setPaddingRight(?string $paddingRight): self
	{
		$this->paddingRight = $paddingRight;

		return $this;
	}

	public function getPaddingRight(): string
	{
		return $this->paddingRight ?? $this->getPadding();
	}

	public function jsonSerialize(): array
	{
		return [
			'isHeaderShown' => $this->isHeaderShown(),
			'isFooterShown' => $this->isFooterShown(),
			'isSendButtonShown' => $this->isSendButtonShown(),
			'isCancelButtonShown' => $this->isCancelButtonShown(),
			'isMessagePreviewShown' => $this->isMessagePreviewShown(),
			'isContentProvidersShown' => $this->isContentProvidersShown(),
			'isEmojiButtonShown' => $this->isEmojiButtonShown(),
			'isMessageTextReadOnly' => $this->isMessageTextReadOnly(),
			'padding' => $this->getPadding(),
			'paddingTop' => $this->getPaddingTop(),
			'paddingBottom' => $this->getPaddingBottom(),
			'paddingLeft' => $this->getPaddingLeft(),
			'paddingRight' => $this->getPaddingRight(),
		];
	}
}
