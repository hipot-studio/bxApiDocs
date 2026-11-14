<?php

namespace Bitrix\Crm\Engine\ActionFilter;

use Bitrix\Main\Type\IRequestFilter;

final class TimelineCommentAllowEditRequestFilter implements IRequestFilter
{
	private const SECTIONS = ['get', 'post', 'files', 'cookie', 'json'];
	private const ATTACHMENT_ID_PATTERN = '/^n?\d+$/D';

	private readonly array $allowEdit;

	public function __construct(
		private readonly string $fieldName,
		array $allowEdit,
	)
	{
		$this->allowEdit = $this->normalize($allowEdit);
	}

	public function filter(array $values): array
	{
		// Request passes the flat GET+POST values first, HttpRequest passes the sections after that
		if (empty($this->allowEdit) || !$this->isSectioned($values))
		{
			return $values;
		}

		$values['post'][$this->fieldName] = $this->allowEdit;

		return $values;
	}

	private function isSectioned(array $values): bool
	{
		foreach (self::SECTIONS as $section)
		{
			if (!isset($values[$section]) || !is_array($values[$section]))
			{
				return false;
			}
		}

		return true;
	}

	private function normalize(array $allowEdit): array
	{
		$normalized = [];
		foreach ($allowEdit as $attachmentId => $value)
		{
			if (preg_match(self::ATTACHMENT_ID_PATTERN, (string)$attachmentId))
			{
				$normalized[$attachmentId] = ($value === 1 || $value === '1' || $value === true) ? '1' : '0';
			}
		}

		return $normalized;
	}
}
