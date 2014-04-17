<?php
/**
* KernelOops parser config
* @file   oops-config.php
* @author Petr Oros
*/
// parser paths config
$oopscfg["webroot"] = getenv('OPENSHIFT_REPO_DIR') . "php/";
$oopscfg["parser"] = $oopscfg["webroot"] . "parser/";
$oopscfg["tagdir"] = getenv('OPENSHIFT_DATA_DIR') . "tags/";
$oopscfg["cachedir"] = $oopscfg["webroot"] . "cache/";
$oopscfg["codedecode"] = getenv('OPENSHIFT_DATA_DIR') . "linux-stable/scripts/decodecode";
$oopscfg["binutils"] = getenv('OPENSHIFT_DATA_DIR') . "binutils/";

$oopscfg["safeout"]["dir"] = $oopscfg["webroot"] . "safe-output/";
$oopscfg["safeout"]["export"] = "export.xml";
$oopscfg["safeout"]["rawexport"] = "raw-export.xml";

$oopscfg["reportsurl"] = get_post_type_archive_link("oops");

$oopscfg["pchartlib"]["root"] = $oopscfg["parser"] . "pChartLib/";
$oopscfg["pchartlib"]["fonts"] = $oopscfg["pchartlib"]["root"] . "fonts/";
$oopscfg["pchartlib"]["class"] = $oopscfg["pchartlib"]["root"] . "class/";
// parser params config
$oopscfg["limit"] = 1000;
// wordpress custom fields config
$oopscfg["wpcf"]["dbprefix"] = "wpcf-";
// slug list
$oopscfg["wpcf"]["slug"] = Array("type" => "type",
"class" => "class",
"kernel" => "kernel",
"tainted" => "tainted",
"architecture" => "architecture",
"distro" => "distro",
"hardware" => "hardware",
"last-sys-file" => "last-sys-file",
"caused-by" => "caused-by",
"guilty-link" => "guilty-link",
"ip" => "ip",
"registers" => "registers",
"stack" => "stack",
"disassm" => "disassm",
"trace" => "trace",
"modules-participated" => "modules-participated",
"linked-modules" => "linked-modules",
"last-seen" => "last-seen",
"total-count" => "total-count",
"raw-list" => "raw-list",
"hash" => "hash",
"bugline" => "bugline",
"kernel-sort" => "kernel-sort"
);
// slug names
$oopscfg["wpcf"]["slug-show"] = Array("type" => "Type",
"class" => "Class",
"kernel" => "Kernel version",
"tainted" => "Tainted info",
"architecture" => "Architecture",
"distro" => "Distribution",
"hardware" => "Hardware",
"last-sys-file" => "Last used system file",
"caused-by" => "Guilty info",
"guilty-link" => "Guilty link",
"ip" => "Ip",
"registers" => "Registers",
"stack" => "Stack",
"disassm" => "Dissassembled code",
"trace" => "Trace",
"modules-participated" => "Participated modules",
"linked-modules" => "Linked modules",
"last-seen" => "Last seen similar oops",
"total-count" => "Total count",
//"raw-list" => "raw-list",
//"hash" => "hash",
//"bugline" => "bugline",
//"kernel-sort" => "kernel-sort"
);
// cache config
$oopscfg["cache"]["ttl"] = 60 * 60 * 12;
require ($oopscfg["parser"] . "parser-functions.php");
?>
