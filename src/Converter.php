<?php
/**
 * Convert Simple Mentions XML tags without reparsing unrelated content.
 * Licensed under GPL-2.0-or-later; see LICENSE.
 */
namespace SimpleMentionsConverter;

final class Converter
{
	private $resolve;

	/** Resolver receives ('name'|'user'|'group', value), returns an ID or null. */
	public function __construct(callable $resolve)
	{
		$this->resolve = $resolve;
	}

	/** Return new XML and counts; malformed or unfamiliar legacy tags fail closed. */
	public function convert(string $xml): array
	{
		$counts = ['converted' => 0, 'unresolved' => 0];
		if (!preg_match('/<(?:SMENTION|MENTION)(?:\s|>)/', $xml))
		{
			// Unparsed text and literal BBCode examples are deliberately untouched.
			if (preg_match('/\[(?:s?mention)(?:[:=\s]|\])/i', $xml)
				&& !str_starts_with($xml, '<r>') && !str_starts_with($xml, '<t>'))
			{
				throw new \RuntimeException('Non-XML mention content: run the phpBB text reparser before conversion.');
			}
			return [$xml, $counts];
		}
		if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false)
		{
			throw new \RuntimeException('DTD/entity declarations are not supported.');
		}
		$previous = libxml_use_internal_errors(true);
		$doc = new \DOMDocument('1.0', 'UTF-8');
		try
		{
			if (!$doc->loadXML($xml, LIBXML_NONET) || !in_array($doc->documentElement->tagName, ['r', 't'], true))
			{
				throw new \RuntimeException('Malformed phpBB text XML.');
			}
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		$nodes = iterator_to_array((new \DOMXPath($doc))->query('//MENTION | //SMENTION'));
		foreach ($nodes as $node)
		{
			// A literal inside CODE must never become an active mention.
			for ($ancestor = $node->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode)
			{
				if (in_array($ancestor->tagName, ['CODE', 'MENTION', 'SMENTION'], true))
				{
					throw new \RuntimeException('Nested mention or parsed mention inside CODE requires manual review.');
				}
			}
			if ($node->tagName === 'MENTION' && ($node->hasAttribute('user_id') || $node->hasAttribute('group_id')))
			{
				continue; // Already native; do not look up a name or change attributes.
			}
			$attributes = [];
			foreach ($node->attributes as $attribute)
			{
				$attributes[$attribute->name] = $attribute->value;
			}
			if (array_diff(array_keys($attributes), $node->tagName === 'SMENTION' ? ['u', 'g'] : []))
			{
				throw new \RuntimeException('Unrecognized legacy mention attributes.');
			}
			$start = $end = null;
			$label = '';
			foreach ($node->childNodes as $child)
			{
				if ($child instanceof \DOMElement)
				{
					if ($child->tagName === 's' && $start === null)
					{
						$start = $child;
					}
					else if ($child->tagName === 'e' && $end === null)
					{
						$end = $child;
					}
					else
					{
						throw new \RuntimeException('Formatted/nested legacy mention label requires manual review.');
					}
				}
				else
				{
					$label .= $child->textContent;
				}
			}
			if (!$start || !$end)
			{
				throw new \RuntimeException('Legacy mention has no BBCode delimiters.');
			}
			$kind = 'name';
			$value = $label;
			if ($node->tagName === 'SMENTION')
			{
				$ids = array_filter($attributes, static fn($id) => $id !== '');
				if (count($ids) !== 1)
				{
					throw new \RuntimeException('SMENTION must identify exactly one user or group.');
				}
				$key = array_key_first($ids);
				$value = $ids[$key];
				if (!preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > 10 || (int) $value > 2147483647)
				{
					throw new \RuntimeException('Invalid legacy mention ID.');
				}
				$kind = $key === 'u' ? 'user' : 'group';
				$expected = '/^\[smention\s+' . $key . '=["\x27]?' . $value . '["\x27]?\]$/iD';
			}
			else
			{
				$expected = '/^\[mention\]$/iD';
				// The legacy definition also swallows native 4.0 syntax, dropping
				// its XML ID attribute. Recover the authoritative ID from <s>.
				if (preg_match('/^\[mention=([ug]):([1-9][0-9]*)\]$/iD', $start->textContent, $native))
				{
					$value = $native[2];
					if (strlen($value) > 10 || (int) $value > 2147483647)
					{
						throw new \RuntimeException('Invalid swallowed native mention ID.');
					}
					$kind = strtolower($native[1]) === 'g' ? 'group' : 'user';
					$expected = '/^' . preg_quote($start->textContent, '/') . '$/D';
				}
			}
			if (!preg_match($expected, $start->textContent)
				|| strcasecmp($end->textContent, '[/' . strtolower($node->tagName) . ']') !== 0)
			{
				throw new \RuntimeException('Legacy tag attributes and BBCode delimiters disagree.');
			}
			$id = ($this->resolve)($kind, $value);
			if ($id === null)
			{
				$node->parentNode->replaceChild($doc->createTextNode('@' . $label), $node);
				++$counts['unresolved'];
			}
			else
			{
				$native = $doc->createElement('MENTION');
				$native->setAttribute($kind === 'group' ? 'group_id' : 'user_id', (string) $id);
				$native->appendChild($doc->createElement('s', '[mention=' . ($kind === 'group' ? 'g:' : 'u:') . $id . ']'));
				$native->appendChild($doc->createTextNode($label));
				$native->appendChild($doc->createElement('e', '[/mention]'));
				$node->parentNode->replaceChild($native, $node);
				++$counts['converted'];
			}
		}
		return [$counts['converted'] + $counts['unresolved'] ? $doc->saveXML($doc->documentElement) : $xml, $counts];
	}

	/** Only the exact definitions shipped by Simple Mentions may be removed. */
	public static function isLegacyDefinition(array $row): bool
	{
		return strtolower($row['bbcode_tag']) === 'mention'
			&& $row['bbcode_match'] === '[mention]{TEXT}[/mention]'
			&& in_array($row['bbcode_tpl'], [
				'<em class="mention">@{TEXT}</em>',
				'<span class="mention">@{TEXT}</span>',
			], true);
	}
}
