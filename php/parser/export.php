<?php
require "../wp-load.php";
ini_set('memory_limit', '1024M');
global $wpdb;
$args = array('post_type' => 'oops',
              'order' => 'ASC',
              'orderby' => 'ID',
              'posts_per_page' => 1024);
$slug = Array("bugline" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["bugline"],
              "type" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["type"],
              "class" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["class"],
              "kernel" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel"],
              "tainted" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["tainted"],
              "distro" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"],
              "hardware" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["hardware"],
              "lastsysfs" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-sys-file"],
              "guilty" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["caused-by"],
              "ip" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["ip"],
              "trace" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["trace"],
              "linked_modules" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["linked-modules"],
              "participated_module" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["modules-participated"],
              "registers" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["registers"],
              "stack" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["stack"],
              "dissasm" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["disassm"],
              "total_raw" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["total-count"],
              "last_seen" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"]
             );
$handle = fopen($oopscfg["safeout"]["dir"] . $oopscfg["safeout"]["export"] , "w");
fwrite($handle, '<?xml version="1.0"?>' . "\n");
fwrite($handle, '<oopslist>' . "\n");
$out = "";
$args["paged"] = 1;
while($args["paged"]) {
	query_posts($args);
	if(!have_posts()) break;
	$args["paged"]++;
	while (have_posts()) {
		the_post();
		$out .= '<oops id="' . $post->ID . '">' . "\n";
		foreach ($slug as $key => $value) {
			$temp = get_post_custom_values($value);
			if ($key != "trace" && $key != "guilty") {
				if (isset($temp[0]) && $temp[0] != "") {
					$fix = str_replace("<li>", "", $temp[0]);
					$fix = str_replace("</li>", ";", $fix);
					$fix = str_replace("\t", " ", $fix);
					$fix = preg_replace("/[[:blank:]]+/", " ", $fix);
					$out .= ' <' . $key . '>' . htmlspecialchars($fix) . '</' . $key . '>' . "\n";
					unset($fix);
				}
			} elseif ($key == "trace" && isset($temp[0])) {
				$out .= " <traces>\n";
				foreach ($temp as $k => $val) {
					$fix = str_replace("<li>", "", $val);
					$fix = str_replace("</li>", "", $fix);
					$fix = str_replace("\t", " ", $fix);
					$fix = preg_replace("/[[:blank:]]+/", " ", $fix);
					$out .= "  <trace id=\"" . $k . "\">" . $fix . "</trace>\n";
					unset($fix);
				}
				$out .= " </traces>\n";
			} elseif ($key == "guilty" && isset($temp[0])) {
				$out .= " <guilty>\n";
				$curr = preg_replace("/.*<li>File: ([^<]+)<\/li>.*/", "$1", $temp[0]);
				if ($curr != $temp[0]) $out .= '  <file>' . $curr . '</file>' . "\n";
				$curr = preg_replace("/.*<li>Function: ([^<]+)<\/li>.*/", "$1", $temp[0]);
				if ($curr != $temp[0]) $out .= '  <function>' . $curr . '</function>' . "\n";
				$curr = preg_replace("/.*<li>Driver: ([^<]+)<\/li>.*/", "$1", $temp[0]);
				if ($curr != $temp[0]) $out .= '  <driver>' . $curr . '</driver>' . "\n";
				$curr = preg_replace("/.*<li>Module: ([^<]+)<\/li>.*/", "$1", $temp[0]);
				if ($curr != $temp[0]) $out .= '  <module>' . $curr . '</module>' . "\n";
				$out .= " </guilty>\n";
				unset($curr);
			}
			unset($temp);
		}
		unset($post);
		$out .= '</oops>' . "\n";
    }
    if ($out != "") {
		fwrite($handle, $out);
		unset($out);
		$out = "";
	}
	wp_cache_flush();
}
if ($out != "") fwrite($handle, $out);
fwrite($handle, '</oopslist>');
fclose($handle);
?>
