# Simple Mentions to phpBB 4.0 converter

A standalone, one-time CLI tool for moving existing Simple Mentions content to
phpBB 4.0's built-in mentions. It also repairs native mention tags parsed while
the leftover custom `mention` BBCode was overriding the core definition.

Run it **after upgrading a copy of the board to phpBB 4.0**, with Simple Mentions
disabled. It works even if the extension's data and files have already been
deleted. No extension installation or phpBB core edits are required.

The tool uses the installed board's PHP dependencies and database connection.
It requires PHP 8.1 or later, PHP DOM, and a complete phpBB 4.0 installation.
The source/database version and native parser are checked at runtime because
phpBB 4.0 is still in development. See [verification](docs/verification.md) for
the exact environment and limits of testing.

## Usage

Keep this directory outside your web root. Use the same PHP version and operating
system account used for phpBB CLI maintenance; it must be able to write phpBB's
cache and store directories.

1. Back up the **full database** and test the upgrade/conversion on a copy first.
2. Finish the phpBB 4.0 database upgrade and disable Simple Mentions. Do not
   re-enable it after conversion.
3. Preview the conversion:

   ```bash
   php convert.php --board /srv/forum --dry-run
   ```

4. Review the counts and limitations below. Disable the board in ACP, and stop
   web/admin writes, cron, queues, reparsers, and any other database writers.
   Board-disabled mode alone still permits administrators and background jobs.
5. Apply, using a private recovery directory outside the board:

   ```bash
   php convert.php --board /srv/forum --apply --state-dir /srv/private/mentions-recovery
   ```

   The tool creates a missing directory with private permissions. An existing
   recovery directory must have mode `700`. Use it only for this board.
6. After exit code `0`, check old posts, editing, quoting, and new mention
   notifications using two accounts. Enable mentions and grant phpBB 4.0's
   native mention permission where desired; the converter does not change
   feature settings, permissions, or notification preferences. Reopen the board.

With no arguments, the tool prints help. `-h` / `--help` lists all options.
`--dry-run` changes no content or BBCode rows; bootstrapping phpBB can create its
normal cache files.

## Conversion behavior

| Stored content | Result |
| --- | --- |
| Legacy `[mention]Username[/mention]` | Native user mention using a unique current `username_clean` match |
| `[smention u=ID]Label[/smention]` | Native user mention preserving ID and label |
| `[smention g=ID]Label[/smention]` | Native group mention preserving ID and label |
| Native `[mention=u:ID]` / `[mention=g:ID]` swallowed by the old BBCode | Recover the ID from the stored opening delimiter |
| Missing user/group, guest account, or unresolved name | Plain `@Label` text, counted as `unresolved` |
| Already-native parsed mention | Unchanged |
| Literal BBCode examples inside CODE or unparsed XML text | Unchanged |

Only parsed mention tags are transformed. Other formatting, links, quote
metadata, Unicode, and surrounding content are retained. XML may be serialized
with equivalent entity/empty-element notation. The tool does not reparse the
entire post, call posting APIs, or send historical notifications.

It checks posts, private messages, signatures, forum descriptions/rules, group
descriptions, poll titles/options, and contact-admin information. Content owned
by other extensions (for example, a knowledgebase's custom tables), backups,
drafts, search indexes, and external archives are outside its scope.

Old username-only mentions have no historical user ID to recover. A renamed
user may therefore be unresolved, or an old name may now belong to another
account. On boards with renamed/reused usernames, review or reconcile those
names on the test copy before applying. The tool cannot infer historical
ownership from a username alone.

Cleanup accepts only the exact legacy `mention` definitions shipped with
Simple Mentions: `[mention]{TEXT}[/mention]` and its known `em`/`span` templates.
A customized definition, conflicting `smention` BBCode, malformed XML,
unrecognized tag attributes, or nested/formatted mention label stops the run
for manual review. Pre-XML stored BBCode must be handled with phpBB's text
reparser before running this tool; it is not guessed or silently discarded.

The conflicting BBCode is removed only after conversion and a full follow-up
scan succeed. The formatter cache is rebuilt and probed for a native user-ID
mention. An unknown definition is never automatically deleted.

## Batches and recovery

`--batch-size 100` is the default; values from `1` to `1000` are accepted.
Memory use is bounded by a batch, with a bounded user/group resolution cache.
There is a complete preflight scan before content changes and a final audit
before cleanup; these scans can take time on large boards.

For a deliberately partial run:

```bash
php convert.php --board /srv/forum --apply --state-dir /srv/private/mentions-recovery \
  --batch-size 100 --max-batches 10
```

Exit code `2` means partial conversion. Keep the board offline and rerun the
same command to continue, or omit `--max-batches` to finish. Reruns scan from
the beginning and skip already-converted tags. There is no cursor that can
skip an uncommitted batch after a crash. Repeating a completed conversion is
safe. The old BBCode stays in place until all batches are complete.

Before each changed batch, a private JSON file records primary keys, field
names, and original/replacement text. Before deletion, a separate JSON file
records the full legacy BBCode rows. Files are flushed and synced before the
corresponding database transaction begins. They contain private forum/PM data:
keep the directory outside **all** served web roots and protect it like a
database backup. `board.json` binds it to the board/database without storing
database credentials.

Recovery files describe attempted batches; an interrupted or rolled-back batch
may have a recovery file even though it made no database changes. They are an
audit/recovery aid, **not** a full database backup or an automatic undo command.
For a full rollback, restore the full database backup while the board remains
offline, then purge phpBB's cache. Do not blindly replay recovery files over
new content. An expert restoring an individual field should match its current
value against `after` before restoring `before`.

Exit code `1` means an error. Previously committed batches remain converted;
the current failed transaction rolls back. Fix the reported condition and
rerun while offline. Two converter processes against the same board directory
are blocked using a store-file lock. Keep a shared board path when maintaining
a multi-host installation; the tool does not lock out other applications.

SQLite, InnoDB through phpBB's `mysqli` driver, and PostgreSQL are accepted.
Non-InnoDB MySQL/MariaDB tables are rejected before writes because partial
rollback would be unsafe. Other database drivers are rejected for apply.

## Development

Run the focused tests:

```bash
php tests/converter.php
```

The integration test **modifies a disposable SQLite board** with standard
seed-forum fixtures. Create `.simple-mentions-test-board` in that disposable
board, then run:

```bash
python3 tests/integration.py --board /path/to/disposable-board
```

Start from a freshly installed and seeded board each time. The PHP fixture
helper also supports `seed`, `check`, and `notify` for database-independent
runtime checks; these actions likewise require the disposable-board marker.

## Contributing

Contributions are welcome!

- **Bug reports**: [Open an issue](https://github.com/phpbbmodders/simple-mentions-converter/issues).
- **Everything else** (questions, feature requests, ideas, general discussion): [Use Discussions](https://github.com/phpbbmodders/simple-mentions-converter/discussions).
- Pull requests are welcome for bug fixes or discussed features.

## Acknowledgments

- Code, tests, and documentation assisted by [Codex](https://www.anthropic.com/Codex).
- Migration formats are based on [Paul's Simple Mentions](https://github.com/paul999/mention)
  and [phpBB's native mention implementation](https://github.com/phpbb/phpbb).

## License

GNU General Public License, version 2 or later. See [LICENSE](LICENSE).
