<?php

namespace Bitrix\Landing\Markup\Form;

use Bitrix\Landing\Markup\Package;

/**
 * Cuts foreign `<form>` elements out of the entry document of a markup package: the last resort
 * when the repairer has not turned them into CRM form markers within its budget.
 *
 * The ranges come from the same scanner the `foreign_form` detector uses, so the cut package
 * has nothing left for the detector. Everything but the entry stays as it was.
 */
class ForeignFormCutter
{
	public const WARNING_REMOVED = 'MARKUP_FOREIGN_FORM_REMOVED';

	private FormMarkupScanner $scanner;

	public function __construct(?FormMarkupScanner $scanner = null)
	{
		$this->scanner = $scanner ?? new FormMarkupScanner();
	}

	/**
	 * @return array{package: Package, removed: int} a copy of the package, the given one is not changed
	 */
	public function cut(Package $package): array
	{
		$result = clone $package;
		$entry = $package->getEntry();
		if ($entry === null)
		{
			return [
				'package' => $result,
				'removed' => 0,
			];
		}

		$content = (string)$entry['content'];
		$ranges = $this->scanner->findForeignForms($content);
		foreach (array_reverse($ranges) as $range)
		{
			$content = substr_replace($content, '', $range['start'], $range['end'] - $range['start']);
		}

		if ($ranges)
		{
			$result->setEntry((string)$entry['path'], $content);
		}

		return [
			'package' => $result,
			'removed' => count($ranges),
		];
	}
}
