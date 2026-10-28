<?php
/** Bootstrap installed phpBB CLI services without creating a user session. */
if (PHP_SAPI !== 'cli')
{
	exit(1);
}
define('IN_PHPBB', true);
$phpEx = 'php';
chdir($phpbb_root_path);
require $phpbb_root_path . 'includes/startup.php';
require $phpbb_root_path . 'phpbb/class_loader.php';
$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', $phpbb_root_path . 'phpbb/', $phpEx);
$phpbb_class_loader->register();
$phpbb_class_loader_ext = new \phpbb\class_loader('\\', $phpbb_root_path . 'ext/', $phpEx);
$phpbb_class_loader_ext->register();
$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path, $phpEx);
extract($phpbb_config_php_file->get_all());
if (!defined('PHPBB_ENVIRONMENT'))
{
	define('PHPBB_ENVIRONMENT', 'production');
}
foreach (['constants', 'functions', 'functions_admin', 'functions_content', 'utf/utf_tools', 'functions_compatibility'] as $include)
{
	require_once $phpbb_root_path . 'includes/' . $include . '.php';
}
$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpEx);
$phpbb_container = $phpbb_container_builder->with_config($phpbb_config_php_file)->get_container();
$phpbb_container->get('request')->enable_super_globals();
require $phpbb_root_path . 'includes/compatibility_globals.php';
register_compatibility_globals();
$language = $phpbb_container->get('language');
$language->set_default_language($config['default_lang']);
$language->add_lang(['common', 'acp/common', 'cli']);
$user->data['user_id'] = ANONYMOUS;
$user->ip = '127.0.0.1';
