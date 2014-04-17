<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;
/**
 * Archive Template
 *
 * @file           archive-oops.php
 * @theme          oops.kernel.org, child of Responsive
 * @author         Petr Oros, Anton Arapov
 * @version        Release: 0.1
 * @filesource     wp-content/themes/responsive-child/archive-oops.php
 * @description    kernel oops extension for oops list
 */
get_header(); ?>

<?php
	global $wpdb;

	$args = array('post_type' => 'oops');
	$slug = Array($oopscfg["wpcf"]["slug"]["bugline"],
					$oopscfg["wpcf"]["slug"]["kernel"],
					$oopscfg["wpcf"]["slug"]["total-count"],
					$oopscfg["wpcf"]["slug"]["last-seen"]);
	$slug_show = Array($oopscfg["wpcf"]["slug"]["kernel"],
					$oopscfg["wpcf"]["slug"]["total-count"],
					$oopscfg["wpcf"]["slug"]["last-seen"]);

	$order = get_query_var('order');
	if ($order != "ASC" && $order != "DESC")
		$order = "";
	else {
    	$orderby = get_query_var('orderby');
		
		if (in_array($orderby, $slug)) {
			if ($orderby == $oopscfg["wpcf"]["slug"]["kernel"] ||
				$orderby == $oopscfg["wpcf"]["slug"]["total-count"])
				$args['orderby'] = 'meta_value_num';
			else
				$args['orderby'] = 'meta_value';
		} else
			unset($order, $orderby);
	}

	foreach ($slug as $key) {
		$sort[$key] = "?orderby=" . $key . "&order=";
		if ($orderby == $key)
			$sort[$key] .= ($order == "ASC") ? "DESC" : "ASC";
		else
			$sort[$key] .= "DESC";
	}

	$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
	$args['paged'] = $paged;
	if (isset($order) && isset($orderby)) {
		$args['order'] = $order;
		$args['meta_key'] = $oopscfg["wpcf"]["dbprefix"] . 
			(($orderby == $oopscfg["wpcf"]["slug"]["kernel"]) ? $oopscfg["wpcf"]["slug"]["kernel-sort"] : $orderby);
	} else {
		// default setup for sorting, when neither ASC or DESC specified or
		// specified for the wrong/absent slug.
		$args['order'] = 'DESC';
		$args['orderby'] = 'meta_value_num';
		$args['meta_key'] = $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel-sort"];
	}

	// build and show filter form
	$query = "SELECT DISTINCT meta_key, meta_value FROM wp_postmeta WHERE ";
	$query .=  "meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["class"] . "' ";
	$query .=  " OR meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["type"] . "' ";
	$query .=  " OR meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"] . "'";

	$result = mysql_query($query);
	while ($row = mysql_fetch_array($result))
		$selects[$row['meta_key']][] = $row["meta_value"];

	if (isset($selects[$oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"]]))
		natsort($selects[$oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"]]);
	echo '<br /><a href="#" id="filterAreaControl" onclick="return showFilter()">Show Filter</a>';

	// filter {
	echo '<div id="filterArea" style="display: none;"><form name="filterform" method="GET">';
	echo '<table cellpadding="0" cellspacing="0" align="center" id="filterTable">';
	echo '<tr style="border: none;"><td width="20%">Select Class OR Type:';

	// class combo
	echo '<select name="oopsclass" id="oopsclass" class="ac_input" onchange="clrtype()">';
	if (!isset($_GET["oopsclass"]))
		echo '<option value="default" selected>--All Classes--</option>';
	else
		echo '<option value="default">--All Classes--</option>';

	foreach ($selects[$oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["class"]] as $key => $value) {
		if ($_GET["oopsclass"] == $value)
			echo '<option value="' . $value . '" selected>' . $value . '</option>';
		else
			echo '<option value="' . $value . '">' . $value . '</option>';
	}
	echo '</select><br />';

	// type combo
	echo '<select name="oopstype" id="oopstype" class="ac_input" onchange="clrclass()">';
	if (!isset($_GET["oopstype"]))
		echo '<option value="default" selected>--All Types--</option>';
	else
		echo '<option value="default">--All Types--</option>';

	foreach ($selects[$oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["type"]] as $key => $value) {
		if ($_GET["oopstype"] == $value)
			echo '<option value="' . $value . '" selected>' . $value . '</option>';
		else
			echo '<option value="' . $value . '">' . $value . '</option>';
	}
	echo '</select><br />';

	// distro combo
	echo '<select name="oopsdistro" id="oopsdistro" class="ac_input">';
	if (!isset($_GET["oopsdistro"]))
		echo '<option value="default" selected>--All Distributions--</option>';
	else
		echo '<option value="default">--All Distributions--</option>';

	foreach ($selects[$oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"]] as $key => $value) {
		if ($_GET["oopsdistro"] == $value)
			echo '<option value="' . $value . '" selected>' . $value . '</option>';
		else
			echo '<option value="' . $value . '">' . $value . '</option>';
	}
	echo '</select></td><td>';

	echo '<script type="text/javascript">jQuery(document).ready(function($) {$("#autocomplete-module").autocomplete("' . get_bloginfo('url') . '/get-data.php?c=1", {width: 260,matchContains: true,mustMatch: true,minChars: 2,selectFirst: false});});</script>';
	if (isset($_GET["module"]))
		echo 'Enter guilty module:<input type="text" name="module" id="autocomplete-module" value="' . $_GET["module"] . '"/>';
	else
		echo 'Enter guilty module:<input type="text" name="module" id="autocomplete-module"/>';
	
	echo '<br/>';
	echo '<script type="text/javascript">jQuery(document).ready(function($) {$("#autocomplete-driver").autocomplete("' . get_bloginfo('url') . '/get-data.php?c=2", {width: 260,matchContains: true,mustMatch: true,minChars: 2,selectFirst: false});});</script>';
	if (isset($_GET["driver"]))
		echo 'Enter guilty driver:<input type="text" name="driver" id="autocomplete-driver" value="' . $_GET["driver"] . '"/>';
	else
		echo 'Enter guilty driver:<input type="text" name="driver" id="autocomplete-driver"/>';

	echo '<br/>';
	if (isset($_GET["function"]))
		echo 'Enter guilty function:<input type="text" name="function" value="' . $_GET["function"] . '"/>';
	else
		echo 'Enter guilty function:<input type="text" name="function" />';

	echo '<br/>';
	if (isset($_GET["file"]))
		echo 'Enter guilty file:<input type="text" name="file" value="' . $_GET["file"] . '"/>';
	else
		echo 'Enter guilty file:<input type="text" name="file" />';

	echo '</td><td>Bugline contain:';
	if (isset($_GET["bugline"]))
		echo '<input type="text" name="bugline" value="' . $_GET["bugline"] . '" class="ac_input"/>';
	else
		echo '<input type="text" name="bugline" class="ac_input"/>';

	echo "Kernel Version:";
	if (isset($_GET["oopskernel"]))
		echo '<input type="text" name="oopskernel" value="' . $_GET["oopskernel"] . '" class="ac_input" id="oopskernel"/>';
	else
		echo '<input type="text" name="oopskernel" class="ac_input" id="oopskernel" />';
	echo '<div id="helptext">Using examples<ul><li>3.11</li><li>&gt;3.6</li><li>&lt;3.6</li><li>3.5&lt;&gt;3.10 (range)</li></ul></div>';

	echo '</td><td width="20%">Required in oops:<br />';
	if (isset($_GET["stack"]) && $_GET["stack"])
		echo '<input type="checkbox" name="stack" value="true" checked/> Stack<br />';
	else
		echo '<input type="checkbox" name="stack" value="true"/> Stack<br />';

	if (isset($_GET["registers"]) && $_GET["registers"])
		echo '<input type="checkbox" name="registers" value="true" checked/> Registers<br />';
	else
		echo '<input type="checkbox" name="registers" value="true"/> Registers<br />';

	if (isset($_GET["disassm"]) && $_GET["disassm"])
		echo '<input type="checkbox" name="disassm" value="true" checked/> Dissassembled code<br />';
	else
		echo '<input type="checkbox" name="disassm" value="true"/> Dissassembled code<br />';

	if (isset($_GET["tainted"]) && $_GET["tainted"])
		echo '<input type="checkbox" name="tainted" value="true" checked/> Untainted only</td>';
	else
		echo '<input type="checkbox" name="tainted" value="true"/> Untainted only?</td>';

	echo '</td></tr><tr><td></td><td></td><td></td><td><input type="submit" name="search" value="submit" style="float: right; margin-top: -60px; margin-right: 15px;"/></td></tr>';
	echo '</table></form></div>';
	// } filter

	// build query
	if (isset($_GET["search"]) && $_GET["search"] == "submit") {
		$args['meta_query']['relation'] = 'AND';
		if (isset($_GET["oopsclass"]) && $_GET["oopsclass"] != "default")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["class"], 'value' => $_GET["oopsclass"], 'compare' => 'LIKE');
		if (isset($_GET["oopstype"]) && $_GET["oopstype"] != "default")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["type"], 'value' => $_GET["oopstype"], 'compare' => 'LIKE');
		if (isset($_GET["oopsdistro"]) && $_GET["oopsdistro"] != "default")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["distro"], 'value' => $_GET["oopsdistro"], 'compare' => 'LIKE');
		if (isset($_GET["stack"]) && $_GET["stack"] == true)
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["stack"], 'compare' => 'EXISTS');
		if (isset($_GET["registers"]) && $_GET["registers"] == true)
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["registers"], 'compare' => 'EXISTS');
		if (isset($_GET["disassm"]) && $_GET["disassm"] == true)
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["disassm"], 'compare' => 'EXISTS');
		if (isset($_GET["tainted"]) && $_GET["tainted"] == true)
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["tainted"], 'compare' => 'NOT EXISTS');
		if (isset($_GET["bugline"]) && $_GET["bugline"] != "")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["bugline"], 'value' => $_GET["bugline"], 'compare' => 'LIKE');
		if (isset($_GET["oopskernel"]) && $_GET["oopskernel"] != "") {
			if ($_GET["oopskernel"][0] == ">") {
				$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel-sort"], 'value' => ksrt(substr($_GET["oopskernel"],1)), 'compare' => '>=');
			}
			else if ($_GET["oopskernel"][0] == "<") {
				$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel-sort"], 'value' => ksrt(substr($_GET["oopskernel"],1)), 'compare' => '<=');
			}
			else if (strpos($_GET["oopskernel"], "<>") !== False) {
				$range = explode("<>", $_GET["oopskernel"]);
				$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel-sort"], 'value' => array(ksrt($range[0]),ksrt($range[1])), 'compare' => 'BETWEEN');
			}
			else {
				$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel"], 'value' => $_GET["oopskernel"], 'compare' => 'LIKE');
			}
		}
		if (isset($_GET["module"]) && $_GET["module"] != "")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["caused-by"], 'value' => 'Module: ' . $_GET["module"], 'compare' => 'LIKE');
		if (isset($_GET["driver"]) && $_GET["driver"] != "")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["caused-by"], 'value' => 'Driver: ' . $_GET["driver"], 'compare' => 'LIKE');
		if (isset($_GET["file"]) && $_GET["file"] != "")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["caused-by"], 'value' => 'File: ' . $_GET["file"], 'compare' => 'LIKE');
		if (isset($_GET["function"]) && $_GET["function"] != "")
			$args['meta_query'][] = array('key' => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["caused-by"], 'value' => 'Function: ' . $_GET["function"], 'compare' => 'LIKE');
	}

	// execute query
	query_posts($args);
?>

<div id="content-archive" class="<?php echo implode(' ', responsive_get_content_classes()); ?>">
	<?php if (function_exists('tw_pagination')) tw_pagination(); ?>

	<?php if (have_posts()): ?>
		<table id="oops-list">
			<tr id="oops-list-head">
				<th><a href="<?php echo $sort["bugline"]; ?>">Bugline</a></th>
				<th><a href="<?php echo $sort["kernel"]; ?>">Kernel</a></th>
				<th><a href="<?php echo $sort["total-count"]; ?>">Total</a></th>
				<th><a href="<?php echo $sort["last-seen"]; ?>">Last</a></th>
			</tr>

			<?php 
			$alternator = False; // row color alternator
			while (have_posts()):
				the_post();
				responsive_entry_before();
				responsive_entry_top();

				$alternator = !$alternator; 
				$odd_even = ($alternator) ? 'odd' : 'even';
			?>
			<tr class="oops-list-data-<?php echo $odd_even; ?>">
				<td><a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>" ><?php the_title(); ?></a></td>
				<?php foreach ($slug_show as $key): 
					$temp = get_post_custom_values($oopscfg["wpcf"]["dbprefix"] . $key);
					if (is_array($temp) && $temp[0] != "")
				?>
				<td><?php echo trim($temp[0]); ?></td>
				<?php endforeach; ?>
			</tr>
			<?php
				responsive_entry_bottom();
				responsive_entry_after();
			endwhile;
			?>
		</table>
	<?php if (function_exists('tw_pagination')) tw_pagination(); else get_template_part('loop-nav'); ?>

	<?php else: ?>
		<h1>Empty output: Try again with other search parameters.</h1>
		<script language="javascript">showFilter();</script>
	<?php endif; ?>

</div><!-- end of #content-archive -->

<?php get_sidebar(); ?>
<?php get_footer(); ?>

