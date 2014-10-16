<?php

require_once(dirname(__FILE__) . "/config.php");
require_once(WWW_DIR."/lib/groups.php");
require_once(WWW_DIR."/lib/binaries.php");

if (isset($argv[1]))
{
	$group = $argv[1];
	echo "Updating group {$group}\n";

	$g = new Groups;
	$group = $g->getByName($group);

	$bin = new Binaries;
	$bin->updateGroup($group);
}
else
{
	$binaries = new Binaries;
	$binaries->updateAllGroups();
}