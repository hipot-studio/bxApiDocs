<?php

namespace Bitrix\Superset\Internal\Support;

/**
 * Lexically-aware read-only SQL classifier.
 *
 * Guarantees that a SQL string handed to SQL Lab is exactly ONE read-only
 * statement - a top-level SELECT or a WITH ... SELECT (CTE) - and rejects
 * everything else: DML/DDL, multiple statements, and obfuscation attempts that
 * try to hide a second statement or a forbidden keyword inside comments or
 * string literals.
 *
 * The check does not trust a naive regex over the raw text. It performs a
 * single lexical pass that understands SQL line comments, block comments,
 * single quoted string literals (with '' escaping) and double quoted / backtick
 * identifiers (with "" escaping), so that a ';' or a keyword such as DROP living
 * inside a comment or a literal never influences the decision. Being a security
 * boundary, the pass fails closed: a quoted run that cannot be read
 * unambiguously - unterminated, or ending in a dialect-dependent backslash
 * escape - rejects the whole query instead of deciding on the parsed prefix.
 * Statements are separated on a ';' seen outside any literal or comment; a
 * single trailing ';' is allowed, any earlier separator means more than one
 * statement and is rejected. Parenthesis depth is tracked so a keyword inside a subquery or a
 * CTE body is never mistaken for the top-level statement type.
 *
 * Leading wrapper parentheses are accepted: a read-only query expression may be
 * wrapped in one or more opening parentheses, e.g. "(SELECT ...) UNION (SELECT
 * ...)", "(SELECT ...) ORDER BY x" or "((SELECT 1))". The leading '(' tokens are
 * skipped and the statement type is decided by the first significant non-'(' token,
 * which must still be SELECT or WITH (with, for WITH, the same main-query check).
 * A wrapped DML/DDL statement such as "(DELETE ...)" or "(INSERT ...)" is still
 * rejected because its first non-'(' token is not SELECT or WITH.
 *
 * Alignment with Apache Superset: Superset classifies on a parsed sqlglot AST
 * (SQLScript::has_mutation / SQLStatement::is_mutating in superset/sql/parse.py)
 * and treats INSERT, UPDATE, DELETE, MERGE, CREATE, DROP, TRUNCATE and ALTER
 * (found anywhere in the tree, including inside a CTE body) as mutations, while
 * WITH ... SELECT is read-only. This classifier reaches the same verdict for a
 * leading statement of those types, but differs and is deliberately stricter to
 * match the execute_sql tool contract ("a single read-only SELECT"):
 *  - it accepts a single statement only (Superset itself permits a multi
 *    statement script as long as no statement mutates);
 *  - it rejects SELECT ... INTO, which creates a table. Superset currently
 *    classifies SELECT ... INTO as read-only (there is no special handling in
 *    parse.py), so we do not replicate that gap;
 *  - it classifies only the top-level statement type (for WITH, the main query
 *    that follows the CTE list must be SELECT); it does not descend into CTE
 *    bodies. A data-modifying statement inside a CTE body is therefore not
 *    separately detected, which is safe for the Trino target here because Trino
 *    does not support data-modifying statements inside WITH.
 *
 * Because only a leading SELECT or WITH ... SELECT is accepted, the whole
 * DML/DDL and session-command family (INSERT, UPDATE, DELETE, MERGE, UPSERT,
 * REPLACE, DROP, ALTER, CREATE, TRUNCATE, GRANT, REVOKE, CALL, EXECUTE, COPY,
 * SET, USE, RESET, ANALYZE, VACUUM, COMMENT, RENAME, LOCK, BEGIN, COMMIT,
 * ROLLBACK, START, PREPARE, DEALLOCATE, DESCRIBE, SHOW, EXPLAIN, ...) is
 * rejected implicitly by the whitelist rather than by a maintained blocklist.
 */
final class SqlReadOnlyClassifier
{
	private const T_WORD = 'w';
	private const T_STRING = 's';
	private const T_IDENT_QUOTED = 'q';
	private const T_PUNCT = 'p';

	private const REJECTION_PREFIX = 'Only a single read-only SELECT statement is allowed.';

	/**
	 * Classifies the given SQL as a read-only single SELECT or rejects it.
	 *
	 * @param string $sql Raw SQL to classify.
	 * @return SqlReadOnlyClassification Verdict with a user-facing reason when rejected.
	 */
	public function classify(string $sql): SqlReadOnlyClassification
	{
		$tokens = $this->tokenize($sql);
		if (is_string($tokens))
		{
			return SqlReadOnlyClassification::reject(self::REJECTION_PREFIX . ' ' . $tokens);
		}

		$statements = $this->splitStatements($tokens);

		if ($statements === [])
		{
			return SqlReadOnlyClassification::reject(self::REJECTION_PREFIX . ' The query is empty.');
		}

		if (count($statements) > 1)
		{
			return SqlReadOnlyClassification::reject(
				self::REJECTION_PREFIX . ' Multiple SQL statements are not permitted.'
			);
		}

		return $this->classifyStatement($statements[0]);
	}

	private function classifyStatement(array $tokens): SqlReadOnlyClassification
	{
		// A read-only query expression may be wrapped in one or more leading
		// parentheses; skip them and decide the type by the first non-'(' token.
		$count = count($tokens);
		$parenOffset = 0;
		while (
			$parenOffset < $count
			&& $tokens[$parenOffset]['t'] === self::T_PUNCT
			&& $tokens[$parenOffset]['v'] === '('
		)
		{
			$parenOffset++;
		}

		$first = $tokens[$parenOffset] ?? null;
		if ($first === null || $first['t'] !== self::T_WORD || ($first['v'] !== 'SELECT' && $first['v'] !== 'WITH'))
		{
			return SqlReadOnlyClassification::reject(
				self::REJECTION_PREFIX . ' The query must begin with SELECT or WITH'
				. ($first !== null ? $this->describeToken($first) : '') . '.'
			);
		}

		if ($first['v'] === 'WITH')
		{
			$mainStart = $this->locateMainQueryStart($tokens, $parenOffset);
			if ($mainStart === null)
			{
				return SqlReadOnlyClassification::reject(
					self::REJECTION_PREFIX . ' The statement following the WITH clause must be SELECT.'
				);
			}

			$mainToken = $tokens[$mainStart];
			if ($mainToken['t'] !== self::T_WORD || $mainToken['v'] !== 'SELECT')
			{
				return SqlReadOnlyClassification::reject(
					self::REJECTION_PREFIX . ' The statement following the WITH clause must be SELECT'
					. $this->describeToken($mainToken) . '.'
				);
			}
		}
		else
		{
			$mainStart = $parenOffset;
		}

		if ($this->hasTopLevelInto($tokens, $mainStart))
		{
			return SqlReadOnlyClassification::reject(
				self::REJECTION_PREFIX . ' SELECT ... INTO is not permitted.'
			);
		}

		return SqlReadOnlyClassification::allow();
	}

	/**
	 * Splits a raw SQL string into a flat token stream, dropping whitespace and
	 * comments and masking string literals / quoted identifiers to a single
	 * opaque token so their content can never influence the analysis.
	 *
	 * Returns a rejection reason instead of a token stream when the input cannot
	 * be read unambiguously, so no verdict is ever made on partially parsed SQL.
	 *
	 * @return array<int, array{t: string, v: string}>|string
	 */
	private function tokenize(string $sql): array|string
	{
		$tokens = [];
		$length = strlen($sql);
		$i = 0;

		while ($i < $length)
		{
			$char = $sql[$i];

			if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n" || $char === "\f" || $char === "\v")
			{
				$i++;
				continue;
			}

			// Line comment: -- ... up to end of line.
			if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-')
			{
				$i += 2;
				while ($i < $length && $sql[$i] !== "\n")
				{
					$i++;
				}
				continue;
			}

			// Block comment: /* ... */ (not nested, per standard SQL).
			if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*')
			{
				$i += 2;
				while ($i < $length && !($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/'))
				{
					$i++;
				}
				$i += 2; // Skip the closing */ (harmless overshoot if unterminated).
				continue;
			}

			// Single quoted string literal, double quoted or backtick quoted
			// identifier - masked to one opaque token.
			if ($char === "'" || $char === '"' || $char === '`')
			{
				$end = $this->scanQuotedRun($sql, $i, $length);
				if (is_string($end))
				{
					return $end;
				}

				$i = $end;
				$tokens[] = ['t' => $char === "'" ? self::T_STRING : self::T_IDENT_QUOTED, 'v' => ''];
				continue;
			}

			// Word: identifier, keyword or number.
			if ($this->isWordChar($char))
			{
				$start = $i;
				while ($i < $length && $this->isWordChar($sql[$i]))
				{
					$i++;
				}
				$tokens[] = ['t' => self::T_WORD, 'v' => strtoupper(substr($sql, $start, $i - $start))];
				continue;
			}

			// Any other single character is punctuation.
			$tokens[] = ['t' => self::T_PUNCT, 'v' => $char];
			$i++;
		}

		return $tokens;
	}

	/**
	 * Reads a quoted run (string literal or quoted identifier) that starts at the
	 * opening quote and returns the index right after the closing quote, or a
	 * rejection reason when the run cannot be read unambiguously. A doubled quote
	 * inside the run is an escaped quote, per standard SQL.
	 *
	 * Two cases are ambiguous and must be rejected rather than resolved, because
	 * either one would let the rest of the query be swallowed into the run and the
	 * verdict be made on the remaining prefix:
	 *  - an unterminated quote;
	 *  - a backslash right before the quote character: dialects that honour
	 *    backslash escapes (MySQL) keep the run open there, while standard SQL and
	 *    Trino end it. A backslash anywhere else inside the run is kept, so common
	 *    literals such as '\d+' or 'C:\\' stay valid.
	 *
	 * @return int|string End index, or the reason the query is rejected.
	 */
	private function scanQuotedRun(string $sql, int $start, int $length): int|string
	{
		$quote = $sql[$start];
		$backslashMayEscape = $quote !== '`';
		$i = $start + 1;

		while ($i < $length)
		{
			$char = $sql[$i];

			if ($backslashMayEscape && $char === '\\')
			{
				if ($i + 1 >= $length || $sql[$i + 1] === $quote)
				{
					return 'A backslash before a quote inside a quoted literal is not permitted.';
				}

				$i += 2;
				continue;
			}

			if ($char === $quote)
			{
				if ($i + 1 < $length && $sql[$i + 1] === $quote)
				{
					$i += 2;
					continue;
				}

				return $i + 1;
			}

			$i++;
		}

		return 'The query contains an unterminated quoted literal or identifier.';
	}

	/**
	 * Splits the token stream on top-level ';' separators, dropping empty
	 * statements (so a single trailing ';' or a comment-only tail does not count
	 * as an extra statement).
	 *
	 * @param array<int, array{t: string, v: string}> $tokens
	 * @return array<int, array<int, array{t: string, v: string}>>
	 */
	private function splitStatements(array $tokens): array
	{
		$statements = [];
		$current = [];

		foreach ($tokens as $token)
		{
			if ($token['t'] === self::T_PUNCT && $token['v'] === ';')
			{
				if ($current !== [])
				{
					$statements[] = $current;
				}
				$current = [];
				continue;
			}

			$current[] = $token;
		}

		if ($current !== [])
		{
			$statements[] = $current;
		}

		return $statements;
	}

	/**
	 * For a WITH statement, returns the index of the first token of the outer
	 * (main) query that follows the CTE definition list, or null when it cannot
	 * be located. CTE bodies and column lists live at parenthesis depth >= 1, so
	 * the main query starts at the first token seen at depth 0 right after a CTE
	 * body's closing parenthesis that is not followed by ',' (another CTE) or by
	 * 'AS' (the body of a CTE whose column list just closed).
	 *
	 * $withIndex is the position of the WITH keyword, which may be greater than 0
	 * when the query expression is wrapped in leading parentheses. Depth is tracked
	 * relative to the WITH so those leading wrapper parentheses do not interfere.
	 *
	 * @param array<int, array{t: string, v: string}> $tokens
	 */
	private function locateMainQueryStart(array $tokens, int $withIndex): ?int
	{
		$count = count($tokens);
		$i = $withIndex + 1; // Skip the leading WITH.

		if ($i < $count && $tokens[$i]['t'] === self::T_WORD && $tokens[$i]['v'] === 'RECURSIVE')
		{
			$i++;
		}

		$depth = 0;
		while ($i < $count)
		{
			$token = $tokens[$i];

			if ($token['t'] === self::T_PUNCT && $token['v'] === '(')
			{
				$depth++;
				$i++;
				continue;
			}

			if ($token['t'] === self::T_PUNCT && $token['v'] === ')')
			{
				$depth--;
				$i++;

				if ($depth === 0 && $i < $count)
				{
					$next = $tokens[$i];

					if ($next['t'] === self::T_PUNCT && $next['v'] === ',')
					{
						$i++; // Another CTE definition follows.
						continue;
					}

					if ($next['t'] === self::T_WORD && $next['v'] === 'AS')
					{
						continue; // The closed group was a column list; the body follows.
					}

					return $i; // Start of the outer query.
				}

				continue;
			}

			$i++;
		}

		return null;
	}

	/**
	 * Detects a top-level INTO clause (SELECT ... INTO target), scanning from the
	 * main query start so an INTO inside a subquery is ignored.
	 *
	 * The scan starts inside any leading wrapper parentheses, so a ')' closing a
	 * wrapper takes the depth below zero; everything after it is top level again,
	 * as in "(SELECT a FROM t) INTO x". Any depth <= 0 therefore counts as top
	 * level.
	 *
	 * @param array<int, array{t: string, v: string}> $tokens
	 */
	private function hasTopLevelInto(array $tokens, int $start): bool
	{
		$depth = 0;
		$count = count($tokens);

		for ($i = $start; $i < $count; $i++)
		{
			$token = $tokens[$i];

			if ($token['t'] === self::T_PUNCT)
			{
				if ($token['v'] === '(')
				{
					$depth++;
				}
				elseif ($token['v'] === ')')
				{
					$depth--;
				}
				continue;
			}

			if ($depth <= 0 && $token['t'] === self::T_WORD && $token['v'] === 'INTO')
			{
				return true;
			}
		}

		return false;
	}

	private function describeToken(array $token): string
	{
		if ($token['t'] === self::T_WORD)
		{
			return " (found '" . $token['v'] . "')";
		}

		return '';
	}

	private function isWordChar(string $char): bool
	{
		return ($char >= 'a' && $char <= 'z')
			|| ($char >= 'A' && $char <= 'Z')
			|| ($char >= '0' && $char <= '9')
			|| $char === '_';
	}
}
