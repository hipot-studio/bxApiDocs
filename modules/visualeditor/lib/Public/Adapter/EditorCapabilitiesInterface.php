<?php

namespace Bitrix\VisualEditor\Public\Adapter;

interface EditorCapabilitiesInterface
{
	/**
	 * Returns the trusted server policy for the document context.
	 *
	 * @param array $context
	 * @return EditorCapabilities
	 */
	public function getCapabilities(array $context): EditorCapabilities;
}
