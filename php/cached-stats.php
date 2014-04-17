<?php
/**
* type, form, to, step, distro, notainted, class, stripname, onlykernel
* tp(sum, stddev, reljmpup, absjmpup, raw, last), lst(a,b,c,d ...)
*
* type:      kernel, file, driver, module, function, oops, distro
* stamp:     from(date), to(date), step(xd,xm,xy)
* variant:   guilty: top(sum), top(last), top(stddev), top(absjmpup), top(reljmpup), top(raw)
* variant:   lst manual select of guilties. For example lst=3.6,3.7,3.8,3.9
* limit:     limit for data
* stripname: Useable for Grouping items fe: Fedora xx as Fedora where set param to 1
* distro:    specific distro stats
* class:     class used for output table (style)
* format:    @c - count
*            @n - name
*            @s - stamp
*            @l - link
*            @p - progressbar
* onlykernel: for example 3.6. Show stats only for exact kernel
* notainted: bool, if set stats exclude all tainted kernels
*/

require "wp-load.php";

// fix filename
$fileName = urlencode(print_r($_GET, True));
$search = array('Array', '%3D', '%5B', '+');
$replace = array('', '=', ',', '');
$fileName = str_replace($search, $replace, $fileName);
$fileName = preg_replace('/%[0-9A-Fa-f]{2}/', '', $fileName);
$fileName = substr($fileName, 1);

// filter for type param
if (!($_GET["type"] == "driver" ||
	$_GET["type"] == "module" ||
		$_GET["type"] == "file" ||
			$_GET["type"] == "function" ||
				$_GET["type"] == "kernel" ||
					$_GET["type"] == "oops" ||
						$_GET["type"] == "distro") ||
							(!isset($_GET["type"]) ||
								(!isset($_GET["tp"]) && !isset($_GET["lst"])))
) {
	echo "<h2>Empty output: Check the requested type.</h2>";
	exit();
}

// use cache
if (file_exists($oopscfg["cachedir"] . $fileName) && time() - $oopscfg["cache"]["ttl"] < filemtime($oopscfg["cachedir"] . $fileName)) {
	readfile($oopscfg["cachedir"] . $fileName);
	exit();
}
// remove old cache file
if (file_exists($oopscfg["cachedir"] . $fileName))
	unlink($oopscfg["cachedir"] . $fileName);

$type = $_GET["type"];
if ($type == "kernel")
	$item_name = "version";
else if ($type == "oops")
	$item_name = "bugline";
else if ($type == "distro")
	$item_name = "distro";
else
	$item_name = "name";

// time interval
if (isset($_GET["from"])) {
	try {
		$frm = new DateTime($_GET["from"]);
		$to = new DateTime(isset($_GET["to"]) ? $_GET["to"] : '');
	}
	catch (Exception $e) {
		echo "Empty output: Wrong time format. Use YYYY-MM or YYYY-MM-DD";
		exit();
	}
	if (isset($_GET["step"])) {
		try {
			$interval = new DateInterval('P' . $_GET["step"]);
		}
		catch (Exception $e) {
			echo "Empty output: Wrong step format. Use xy. 'x' is number and 'y' is D or M or Y";
			exit();
		}
	}
	else {
		$tpm = explode("-", $_GET["from"]);
		if (sizeof($tpm) == 3)
			$interval = new DateInterval('P1D');
		else
			$interval = new DateInterval('P1M');
	}
}
else {
	// defaults
	$to = new DateTime();
	$frm = new DateTime();
	try {
		$interval = new DateInterval('P' . $_GET["step"]);
	}
	catch (Exception $e) {
		$interval = new DateInterval('P1M');
	}
	$frm = $frm->sub($interval);
}

// time interval
$range = Array();
$current = new DateTime($frm->format("Ymd"));
if ($interval->format("%d") > 0) $timeformat = "Ymd";
else if ($interval->format("%m") > 0) $timeformat = "Ym";
else if ($interval->format("%y") > 0) $timeformat = "Y";
while ($to >= $current) {
	$range[$current->format($timeformat) ] = Array();
	$current = $current->add($interval);
}
arsort($range);
// limits
if (isset($_GET["limit"]) && is_numeric($_GET["limit"])) $limit = $_GET["limit"];
else $limit = 5;
if (isset($_GET["tp"]) && isset($_GET["lst"])) {
	echo "Use tp OR lst, NO tp AND lst";
	exit();
}
// stat type
if (isset($_GET["tp"])) {
	if ($_GET["tp"] == "sum" ||
		$_GET["tp"] == "stddev" ||
			$_GET["tp"] == "reljmpup" ||
				$_GET["tp"] == "absjmpup" ||
					$_GET["tp"] == "raw" ||
						$_GET["tp"] == "last" ||
							$_GET["tp"] == "top"
								) $tp = $_GET["tp"];
	else {
		echo "TP support only: sum, stddev, reljmpup, absjmpup, raw, last, top";
		exit();
	}
} else $tp = "sum";
// use list of items
if (isset($_GET["lst"])) {
	$lst = explode(",", $_GET["lst"]);
	if (sizeof($lst) <= 0) {
		echo "Bad lst param. Use strings separated by comma";
		exit();
	}
}
// output format
if (isset($_GET["format"])) {
	$temp = explode("@", $_GET["format"]);
	$format = Array();
	$format[0] = "";
	$index = 0;
	foreach ($temp as $key => $value) {
		// security fix, no <script>
		$value = str_replace("<", "", $value);
		$value = str_replace(">", "", $value);
		if ($value == "") continue;
		$format[0] = $format[0] . "<th>" . substr($value, 1) . "</th>";
		$curr = substr($value, 0, 1);
		if ($curr == "c") $format[++$index] = "count";
		else if ($curr == "n") $format[++$index] = $item_name;
		else if ($curr == "s") $format[++$index] = "stamp";
		else if ($curr == "l") $format[++$index] = "link";
		else if ($curr == "p") $format[++$index] = "progressbar";
		else {
			echo "Error: format string accept only @cnslp - " . $curr;
			exit();
		}
	}
} else {
	$format[0] = "<th>" . ucfirst($type) . "</th><th>Count</th>";
	$format[1] = $item_name;
	$format[2] = "count";
}
ob_start();
// build query
$where = "";
if (isset($_GET["lst"])) {
	$data = explode(",", $_GET["lst"]);
	if (sizeof($data) <= 0) {
		echo "Bad list of items!";
		exit();
	}
	foreach ($data as $key => $value) {
		$where .= $item_name . " like '%" . mysql_real_escape_string($value) . "%' OR ";
	}
	if (strlen($where) > 0) {
		$where = " AND (" . substr($where, 0, strlen($where) - 3) . ") ";
	}
	$tp = "sum";
}
$type = $_GET["type"];
if (isset($_GET["distro"]) && $_GET["distro"] != "") {
	$query = "SELECT DISTINCT meta_value AS distro FROM wp_postmeta WHERE meta_key='";
	$query .= $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"] . "' AND meta_value LIKE '%" . mysql_real_escape_string($_GET["distro"]) . "%'";
	if ($result = $wpdb->get_results($query)) {
		$distro_list = array();
		$distro_where = "";
		foreach ($result as $key => $row) {
			$distro_where.= "distro='" . $row->distro . "' OR ";
			$distro_list[] = $row->distro;
		}
		if ($distro_where != "") $distro_where = " AND (" . substr($distro_where, 0, -4) . ") ";
	} else die("Unknown Distro");
}
// strip name param
if (isset($_GET["stripname"]) && ((int)$_GET["stripname"]) > 0) {
	$sn = (int)$_GET["stripname"];
	if ($type == "kernel") $divider = ".";
	else $divider = " ";
} else $sn = 0;

if (isset($_GET["onlykernel"]) && $_GET["onlykernel"] != "") {
	$query = "SELECT id,version FROM kernel WHERE ";
	$temp = explode(",", $_GET["onlykernel"]);
	$kernel_where = "";
	$kernel_list = array();
	foreach ($temp as $kkey => $kval) $kernel_where .= "version LIKE '" . $kval . "%' OR ";
	if ($kernel_where != "") {
		$query .= substr($kernel_where, 0, -4);
		if ($result = $wpdb->get_results($query)) {
			$kernel_where = "";
			foreach ($result as $key => $row) {
				$kernel_where .= "kernelID='" . $row->id . "' OR ";
				$kernel_list[] = $row->version;
			}
			if ($kernel_where != "") $kernel_where = " AND (" . substr($kernel_where, 0, -4) . ") ";
			else $kernel_where = " AND false ";
			unset($result);
		} else die("Unknown Kernel version :(");
	}
} else $kernel_where = "";

if ($type == "oops") {
	$filter[] = Array(
	"key" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"],
	"value" => $frm->format("Y-m-d"),
	"compare" => ">=",
	"type" => "string"
);
$filter[] = Array(
"key" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"],
"value" => $to->format("Y-m-d"),
"compare" => "<=",
"type" => "string"
);
if (is_array($distro_list)) {
$temp = Array();
foreach ($distro_list as $distro) {
	$temp[] = Array(
	"key" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"],
	"value" => $distro,
	"compare" => "like",
	"type" => "string"
);
}
$filter[] = $temp;
}
if (is_array($kernel_list)) {
$temp = Array();
foreach ($kernel_list as $kernel) {
$temp[] = Array(
"key" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel"],
"value" => $kernel,
"compare" => "like",
"type" => "string"
);
}
$filter[] = $temp;
}
$itemlist = getFilteredList($filter);
$sql = "SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE ";
$sql .= "(meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["bugline"] . "' OR ";
$sql .= "meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"] . "' OR ";
$sql .= "meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["total-count"] . "') AND ";
$sql .= "post_id IN (" . implode(",",$itemlist) . ")";
$subresult = $wpdb->get_results($sql);
if ($subresult) {
$result = Array();
foreach ($subresult as $val) {
if($val->meta_key == $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["bugline"])
$result[$val->post_id]->bugline = $val->meta_value;
if($val->meta_key == $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"])
$result[$val->post_id]->stamp = $val->meta_value;
if($val->meta_key == $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["total-count"])
$result[$val->post_id]->count = $val->meta_value;
}
}
} elseif ($type == "distro") {
$query = "SELECT gd.distro,gd.stamp,gd.count,gd.tcount FROM guilty_kernel AS gd WHERE";
$query.= " gd.stamp >= '" . $frm->format("Y-m-d") . "' AND gd.stamp <= '" . $to->format("Y-m-d") . "' " . $distro_where . $kernel_where . $where;
$result = $wpdb->get_results($query);
} else {
$query = "SELECT d." . ($type == "kernel" ? "version" : "name") . ",gd.stamp,gd.count,gd.tcount FROM guilty_" . $type . " AS gd, " . $type . " AS d WHERE";
$query.= " gd.stamp >= '" . $frm->format("Y-m-d") . "' AND gd.stamp <= '" . $to->format("Y-m-d") . "' AND gd." . $type . "ID=d.id " . $distro_where . $kernel_where . $where;
$result = $wpdb->get_results($query);
}
// show tainted?
$notaint = (isset($_GET["notainted"]) && $_GET["notainted"] != "");

if ($result) {
if ($timeformat == "Ymd") $sub_interval = new DateInterval('P1D');
else if ($timeformat == "Ym") $sub_interval = new DateInterval('P1M');
else if ($timeformat == "Y") $sub_interval = new DateInterval('P1Y');
foreach ($result as $key => $row) {
if ($sn) {
	$temp = explode($divider, $row->$item_name);
	$row->$item_name = $temp[0];
	for ($x = 1;$x < $sn;$x++) {
		if (isset($temp[$x])) $row->$item_name.= $divider . $temp[$x];
	}
}
$current = new DateTime($row->stamp);
if (!isset($range[$current->format($timeformat) ])) {
	while (!isset($range[$current->format($timeformat) ])) $current->sub($sub_interval);
}
$row->stamp = $current->format($timeformat);
if (isset($out[$row->$item_name][$row->stamp]))
	$out[$row->$item_name][$row->stamp]+= $row->count;
else $out[$row->$item_name][$row->stamp] = $row->count;
if ($notaint)
	$out[$row->$item_name][$row->stamp] -= $row->tcount;
}
if ($tp == "sum") foreach ($out AS $key => $value) $order[$key] = stat_sum($value);
if ($tp == "top") foreach ($out AS $key => $value) $order[$key] = stat_top($value);
if ($tp == "last") foreach ($out AS $key => $value) $order[$key] = stat_last($value);
if ($tp == "stddev") foreach ($out AS $key => $value) $order[$key] = stat_standard_deviation($value);
if ($tp == "reljmpup") foreach ($out AS $key => $value) $order[$key] = stat_relative_jmp_up($value);
if ($tp == "absjmpup") foreach ($out AS $key => $value) $order[$key] = stat_absolute_jmp_up($value);
arsort($order);
$limit--;
if (isset($_GET["class"])) $class = htmlspecialchars($_GET["class"]);
else $class = "gd";
echo '<table class="' . $class . '"><tr>' . $format[0] . '</tr>';
$format = array_slice($format, 1);
$total = array_sum(array_slice($order, 0, $limit + 1));
foreach ($order as $key => $value) {
if (!$value) continue;
echo '<tr>';
foreach ($format as $fkey => $fvalue) {
	if ($fvalue == "count") echo '<td class="value">' . $value . '</td>';
	if ($fvalue == $item_name) echo '<td>' . $key . '</td>';
	if ($fvalue == "stamp") echo '<td>' . $frm->format("Y-m-d") . '</td>';
	if ($fvalue == "link") {
		$qry = "";
		if (isset($_GET["onlykernel"])) $qry .= "oopskernel=" . $_GET["onlykernel"] . "&";
		if ($_GET["type"] == "distro")
			$qry .= "oopsdistro=" . $key . "&";
		else $qry .= $type . "=" . $key . "&";
		echo '<td>' . '<a href="' . $oopscfg["reportsurl"] . '?' . $qry . 'search=submit">' . substr($key, 0, 32) . '</td>';
	}
	if ($fvalue == "progressbar") echo '<td><div class="percentage-box"><div class="percentage-value" style="width: ' . round(($value * 140) / $total) . 'px;"><div style="padding-left: ' . (round(($value * 140) / $total) + 4) . 'px;">' . $value . '</div></div></div></td>';
}
echo '</tr>';
$limit--;
if ($limit < 0) break;
}
// Fill the tables with empty rows, to make it beauty
while ($limit >= 0) {
echo '<tr><td>&nbsp;</td><td>&nbsp;</td></tr>';
$limit--;
}
echo '</table>';
} else echo "Anny result for this time :(";
$fp = fopen($oopscfg["cachedir"] . $fileName, 'w');
$output = ob_get_contents();
fwrite($fp, $output);
fclose($fp);
?>
