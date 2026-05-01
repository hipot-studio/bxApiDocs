<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Model\TemplateParameterTable;

class TemplateParameterRepository implements TemplateParameterRepositoryInterface
{
	public function link(int $templateId, array $params): void
	{
		if (empty($params))
		{
			return;
		}

		$toInsert = [];
		foreach ($params as $param)
		{
			$code = (int)($param['CODE'] ?? null);
			if (
				empty($code)
				|| empty($param['VALUE'])
				|| !is_scalar($param['VALUE'])
				|| !TemplateParameter::tryFrom($code)
			)
			{
				continue;
			}

			$toInsert[] = [
				'TEMPLATE_ID' => $templateId,
				'CODE' => $code,
				'VALUE' => $param['VALUE'],
			];
		}

		TemplateParameterTable::addInsertIgnoreMulti($toInsert);
	}

	public function updateLinks(int $templateId, array $params): void
	{
		$this->unlink($templateId, $params);

		if (empty($params))
		{
			return;
		}

		$this->link($templateId, $params);
	}

	private function unlink(int $templateId, array $params): void
	{
		$codes = array_unique(array_column($params, 'CODE'));

		$validCodes = [];
		foreach ($codes as $code)
		{
			if (!TemplateParameter::tryFrom($code))
			{
				continue;
			}
			$validCodes[] = $code;
		}

		if (empty($validCodes))
		{
			return;
		}

		TemplateParameterTable::deleteByFilter([
			'TEMPLATE_ID' => $templateId,
			'CODE' => $validCodes,
		]);
	}
}
