<?php

namespace Bitrix\BIConnector\Superset\Dashboard\Metadata\MetadataSection;

interface MetadataSectionInterface
{
	public function getSectionKey(): string;

	public function build(): mixed;

	public function isEmpty(): bool;
}
