<?php
/*********************************************************
*	params: type, form, to, step, distro, notainted, tp(sum, stddev, reljmpup, absjmpup, raw, last), lst(a,b,c,d....)
*	type: kernel, file, driver, module, function, distro
*	stamp: from(date), to(date), step(xd,xm,xy)
*	stripname: Useable for Grouping items fe: Fedora xx as Fedora where set param to 1
*	variant: guilty: top(sum), top(last), top(stddev), top(absjmpup), top(reljmpup), top(raw)
*	variant: lst manual select of guilties. For example lst=3.6,3.7,3.8,3.9
*	limit: limit for data
*	distro: distro filter, support *
*	format: pie, line, bar(all other)
*	width: default: 800
*	height: default: 400
*  notainted: bool, if set stats exclude all tainted kernels
**********************************/

require "wp-load.php";

$fileName = urlencode(print_r($_GET, True));
$search = array('Array', '%3D', '%5B', '+');
$replace = array('', '=', ',', '');
$fileName = str_replace($search, $replace, $fileName);
$fileName = preg_replace('/%[0-9A-Fa-f]{2}/', '', $fileName);
$fileName = substr($fileName, 1) . ".png";

if (!($_GET["type"] == "driver" ||
	$_GET["type"] == "module" ||
	$_GET["type"] == "file" ||
	$_GET["type"] == "function" ||
	$_GET["type"] == "kernel" ||
	$_GET["type"] == "distro" ||
	$_GET["type"] == "oops")
) {
	echo "<h2>No Data. Sorry :(</h2>";
	exit();
} else $type = $_GET["type"];
// read cache
if (file_exists($oopscfg["cachedir"] . $fileName) && time() - $oopscfg["cache"]["ttl"] < filemtime($oopscfg["cachedir"] . $fileName)) {
	readfile($oopscfg["cachedir"] . $fileName);
	exit();
}
if (file_exists($oopscfg["cachedir"] . $fileName)) unlink($oopscfg["cachedir"] . $fileName);
if ($type == "kernel" || $type == "oops") $item_name = "version";
else $item_name = "name";
if (isset($_GET["from"])) {
	try {
		$frm = new DateTime($_GET["from"]);
		$to = new DateTime(isset($_GET["to"]) ? $_GET["to"] : '');
	}
	catch(Exception $e) {
		echo "Bad time format. Use YYYY-MM or YYYY-MM-DD";
		exit();
	}
	if (isset($_GET["step"])) {
		try {
			$interval = new DateInterval('P' . $_GET["step"]);
		}
		catch(Exception $e) {
			echo "Bad step format. Use xy. 'x' is number and 'y' is D or M or Y";
			exit();
		}
	} else {
		$tpm = explode("-", $_GET["from"]);
		if (sizeof($tpm) == 3) $interval = new DateInterval('P1D');
		else $interval = new DateInterval('P1M');
	}
} else {
	// defaults
	$to = new DateTime();
	$frm = new DateTime();
	try {
		$interval = new DateInterval('P' . $_GET["step"]);
	}
	catch(Exception $e) {
		$interval = new DateInterval('P1M');
	}
	if (isset($_GET["period"])) {
		try {
			$period = new DateInterval('P' . $_GET["period"]);
		}
		catch(Exception $e) {
			echo "Bad period format. Use xy. 'x' is number and 'y' is D or M or Y";
			exit();
		}
		$frm = $frm->sub($period);
	} else $frm = $frm->sub($interval);
}
$range = Array();
$current = new DateTime($frm->format("Ymd"));
if ($interval->format("%d") > 0) $timeformat = "Ymd";
else if ($interval->format("%m") > 0) $timeformat = "Ym";
else if ($interval->format("%y") > 0) $timeformat = "Y";
while ($to >= $current) {
	$range[$current->format($timeformat) ] = Array();
	$current = $current->add($interval);
}
if (isset($_GET["limit"]) && is_numeric($_GET["limit"])) $limit = $_GET["limit"];
else $limit = 5;
if (isset($_GET["tp"]) && isset($_GET["lst"])) {
	echo "Use tp OR lst, NO tp AND lst";
	exit();
}
// show tainted?
$notaint = (isset($_GET["notainted"]) && $_GET["notainted"] != "");

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
if (isset($_GET["lst"])) {
	$lst = explode(",", $_GET["lst"]);
	if (sizeof($lst) <= 0) {
		echo "Bad lst param. Use strings separated by comma";
		exit();
	}
}
if (isset($_GET["width"]) && is_numeric($_GET["width"])) $width = (int)$_GET["width"];
else $width = 800;
if (isset($_GET["height"]) && is_numeric($_GET["height"])) $height = (int)$_GET["height"];
else $height = 400;
// build query
$where = "";
if (isset($_GET["lst"])) {
	$data = explode(",", $_GET["lst"]);
	if (sizeof($data) <= 0) {
		echo "Bad list of items!";
		exit();
	}
	foreach ($data as $key => $value) {
		$where.= $item_name . " like '%" . mysql_real_escape_string($value) . "%' OR ";
	}
	if (strlen($where) > 0) {
		$where = " AND (" . substr($where, 0, strlen($where) - 3) . ") ";
	}
	$tp = "sum";
}
// distro filter
if (isset($_GET["distro"]) && $_GET["distro"] != "") {
	$query = "SELECT DISTINCT meta_value AS distro FROM wp_postmeta WHERE meta_key='";
	$query .= $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"] . "' AND meta_value LIKE '%" . mysql_real_escape_string($_GET["distro"]) . "%'";
	if ($result = $wpdb->get_results($query)) {
		$distro_where = "";
		foreach ($result as $key => $row)
			$distro_where.= "distro='" . $row->distro . "' OR ";
		if ($distro_where != "") $distro_where = " AND (" . substr($distro_where, 0, -4) . ") ";
	} else die("Unknown Distro");
} else $distro_where = "";
if (isset($_GET["stripname"]) && ((int)$_GET["stripname"]) > 0) {
	$sn = (int)$_GET["stripname"];
	if ($type == "kernel") $divider = ".";
	else $divider = " ";
} else $sn = 0;
if ($type == "distro") {
	$query = "SELECT gd.distro as name,gd.stamp,gd.count,gd.tcount FROM guilty_kernel AS gd WHERE";
	$query.= " gd.stamp >= '" . $frm->format("Y-m-d") . "' AND gd.stamp <= '" . $to->format("Y-m-d") . "' " . $distro_where . $where;
} elseif ($type == "oops") {
	$query = "SELECT d.version,gd.stamp,gd.count,gd.tcount FROM guilty_kernel AS gd, kernel AS d WHERE";
	$query.= " gd.stamp >= '" . $frm->format("Y-m-d") . "' AND gd.stamp <= '" . $to->format("Y-m-d") . "' AND gd.kernelID=d.id " . $distro_where . $where;
} else {
	$query = "SELECT d." . ($type == "kernel" ? "version" : "name") . ",gd.stamp,gd.count FROM guilty_" . $type . " AS gd, " . $type . " AS d WHERE";
	$query.= " gd.stamp >= '" . $frm->format("Y-m-d") . "' AND gd.stamp <= '" . $to->format("Y-m-d") . "' AND gd." . $type . "ID=d.id " . $distro_where . $where;
}
$result = $wpdb->get_results($query);
if ($result) {
	if ($timeformat == "Ymd") $sub_interval = new DateInterval('P1D');
	else if ($timeformat == "Ym") $sub_interval = new DateInterval('P1M');
	else if ($timeformat == "Y") $sub_interval = new DateInterval('P1Y');
	foreach ($result as $key => $row) {
		if ($type != "oops") {
			if ($sn) {
				$temp = explode($divider, $row->$item_name);
				$row->$item_name = $temp[0];
				for ($x = 1;$x < $sn;$x++) {
					if (isset($temp[$x])) $row->$item_name.= $divider . $temp[$x];
				}
			}
		} else $row->$item_name = "Oopses";
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
	foreach ($out as $f1 => $v1) {
		foreach ($range as $f2 => $v2) {
			if (!isset($out[$f1][$f2])) $out[$f1][$f2] = 0;
		}
		ksort($out[$f1]);
	}
	if ($tp == "sum") foreach ($out AS $key => $value) $order[$key] = stat_sum($value);
	if ($tp == "top") foreach ($out AS $key => $value) $order[$key] = stat_top($value);
	if ($tp == "last") foreach ($out AS $key => $value) $order[$key] = stat_last($value);
	if ($tp == "stddev") foreach ($out AS $key => $value) $order[$key] = stat_standard_deviation($value);
	if ($tp == "reljmpup") foreach ($out AS $key => $value) $order[$key] = stat_relative_jmp_up($value);
	if ($tp == "absjmpup") foreach ($out AS $key => $value) $order[$key] = stat_absolute_jmp_up($value);
	arsort($order);
	$order = array_slice($order, 0, $limit, True);
	include ($oopscfg["pchartlib"]["class"] . "pData.class.php");
	include ($oopscfg["pchartlib"]["class"] . "pDraw.class.php");
	include ($oopscfg["pchartlib"]["class"] . "pPie.class.php");
	include ($oopscfg["pchartlib"]["class"] . "pImage.class.php");
	if (isset($_GET["format"]) && $_GET["format"] == "pie") {
		$myData = new pData();
		unset($data, $labels);
		$data = array();
		$labels = array();
		foreach ($order as $item => $values) {
			$sum = 0;
			foreach ($out[$item] as $k => $v) $sum+= $v;
			$data[] = $sum;
			$labels[] = $item;
		}
		$myData->addPoints($data, "Score");
		$myData->setSerieDescription("Score", $type);
		$myData->addPoints($labels, "Labels");
		$myData->setAbscissa("Labels");
		$myPicture = new pImage(500, 300, $myData, TRUE);
		$myPicture->setFontProperties(array("FontName" => $oopscfg["pchartlib"]["fonts"] . "verdana.ttf", "FontSize" => 10, "R" => 80, "G" => 80, "B" => 80));
		$PieChart = new pPie($myPicture, $myData);
		$PieChart->draw3DPie(250, 150, array("WriteValues" => TRUE, "DrawLabels" => TRUE, "DataGapAngle" => 10, "DataGapRadius" => 6, "Border" => TRUE, "Radius" => 140));
		ob_start();
		$myPicture->autoOutput($oopscfg["cachedir"] . $fileName);
		$fp = fopen($oopscfg["cachedir"] . $fileName, 'w');
		$output = ob_get_contents();
		fwrite($fp, $output);
		fclose($fp);
		echo $output;
	} else {
		foreach ($range as $k3 => $v3) $axis[] = $k3;
		$myData = new pData();
		$cntr = 1;
		foreach ($order as $item => $values) {
			unset($dta);
			$dta = array();
			foreach ($out[$item] as $k => $v) $dta[] = $v;
			$myData->addPoints($dta, "Serie" . $cntr);
			$myData->setSerieDescription("Serie" . $cntr, $item);
			$myData->setSerieOnAxis("Serie" . $cntr, 0);
			$cntr++;
		}
		$myData->addPoints($axis, "Absissa");
		$myData->setAbscissa("Absissa");
		$myData->setAxisPosition(0, AXIS_POSITION_LEFT);
		//$myData->setAxisName(0, "count");
		$myData->setAxisUnit(0, "");
		$myPicture = new pImage($width, $height, $myData);
		$Settings = array("R" => 255, "G" => 255, "B" => 255);
		$myPicture->drawFilledRectangle(0, 0, $width, $height, $Settings);
		$myPicture->setGraphArea(50, 50, $width - 25, $height - 70);
		$myPicture->setFontProperties(array("R" => 0, "G" => 0, "B" => 0, "FontName" => $oopscfg["pchartlib"]["fonts"] . "verdana.ttf", "FontSize" => 8));
		$Settings = array("Pos" => SCALE_POS_LEFTRIGHT, "Mode" => SCALE_MODE_START0, "LabelingMethod" => LABELING_DIFFERENT, "GridR" => 255, "GridG" => 255, "GridB" => 255, "GridAlpha" => 50, "TickR" => 0, "TickG" => 0, "TickB" => 0, "TickAlpha" => 50, "LabelRotation" => 90, "CycleBackground" => 1, "DrawXLines" => 0, "DrawSubTicks" => 1, "SubTickR" => 255, "SubTickG" => 0, "SubTickB" => 0, "SubTickAlpha" => 50, "DrawYLines" => NONE, "RemoveYAxis" => TRUE);
		$myPicture->drawScale($Settings);
		$Config = array("DisplayValues" => 1, "BreakVoid" => 0, "BreakR" => 234, "BreakG" => 55, "BreakB" => 26);
		if ($_GET["format"] == "line") $myPicture->drawLineChart($Config);
		else $myPicture->drawBarChart($Config);
		$Config = array("FontR" => 0, "FontG" => 0, "FontB" => 0, "FontName" => $oopscfg["pchartlib"]["fonts"] . "verdana.ttf", "FontSize" => 8, "Margin" => 6, "Alpha" => 30, "BoxSize" => 5, "Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL);
		$myPicture->drawLegend($width - 80, 16, $Config);
		ob_start();
		$myPicture->autoOutput($oopscfg["cachedir"] . $fileName);
		$fp = fopen($oopscfg["cachedir"] . $fileName, 'w');
		$output = ob_get_contents();
		fwrite($fp, $output);
		fclose($fp);
		echo $output;
	}
} else echo "No data. Sorry :(";
?>
