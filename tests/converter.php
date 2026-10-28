<?php
/** Focused conversion regressions; run with php tests/converter.php. */
require __DIR__ . '/../src/Converter.php';
use SimpleMentionsConverter\Converter;

$checks = 0;
function check(bool $condition, string $message): void
{
	global $checks;
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	++$checks;
}
$converter = new Converter(static fn($kind, $value) => [
	'name' => ['Alice & Bob' => 7, 'Alice' => 7],
	'user' => ['7' => 7],
	'group' => ['1' => 1],
][$kind][$value] ?? null);

foreach ([
	['<r><MENTION><s>[mention]</s>Alice<e>[/mention]</e></MENTION></r>', 'user_id="7"', '[mention=u:7]'],
	['<r><MENTION><s>[mention]</s>Alice &amp; Bob<e>[/mention]</e></MENTION></r>', 'user_id="7"', 'Alice &amp; Bob'],
	['<r><SMENTION u="7"><s>[smention u=7]</s>Old username<e>[/smention]</e></SMENTION></r>', 'user_id="7"', 'Old username'],
	['<r><SMENTION g="1"><s>[smention g=1]</s>Group<e>[/smention]</e></SMENTION></r>', 'group_id="1"', '[mention=g:1]'],
	['<r><SMENTION g="" u="7"><s>[smention u="7"]</s>Alice<e>[/smention]</e></SMENTION></r>', 'user_id="7"', '[mention=u:7]'],
	['<r><MENTION><s>[mention=u:7]</s>Earlier name<e>[/mention]</e></MENTION></r>', 'user_id="7"', 'Earlier name'],
	['<r><MENTION><s>[mention=g:1]</s>Group<e>[/mention]</e></MENTION></r>', 'group_id="1"', '[mention=g:1]'],
] as [$input, $attribute, $label])
{
	[$output, $counts] = $converter->convert($input);
	check(str_contains($output, $attribute) && str_contains($output, $label), 'Correct native target and label');
	check($counts === ['converted' => 1, 'unresolved' => 0], 'Conversion counted');
	check($converter->convert($output)[0] === $output, 'Rerun is byte-idempotent');
}
foreach ([
	'<r><MENTION><s>[mention]</s>Deleted user<e>[/mention]</e></MENTION></r>',
	'<r><SMENTION u="99"><s>[smention u=99]</s>Deleted user<e>[/smention]</e></SMENTION></r>',
	'<r><SMENTION g="99"><s>[smention g=99]</s>Deleted user<e>[/smention]</e></SMENTION></r>',
] as $input)
{
	[$output, $counts] = $converter->convert($input);
	check($output === '<r>@Deleted user</r>' && $counts['unresolved'] === 1, 'Unknown targets preserved as plain text');
}
foreach ([
	'<r><MENTION user_id="7"><s>[mention=u:7]</s>Alice<e>[/mention]</e></MENTION></r>',
	'<r><CODE><s>[code]</s>[mention]Alice[/mention]<e>[/code]</e></CODE></r>',
	'<t>[smention u=7]Literal[/smention]</t>',
	'<r><B><s>[b]</s>Unicode café 😃<e>[/b]</e></B></r>',
] as $input)
{
	check($converter->convert($input)[0] === $input, 'Unrelated/native content unchanged');
}
$mixed = '<r><QUOTE author="A"><s>[quote=A]</s><B><s>[b]</s>bold<e>[/b]</e></B> <MENTION><s>[mention]</s>Alice<e>[/mention]</e></MENTION><e>[/quote]</e></QUOTE></r>';
[$output] = $converter->convert($mixed);
check(str_contains($output, '<B><s>[b]</s>bold<e>[/b]</e></B>') && str_contains($output, 'author="A"'), 'Other formatting and quote metadata preserved');

foreach ([
	'<r><MENTION>',
	'<!DOCTYPE r [<!ENTITY test SYSTEM "file:///etc/passwd">]><r><MENTION>&test;</MENTION></r>',
	'<r><MENTION other="x"><s>[mention]</s>Alice<e>[/mention]</e></MENTION></r>',
	'<r><SMENTION u="7" g="1"><s>[smention u=7]</s>Alice<e>[/smention]</e></SMENTION></r>',
	'<r><SMENTION u="7"><s>[smention u=9]</s>Alice<e>[/smention]</e></SMENTION></r>',
	'<r><SMENTION u="-7"><s>[smention u=-7]</s>Alice<e>[/smention]</e></SMENTION></r>',
	'<r><MENTION><s>[mention]</s><B>Alice</B><e>[/mention]</e></MENTION></r>',
	'<r><CODE><MENTION><s>[mention]</s>Alice<e>[/mention]</e></MENTION></CODE></r>',
	'[mention:abcdef12]Alice[/mention:abcdef12]',
] as $input)
{
	$failed = false;
	try
	{
		$converter->convert($input);
	}
	catch (RuntimeException $error)
	{
		$failed = true;
	}
	check($failed, 'Unsafe/unknown input rejected');
}
check(Converter::isLegacyDefinition(['bbcode_tag' => 'mention', 'bbcode_match' => '[mention]{TEXT}[/mention]', 'bbcode_tpl' => '<span class="mention">@{TEXT}</span>']), 'Known definition identified');
check(!Converter::isLegacyDefinition(['bbcode_tag' => 'mention', 'bbcode_match' => '[mention]{TEXT}[/mention]', 'bbcode_tpl' => '<strong>{TEXT}</strong>']), 'Customized definition protected');
echo "$checks checks passed.\n";
