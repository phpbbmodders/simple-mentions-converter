#!/usr/bin/env php
<?php
/**
 * One-time Simple Mentions -> phpBB 4.0 conversion CLI.
 * Reads an installed board; --apply writes bounded transactions and backups.
 * No posting or notification APIs are called. See README.md before use.
 * Licensed under GPL-2.0-or-later; see LICENSE.
 */
use SimpleMentionsConverter\Converter;

if (PHP_SAPI !== 'cli')
{
	http_response_code(403);
	exit;
}
require __DIR__ . '/src/Converter.php';

function usage(): void
{
	echo <<<'HELP'
Usage: php convert.php --board /path/to/phpBB --dry-run
       php convert.php --board /path/to/phpBB --apply --state-dir /private/backups

  -b, --board PATH        Installed phpBB 4.0 board (required).
  -n, --dry-run           Read and report; no content or BBCode writes.
  -a, --apply             Convert with board disabled and Simple Mentions inactive.
  -s, --state-dir PATH    Private recovery directory OUTSIDE the board (apply only).
  -z, --batch-size N      Rows read per transaction, 1..1000 (default 100).
  -m, --max-batches N     Stop after N changed batches; rerun to continue.
  -h, --help              Show help. No arguments also shows help.

Back up the full database first. Stop web/admin/cron/queue writers during apply.
Reruns rescan from the beginning and skip converted tags; no cursor can lose work.
Exit codes: 0 complete/dry-run, 1 error, 2 partial (--max-batches).
HELP;
	echo PHP_EOL;
}

/** Parse explicit options, rejecting unknown/duplicate arguments. */
function options(array $args): array
{
	$aliases = ['b' => 'board', 'n' => 'dry-run', 'a' => 'apply', 's' => 'state-dir', 'z' => 'batch-size', 'm' => 'max-batches', 'h' => 'help'];
	$flags = ['help', 'dry-run', 'apply'];
	$values = ['board', 'state-dir', 'batch-size', 'max-batches'];
	$out = [];
	while ($args)
	{
		$arg = array_shift($args);
		if (!preg_match('/^--?([a-z-]+)(?:=(.*))?$/D', $arg, $match))
		{
			throw new RuntimeException('Unexpected argument: ' . $arg);
		}
		$name = $aliases[$match[1]] ?? $match[1];
		if (isset($out[$name]))
		{
			throw new RuntimeException('Duplicate option: ' . $name);
		}
		if (in_array($name, $flags, true) && !isset($match[2]))
		{
			$out[$name] = true;
		}
		else if (in_array($name, $values, true))
		{
			$value = $match[2] ?? array_shift($args);
			if ($value === null || $value === '' || str_starts_with($value, '-'))
			{
				throw new RuntimeException('Missing value for --' . $name);
			}
			$out[$name] = $value;
		}
		else
		{
			throw new RuntimeException('Unknown option: ' . $arg);
		}
	}
	return $out;
}

/** Write an exclusive, private, durable recovery file before database writes. */
function backup(string $directory, array $data): void
{
	$path = $directory . '/batch-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8)) . '.json';
	$handle = fopen($path . '.tmp', 'x');
	if (!$handle)
	{
		throw new RuntimeException('Cannot create recovery file.');
	}
	try
	{
		$text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
		$offset = 0;
		while ($offset < strlen($text))
		{
			$written = fwrite($handle, substr($text, $offset));
			if (!$written)
			{
				throw new RuntimeException('Recovery write failed; database batch not started.');
			}
			$offset += $written;
		}
		if (!fflush($handle) || !fsync($handle))
		{
			throw new RuntimeException('Cannot flush recovery file.');
		}
	}
	finally
	{
		fclose($handle);
	}
	if (!rename($path . '.tmp', $path))
	{
		throw new RuntimeException('Cannot finalize recovery file.');
	}
}

/** Escape one value using the board's DBAL. Identifiers are fixed in source. */
function quoted($db, $value): string
{
	return "'" . $db->sql_escape((string) $value) . "'";
}

/** Build an equality predicate for the complete primary key. */
function key_where($db, array $key): string
{
	$parts = [];
	foreach ($key as $column => $value)
	{
		$parts[] = $column . ' = ' . quoted($db, $value);
	}
	return implode(' AND ', $parts);
}

/** Keyset pagination also handles poll options' composite primary key. */
function after_key($db, array $key): string
{
	$terms = $equals = [];
	foreach ($key as $column => $value)
	{
		$terms[] = '(' . implode(' AND ', array_merge($equals, [$column . ' > ' . quoted($db, $value)])) . ')';
		$equals[] = $column . ' = ' . quoted($db, $value);
	}
	return $terms ? '(' . implode(' OR ', $terms) . ')' : '1=1';
}

/** Scan standard phpBB formatted fields, optionally saving changed batches. */
function scan($db, Converter $converter, array $sources, int $size, bool $apply, ?string $directory, int $limit): array
{
	$totals = ['rows' => 0, 'changed' => 0, 'converted' => 0, 'unresolved' => 0, 'batches' => 0];
	foreach ($sources as [$table, $keys, $fields, $filter])
	{
		$cursor = [];
		do
		{
			$query = 'SELECT ' . implode(', ', array_merge($keys, $fields)) . ' FROM ' . $table
				. ' WHERE (' . $filter . ') AND ' . after_key($db, $cursor) . ' ORDER BY ' . implode(', ', $keys);
			$result = $db->sql_query_limit($query, $size);
			$rows = $db->sql_fetchrowset($result);
			$db->sql_freeresult($result);
			$changes = [];
			foreach ($rows as $row)
			{
				++$totals['rows'];
				$cursor = array_intersect_key($row, array_flip($keys));
				foreach ($fields as $field)
				{
					try
					{
						[$text, $counts] = $converter->convert($row[$field]);
					}
					catch (Throwable $error)
					{
						throw new RuntimeException($table . '.' . $field . ' key ' . json_encode($cursor) . ': ' . $error->getMessage());
					}
					foreach ($counts as $name => $count)
					{
						$totals[$name] += $count;
					}
					if ($text !== $row[$field])
					{
						$changes[] = ['table' => $table, 'key' => $cursor, 'field' => $field, 'before' => $row[$field], 'after' => $text];
						++$totals['changed'];
					}
				}
			}
			if ($changes)
			{
				++$totals['batches'];
				if ($apply)
				{
					backup($directory, ['format' => 1, 'kind' => 'content', 'changes' => $changes]);
					$db->sql_transaction('begin');
					try
					{
						foreach ($changes as $change)
						{
							$db->sql_query('UPDATE ' . $change['table'] . ' SET ' . $change['field'] . ' = ' . quoted($db, $change['after'])
								. ' WHERE ' . key_where($db, $change['key']) . ' AND ' . $change['field'] . ' = ' . quoted($db, $change['before']));
							if ($db->sql_affectedrows() !== 1)
							{
								throw new RuntimeException('Content changed during conversion; stop other writers and rerun.');
							}
						}
						$db->sql_transaction('commit');
					}
					catch (Throwable $error)
					{
						if ($db->get_transaction())
						{
							$db->sql_transaction('rollback');
						}
						throw $error;
					}
					echo 'Converted batch: ' . $table . ' through ' . json_encode($cursor) . PHP_EOL;
					if ($limit && $totals['batches'] >= $limit)
					{
						return [$totals, false];
					}
				}
			}
		}
		while (count($rows) === $size);
	}
	return [$totals, true];
}

try
{
	$options = options(array_slice($argv, 1));
	if (!$options || isset($options['help']))
	{
		usage();
		exit(0);
	}
	if (isset($options['apply']) === isset($options['dry-run']))
	{
		throw new RuntimeException('Select exactly one of --dry-run or --apply.');
	}
	$apply = isset($options['apply']);
	$size = $options['batch-size'] ?? '100';
	$limit = $options['max-batches'] ?? '0';
	if (!ctype_digit($size) || (int) $size < 1 || (int) $size > 1000
		|| !ctype_digit($limit) || strlen($limit) > 9 || (isset($options['max-batches']) && (int) $limit < 1))
	{
		throw new RuntimeException('Invalid batch size (1..1000) or max batches (positive integer).');
	}
	if (!$apply && (isset($options['state-dir']) || isset($options['max-batches'])))
	{
		throw new RuntimeException('--state-dir and --max-batches require --apply.');
	}
	$root = realpath($options['board'] ?? '');
	if (!isset($options['board']) || !$root || !is_file($root . '/config.php') || !is_file($root . '/vendor/autoload.php'))
	{
		throw new RuntimeException('--board must identify an installed phpBB board with its Composer dependencies.');
	}
	// Check the source version before executing the board's bootstrap.
	$constants = file_get_contents($root . '/includes/constants.php');
	if (!preg_match("/define\('PHPBB_VERSION', '([^']+)'\)/", $constants, $version)
		|| !str_starts_with($version[1], '4.0.'))
	{
		throw new RuntimeException('Only phpBB 4.0.x is supported.');
	}
	if (!class_exists('DOMDocument'))
	{
		throw new RuntimeException('The PHP DOM extension is required.');
	}
	$phpbb_root_path = $root . '/';
	require __DIR__ . '/src/bootstrap.php';
	$db->sql_return_on_error(false);
	// Turn database failures into catchable exceptions so active batches roll back.
	set_error_handler(static function ($severity, $message, $file, $line)
	{
		if (error_reporting() & $severity)
		{
			throw new ErrorException($message, 0, $severity, $file, $line);
		}
		return false;
	});
	if (!str_starts_with((string) $config['version'], '4.0.'))
	{
		throw new RuntimeException('Finish the phpBB 4.0 database upgrade first.');
	}
	$result = $db->sql_query('SELECT ext_name FROM ' . EXT_TABLE . ' WHERE ext_active = 1');
	foreach ($db->sql_fetchrowset($result) as $extension)
	{
		if (preg_match('~(?:^|/)mentions?$~i', $extension['ext_name']))
		{
			throw new RuntimeException('Disable the mentions extension before running this tool: ' . $extension['ext_name']);
		}
	}
	$db->sql_freeresult($result);
	$result = $db->sql_query('SELECT * FROM ' . BBCODES_TABLE);
	$legacy = [];
	foreach ($db->sql_fetchrowset($result) as $row)
	{
		if (in_array(strtolower(rtrim($row['bbcode_tag'], '=')), ['mention', 'smention'], true))
		{
			if (!Converter::isLegacyDefinition($row))
			{
				throw new RuntimeException('Unknown/customized mention BBCode definition; cleanup requires manual review.');
			}
			$legacy[] = $row;
		}
	}
	$db->sql_freeresult($result);
	$sources = [
		[POSTS_TABLE, ['post_id'], ['post_text'], '1=1'],
		[PRIVMSGS_TABLE, ['msg_id'], ['message_text'], '1=1'],
		[USERS_TABLE, ['user_id'], ['user_sig'], '1=1'],
		[FORUMS_TABLE, ['forum_id'], ['forum_desc', 'forum_rules'], '1=1'],
		[GROUPS_TABLE, ['group_id'], ['group_desc'], '1=1'],
		[TOPICS_TABLE, ['topic_id'], ['poll_title'], '1=1'],
		[POLL_OPTIONS_TABLE, ['topic_id', 'poll_option_id'], ['poll_option_text'], '1=1'],
		[CONFIG_TEXT_TABLE, ['config_name'], ['config_value'], "config_name = 'contact_admin_info'"],
	];
	$converter = new Converter(static function (string $kind, string $value) use ($db): ?int
	{
		static $resolved = [];
		$cacheKey = $kind . ':' . $value;
		if (array_key_exists($cacheKey, $resolved))
		{
			return $resolved[$cacheKey];
		}
		if (count($resolved) >= 4096)
		{
			$resolved = []; // Bound memory on large boards.
		}
		$table = $kind === 'group' ? GROUPS_TABLE : USERS_TABLE;
		$id = $kind === 'group' ? 'group_id' : 'user_id';
		$where = $kind === 'name' ? 'username_clean = ' . quoted($db, utf8_clean_string($value)) : $id . ' = ' . (int) $value;
		$result = $db->sql_query('SELECT ' . $id . ' FROM ' . $table . ' WHERE ' . $where);
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		$minimum = $kind === 'group' ? 0 : ANONYMOUS;
		return $resolved[$cacheKey] = count($rows) === 1 && (int) $rows[0][$id] > $minimum ? (int) $rows[0][$id] : null;
	});
	echo 'Board: phpBB ' . $config['version'] . '; legacy BBCode definitions: ' . count($legacy) . PHP_EOL;
	$lock = null;
	$directory = null;
	if ($apply)
	{
		$layer = $db->get_sql_layer();
		if (!in_array($layer, ['sqlite3', 'mysqli', 'postgres'], true))
		{
			throw new RuntimeException('Unsupported database driver: ' . $layer);
		}
		if ($layer === 'mysqli')
		{
			$tables = array_unique(array_merge(array_column($sources, 0), [BBCODES_TABLE]));
			$result = $db->sql_query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND '
				. $db->sql_in_set('TABLE_NAME', $tables));
			$engines = $db->sql_fetchrowset($result);
			$db->sql_freeresult($result);
			if (count($engines) !== count($tables))
			{
				throw new RuntimeException('Cannot verify all target table storage engines.');
			}
			foreach ($engines as $engine)
			{
				if (strcasecmp($engine['ENGINE'], 'InnoDB') !== 0)
				{
					throw new RuntimeException('Transactional InnoDB tables required: ' . $engine['TABLE_NAME']);
				}
			}
		}
		if (empty($config['board_disable']))
		{
			throw new RuntimeException('Disable the board in ACP first, and stop web/admin/cron/queue writers.');
		}
		if (!isset($options['state-dir']))
		{
			throw new RuntimeException('--apply requires --state-dir outside the board.');
		}
		$requested = $options['state-dir'];
		if (!is_dir($requested) && !mkdir($requested, 0700, true))
		{
			throw new RuntimeException('Cannot create recovery directory.');
		}
		$directory = realpath($requested);
		if (!$directory || $directory === $root || str_starts_with($directory . '/', $root . '/')
			|| (fileperms($directory) & 0077) !== 0)
		{
			throw new RuntimeException('Recovery directory must be outside the board and private (chmod 700).');
		}
		umask(0077);
		// The canonical board path serializes runs even with different state dirs.
		$lockPath = $root . '/store/simple-mentions-converter.lock';
		if (is_link($lockPath))
		{
			throw new RuntimeException('Refusing a symlink for the converter lock.');
		}
		$lock = fopen($lockPath, 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
		{
			throw new RuntimeException('Another converter is running or the board store directory is not writable.');
		}
		$identity = ['root' => $root, 'prefix' => $table_prefix, 'database' => hash('sha256',
			$dbms . '|' . $dbhost . '|' . ($dbport ?? '') . '|' . $dbname . '|' . $table_prefix)];
		$identityPath = $directory . '/board.json';
		if (is_link($identityPath))
		{
			throw new RuntimeException('Refusing a symlink for recovery metadata.');
		}
		if (is_file($identityPath))
		{
			if (json_decode(file_get_contents($identityPath), true, 512, JSON_THROW_ON_ERROR) !== $identity)
			{
				throw new RuntimeException('Recovery directory belongs to a different board/database.');
			}
		}
		else
		{
			$handle = fopen($identityPath, 'x');
			if (!$handle || fwrite($handle, json_encode($identity, JSON_THROW_ON_ERROR)) === false || !fflush($handle) || !fsync($handle))
			{
				throw new RuntimeException('Cannot save board recovery identity.');
			}
			fclose($handle);
		}
	}
	try
	{
		// Validate all supported fields before any content changes.
		[$preview] = scan($db, $converter, $sources, (int) $size, false, null, 0);
		echo 'Preflight: ' . json_encode($preview, JSON_THROW_ON_ERROR) . PHP_EOL;
		if (!$apply)
		{
			echo 'Dry-run complete. Unresolved mentions would become plain @label text.' . PHP_EOL;
			exit(0);
		}
		[$totals, $complete] = scan($db, $converter, $sources, (int) $size, true, $directory, (int) $limit);
		echo 'Applied: ' . json_encode($totals, JSON_THROW_ON_ERROR) . PHP_EOL;
		if (!$complete)
		{
			echo 'Partial conversion; keep the board offline and rerun the same command. BBCode cleanup deferred.' . PHP_EOL;
			exit(2);
		}
		[$remaining] = scan($db, $converter, $sources, (int) $size, false, null, 0);
		if ($remaining['changed'])
		{
			throw new RuntimeException('Legacy tags remain after conversion; keep offline and rerun.');
		}
		if ($legacy)
		{
			backup($directory, ['format' => 1, 'kind' => 'bbcodes', 'rows' => $legacy]);
		}
		$db->sql_transaction('begin');
		try
		{
			foreach ($legacy as $row)
			{
				$db->sql_query('DELETE FROM ' . BBCODES_TABLE . ' WHERE bbcode_id = ' . (int) $row['bbcode_id']
					. ' AND bbcode_match = ' . quoted($db, $row['bbcode_match']) . ' AND bbcode_tpl = ' . quoted($db, $row['bbcode_tpl']));
				if ($db->sql_affectedrows() !== 1)
				{
					throw new RuntimeException('BBCode definition changed during conversion; cleanup stopped.');
				}
			}
			$phpbb_container->get('cache.driver')->destroy('sql', BBCODES_TABLE);
			$factory = $phpbb_container->get('text_formatter.s9e.factory');
			$objects = $factory->regenerate();
			$probe = $objects['parser']->parse('[mention=u:2]Converter probe[/mention]');
			$probeDoc = new DOMDocument();
			$probeDoc->loadXML($probe, LIBXML_NONET);
			if ((new DOMXPath($probeDoc))->query('//MENTION[@user_id="2"]')->length !== 1)
			{
				throw new RuntimeException('Native phpBB mention parser probe failed; BBCode cleanup rolled back.');
			}
			$db->sql_transaction('commit');
		}
		catch (Throwable $error)
		{
			if ($db->get_transaction())
			{
				$db->sql_transaction('rollback');
			}
			$phpbb_container->get('cache.driver')->destroy('sql', BBCODES_TABLE);
			$phpbb_container->get('text_formatter.s9e.factory')->invalidate();
			throw $error;
		}
		echo 'Complete: legacy tags converted, conflicting BBCode removed, native formatter rebuilt and checked. No historical notifications sent.' . PHP_EOL;
	}
	finally
	{
		if (is_resource($lock))
		{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
catch (Throwable $error)
{
	fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
	exit(1);
}
