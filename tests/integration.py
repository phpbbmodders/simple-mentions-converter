#!/usr/bin/env python3
"""Exercise the real CLI on a freshly seeded disposable SQLite phpBB 4.0 board.

Inputs: --board with standard seed-forum fixtures and a marker named
.simple-mentions-test-board. Mutates that disposable board and creates recovery
files in a temporary directory. Outputs assertion results; exits nonzero on
failure. Does not install/reset boards or contact external services.
"""

import argparse
import fcntl
import json
import sqlite3
import subprocess
import tempfile
from pathlib import Path


def main():
    """Run conversion, failure-path, recovery, and native-runtime checks."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("-b", "--board", type=Path, required=True)
    args = parser.parse_args()
    board = args.board.resolve()
    project = Path(__file__).resolve().parents[1]
    if not (board / ".simple-mentions-test-board").is_file():
        parser.error("Disposable-board marker is required")
    database = board / "store/sqlite.db"

    def command(arguments, expected=0):
        result = subprocess.run(
            ["php", *map(str, arguments)], check=False, capture_output=True,
            text=True, timeout=120,
        )
        if result.returncode != expected:
            raise AssertionError(result.stdout + result.stderr)
        return result.stdout + result.stderr

    def cli(*arguments, expected=0):
        return command(
            [project / "convert.php", "--board", board, *arguments], expected,
        )

    def sql(statement, values=()):
        with sqlite3.connect(database) as connection:
            return connection.execute(statement, values).fetchall()

    def purge():
        command([board / "bin/phpbbcli.php", "cache:purge"])

    def snapshot():
        tables = [
            "posts", "privmsgs", "users", "forums", "groups", "topics",
            "poll_options", "config_text", "bbcodes", "notifications",
        ]
        return {name: sql(f"SELECT * FROM phpbb_{name} ORDER BY rowid")
                for name in tables}

    command([project / "tests/board.php", board, "seed"])
    purge()  # Normalize phpBB's permission cache before application snapshots.
    baseline = snapshot()
    preview = cli("--dry-run")
    assert '"changed":16' in preview, preview
    assert snapshot() == baseline, "Dry-run changed application data"
    print("Dry-run preserved all application data; 16 fields identified.")

    with tempfile.TemporaryDirectory(prefix="mentions-test-") as temporary:
        recovery = Path(temporary) / "recovery"
        apply = ["--apply", "--state-dir", str(recovery)]
        sql("UPDATE phpbb_bbcodes SET bbcode_tpl=? WHERE bbcode_id=99",
            ('<strong>{TEXT}</strong>',))
        assert "Unknown/customized" in cli(*apply, expected=1)
        sql("UPDATE phpbb_bbcodes SET bbcode_tpl=? WHERE bbcode_id=99",
            ('<span class="mention">@{TEXT}</span>',))

        sql("UPDATE phpbb_config SET config_value='0' "
            "WHERE config_name='board_disable'")
        purge()
        assert "Disable the board" in cli(*apply, expected=1)
        sql("UPDATE phpbb_config SET config_value='1' "
            "WHERE config_name='board_disable'")
        purge()

        sql("INSERT INTO phpbb_ext(ext_name,ext_active,ext_state) "
            "VALUES ('paul999/mention',1,'')")
        purge()
        assert "Disable the mentions extension" in cli(*apply, expected=1)
        sql("DELETE FROM phpbb_ext WHERE ext_name='paul999/mention'")
        purge()

        original = sql("SELECT post_text FROM phpbb_posts WHERE post_id=2")[0][0]
        sql("UPDATE phpbb_posts SET post_text='<r><MENTION>' WHERE post_id=2")
        malformed = snapshot()
        assert "Malformed" in cli(*apply, expected=1)
        assert snapshot() == malformed, "Preflight wrote content before failure"
        sql("UPDATE phpbb_posts SET post_text=? WHERE post_id=2", (original,))

        assert "outside the board" in cli(
            "--apply", "--state-dir", board / "store/private-recovery",
            expected=1,
        )
        with (board / "store/simple-mentions-converter.lock").open("a") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            assert "Another converter" in cli(*apply, expected=1)

        assert snapshot() == baseline, "Rejection checks changed application data"
        print("Customized BBCode, online board, active extension, malformed XML, "
              "unsafe recovery location, and overlapping run rejected.")

        sql("CREATE TRIGGER converter_test_failure BEFORE UPDATE OF post_text "
            "ON phpbb_posts WHEN OLD.post_id=2 BEGIN SELECT "
            "RAISE(ABORT, 'intentional converter regression'); END")
        failure = cli("--apply", "--state-dir", Path(temporary) / "failure",
                      expected=1)
        assert "intentional converter regression" in failure, failure
        assert snapshot() == baseline, "Failed batch was not completely rolled back"
        sql("DROP TRIGGER converter_test_failure")
        print("Injected second-row SQL failure rolled back the entire batch.")

        partial = cli(*apply, "--batch-size", "1", "--max-batches", "1", expected=2)
        assert "Partial conversion" in partial
        assert sql("SELECT COUNT(*) FROM phpbb_bbcodes WHERE bbcode_id=99")[0][0] == 1
        complete = cli(*apply, "--batch-size", "1")
        assert "Complete:" in complete
        assert not sql("SELECT * FROM phpbb_bbcodes WHERE bbcode_id=99")
        assert snapshot()["notifications"] == baseline["notifications"], (
            "Conversion generated historical notifications"
        )
        files = list(recovery.glob("batch-*.json"))
        assert files and all(path.stat().st_mode & 0o077 == 0 for path in files)
        entries = [json.loads(path.read_text()) for path in files]
        assert any(entry["kind"] == "bbcodes" for entry in entries)
        assert sum(len(entry.get("changes", [])) for entry in entries) == 16
        assert "user_id=\"2\"" in sql(
            "SELECT message_text FROM phpbb_privmsgs ORDER BY msg_id DESC LIMIT 1"
        )[0][0]
        assert all("user_id=\"2\"" in row[0] for row in sql(
            "SELECT poll_option_text FROM phpbb_poll_options WHERE topic_id=1"
        ))
        finished = snapshot()
        assert '"changed":0' in cli("--dry-run")
        cli(*apply)
        assert snapshot() == finished, "Completed rerun changed application data"
        print("Partial run/resume, private backups, all fields, composite poll "
              "keys, deferred cleanup, no historical notifications, and rerun passed.")
        print(command([project / "tests/board.php", board, "check"]).strip())
        print(command([project / "tests/board.php", board, "notify"]).strip())


if __name__ == "__main__":
    main()
