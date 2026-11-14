<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Internal\Services\AiSearch\Enum\SearchEngine;

final class AppsSearcher
{
	public function __construct(
		private readonly AiEngine $aiEngine,
		private readonly ClassicEngine $classicEngine,
		private readonly AppsDecorator $decorator,
	)
	{
	}

	public function search(SearchQuery $query, Visitor $visitor): ?array
	{
		$result = $this->searchByEngine($query, $visitor);

		if ($result === null)
		{
			return null;
		}

		if (empty($result['apps']))
		{
			return $result;
		}

		try
		{
			$result['apps'] = $this->decorator->decorate($result['apps']);
		}
		catch (\Throwable)
		{
			return null;
		}

		return $result;
	}

	private function searchByEngine(SearchQuery $query, Visitor $visitor): ?array
	{
		if (!$this->aiEngine->supports($query))
		{
			return $this->classicEngine->search($query);
		}

		return $this->aiEngine->search($query, $visitor) ?? $this->searchFallback($query);
	}

	private function searchFallback(SearchQuery $query): ?array
	{
		$result = $this->classicEngine->search($query);

		if ($result === null)
		{
			return null;
		}

		$result['engine'] = SearchEngine::ClassicFallback->value;

		return $result;
	}
}
