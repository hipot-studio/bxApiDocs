<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

interface TabInterface
{
	public function isAvailable(): bool;
	public function getId(): string;
	public function getTabTitle(): ?string;
	/** @deprecated */
	public function isPreload(): bool;
	/** @deprecated */
	public function getComponentData(): ?array;
	/** @deprecated */
	public function mergeParams(array $params): void;
	/** @deprecated */
	public function isNeedMergeSharedParams(): bool;
}
