<?php
/**
 * Runtime fixture/check helper. Use only on an explicitly disposable seed board.
 * php tests/board.php /absolute/board seed|check
 * The seed action overwrites fixture post text and enables maintenance mode.
 */
$phpbb_root_path = realpath($argv[1] ?? '') . '/';
if (!is_file($phpbb_root_path . '.simple-mentions-test-board'))
{
	throw new RuntimeException('Create .simple-mentions-test-board on a disposable board first.');
}
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Converter.php';
$action = $argv[2] ?? '';
$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE username = 'admin'");
$admin = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
$user->data = $admin;
$user->data['is_registered'] = true;
$user->data['is_bot'] = false;
$auth->acl($user->data);
$user->style = ['style_id' => 1];
$language->add_lang(['posting', 'viewtopic']);
$factory = $phpbb_container->get('text_formatter.s9e.factory');
if ($action === 'seed')
{
	$configurator = $factory->get_configurator();
	// These are the exact definitions shipped by Simple Mentions 1.0.5/2.0.0.
	$configurator->BBCodes->addCustom('[mention]{TEXT}[/mention]', '<span class="mention">@{TEXT}</span>');
	$configurator->BBCodes->addCustom('[smention u={NUMBER?} g={NUMBER?}]{TEXT}[/smention]', '<em class="mention">@{TEXT}</em>');
	$legacyParser = $configurator->finalize()['parser'];
	$fixtures = [
		'[b]Keep bold[/b] [mention]admin[/mention]',
		'[quote=Someone][smention u=2]Earlier name[/smention][/quote]',
		'[smention g=1]First group[/smention]',
		'[mention]Missing old user[/mention]',
		'[code][mention]admin[/mention][/code]',
		'[smention u=999999]Deleted user[/smention]',
		'[mention=u:2]Swallowed core user[/mention]',
		'[mention=g:1]Swallowed core group[/mention]',
	];
	$xmls = array_map(static fn($text) => $legacyParser->parse($text), $fixtures);
	$result = $db->sql_query_limit('SELECT post_id FROM ' . POSTS_TABLE . ' ORDER BY post_id', count($xmls));
	$ids = array_column($db->sql_fetchrowset($result), 'post_id');
	$db->sql_freeresult($result);
	foreach ($ids as $index => $id)
	{
		$db->sql_query('UPDATE ' . POSTS_TABLE . " SET post_text = '" . $db->sql_escape($xmls[$index]) . "' WHERE post_id = " . (int) $id);
	}
	foreach ([
		[USERS_TABLE, 'user_sig', 'user_id = 2'],
		[FORUMS_TABLE, 'forum_desc', 'forum_id = 2'],
		[FORUMS_TABLE, 'forum_rules', 'forum_id = 2'],
		[GROUPS_TABLE, 'group_desc', 'group_id = 1'],
		[TOPICS_TABLE, 'poll_title', 'topic_id = 1'],
	] as [$table, $field, $where])
	{
		$db->sql_query('UPDATE ' . $table . ' SET ' . $field . " = '" . $db->sql_escape($xmls[0]) . "' WHERE " . $where);
	}
	// Composite key: two poll options in the same topic, split with batch size 1.
	foreach ([0, 1] as $optionId)
	{
		$db->sql_query('INSERT INTO ' . POLL_OPTIONS_TABLE . ' ' . $db->sql_build_array('INSERT', [
			'topic_id' => 1, 'poll_option_id' => $optionId, 'poll_option_text' => $xmls[1], 'poll_option_total' => 0,
		]));
	}
	$phpbb_container->get('config_text')->set('contact_admin_info', $xmls[0]);
	$db->sql_query('INSERT INTO ' . PRIVMSGS_TABLE . ' ' . $db->sql_build_array('INSERT', [
		'author_id' => 2, 'message_text' => $xmls[1], 'to_address' => 'u_2', 'bcc_address' => '',
	]));
	$bbcode = [
		'bbcode_id' => 99, 'bbcode_tag' => 'mention', 'bbcode_match' => '[mention]{TEXT}[/mention]',
		'bbcode_tpl' => '<span class="mention">@{TEXT}</span>', 'display_on_posting' => 0,
		'bbcode_helpline' => '', 'first_pass_match' => '', 'first_pass_replace' => '',
		'second_pass_match' => '', 'second_pass_replace' => '',
	];
	$db->sql_query('INSERT INTO ' . BBCODES_TABLE . ' ' . $db->sql_build_array('INSERT', $bbcode));
	$config->set('board_disable', 1);
	$factory->regenerate(); // Cache the collision, just like an upgraded board.
	file_put_contents($phpbb_root_path . 'store/mention-test-fixtures.json', json_encode(['ids' => $ids, 'xmls' => $xmls], JSON_PRETTY_PRINT));
	echo json_encode($xmls, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
else if ($action === 'check')
{
	$fixtures = json_decode(file_get_contents($phpbb_root_path . 'store/mention-test-fixtures.json'), true);
	$helper = $phpbb_container->get('text_formatter.s9e.mention_helper');
	$objects = $factory->regenerate();
	$renderer = $phpbb_container->get('text_formatter.renderer');
	$renderer->set_usemention(true);
	foreach ($fixtures['ids'] as $index => $id)
	{
		$result = $db->sql_query('SELECT post_text FROM ' . POSTS_TABLE . ' WHERE post_id = ' . (int) $id);
		$xml = $db->sql_fetchfield('post_text');
		$db->sql_freeresult($result);
		$html = $renderer->render($xml);
		$editable = $phpbb_container->get('text_formatter.utils')->unparse($xml);
		if ($index < 2 && $helper->get_mentioned_user_ids($xml) !== [2])
		{
			throw new RuntimeException('Converted user ID not recognized by core helper.');
		}
		if ($index === 0 && (!preg_match('~<strong\b[^>]*>Keep bold</strong>~', $html) || !str_contains($html, '@') || !str_contains($editable, '[mention=u:2]admin[/mention]')))
		{
			throw new RuntimeException('Rendering or editing did not preserve expected content: ' . json_encode([$html, $editable]));
		}
		if ($index === 1 && !str_contains($editable, '[quote=Someone]'))
		{
			throw new RuntimeException('Quote formatting lost.');
		}
		if ($index === 2 && !str_contains($xml, 'group_id="1"'))
		{
			throw new RuntimeException('Group 1 conversion failed.');
		}
		if ($index === 4 && $xml !== $fixtures['xmls'][$index])
		{
			throw new RuntimeException('Literal code example was changed.');
		}
		if (($index === 6 && $helper->get_mentioned_user_ids($xml) !== [2])
			|| ($index === 7 && !str_contains($xml, 'group_id="1"')))
		{
			throw new RuntimeException('Swallowed native mention target was not recovered.');
		}
		$reparsed = $objects['parser']->parse($editable);
		if ($index < 2 && $helper->get_mentioned_user_ids($reparsed) !== [2])
		{
			throw new RuntimeException('Mention lost when edited/reparsed.');
		}
	}
	$probe = $objects['parser']->parse('[mention=u:2]admin[/mention]');
	if ($helper->get_mentioned_user_ids($probe) !== [2])
	{
		throw new RuntimeException('New mention parser/helper failed.');
	}
	echo "Core rendering, editing/reparse, quoted content, group ID, literal CODE, and new mention extraction passed.\n";
}
else if ($action === 'notify')
{
	require_once $phpbb_root_path . 'includes/functions_posting.php';
	$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username = 'forum_alex'");
	$recipient = (int) $db->sql_fetchfield('user_id');
	$db->sql_freeresult($result);
	$result = $db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE . " WHERE forum_type = 1 AND forum_name = 'General Discussion'");
	$forum = (int) $db->sql_fetchfield('forum_id');
	$db->sql_freeresult($result);
	if (!$forum || !$recipient)
	{
		throw new RuntimeException('Standard fixtures required.');
	}
	$config->set('allow_mentions', 1);
	$message = '[mention=u:' . $recipient . ']forum_alex[/mention] notification regression';
	$uid = $bitfield = '';
	$flags = 0;
	generate_text_for_storage($message, $uid, $bitfield, $flags, true, true, true);
	$data = [
		'forum_id' => $forum, 'topic_id' => 0, 'topic_title' => 'Mention converter regression',
		'icon_id' => 0, 'poster_id' => 2, 'enable_bbcode' => true,
		'enable_smilies' => true, 'enable_urls' => true, 'enable_sig' => false,
		'message' => $message, 'message_md5' => md5($message),
		'bbcode_uid' => $uid, 'bbcode_bitfield' => $bitfield,
		'enable_indexing' => true, 'notify' => false, 'notify_set' => false,
		'post_edit_locked' => false, 'topic_time_limit' => 0,
		'topic_status' => ITEM_UNLOCKED, 'forum_name' => '', 'force_approved_state' => ITEM_APPROVED,
	];
	$poll = [];
	submit_post('post', $data['topic_title'], '', POST_NORMAL, $poll, $data);
	$result = $db->sql_query('SELECT n.notification_id FROM ' . NOTIFICATIONS_TABLE . ' n JOIN ' . NOTIFICATION_TYPES_TABLE
		. " t ON n.notification_type_id = t.notification_type_id WHERE t.notification_type_name = 'notification.type.mention'"
		. ' AND n.user_id = ' . $recipient . ' AND n.item_id = ' . (int) $data['post_id']);
	$notification = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$notification)
	{
		throw new RuntimeException('Normal submit_post did not produce a mention notification.');
	}
	echo 'Normal submit_post created native mention notification for post ' . $data['post_id'] . PHP_EOL;
}
else
{
	throw new RuntimeException('Expected seed or check.');
}
