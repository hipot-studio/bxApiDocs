<?php

namespace Bitrix\Crm\Service\Router\Contract;

interface Component
{
	public function name(): string;

	public function template(): string;

	public function parameters(): array;

	public function parameter(string $name): mixed;

	public function parent(): ?\CBitrixComponent;

	public function setParameter(string $name, mixed $value): self;

	public function render(): void;
}
