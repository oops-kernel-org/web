<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;
/**
 * Single Template
 *
 * @file           single-oops.php
 * @theme          oops.kernel.org, child of Responsive
 * @author         Petr Oros, Anton Arapov
 * @version        Release: 0.1
 * @filesource     wp-content/themes/responsive-child/single-oops.php
 * @description    kernel oops extension for oops detail
 */
get_header(); ?>

<div id="content" class="<?php echo implode(' ', responsive_get_content_classes()); ?>">

<?php get_template_part('loop-header'); ?>
<?php if (have_posts()): ?>

	<?php 
	while (have_posts()):
		the_post();
        responsive_entry_before();
	?>
	<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<?php responsive_entry_top(); ?>

		<div class="navigation">
			<div class="previous"><?php previous_post_link('&#8249; %link'); ?></div>
			<div class="next"><?php next_post_link('%link &#8250;'); ?></div>
		</div><!-- end of .navigation -->

		<h1 class="entry-title post-title"><?php the_title(); ?></h1>
		<div class="post-entry">

		<?php
		foreach ($oopscfg["wpcf"]["slug-show"] as $item => $name):
			echo '<div class="item-block">';

			$data = types_render_field($item, array("show_name" => True, "separator" => "*"));
			if ($data != "") {
				$datae = explode(": ", $data);
				if ($item == $oopscfg["wpcf"]["slug"]["trace"]) {
					$data = str_replace("Trace: ", "", $data);
					$traces = explode("*", $data);
					$backtrace = "";
					$current = "";
					$count = 0;
					foreach ($traces as $key => $trace) {
						if ($current != "" && !($count % 3)) {
							$backtrace .= '<tr>';
							$backtrace .= $current . "</tr>";
							$current = "";
						}
						$current .= '<td class="trace"><b>Trace ' . ++$count . "</b><br />";
						$current .= '<ul>' . $trace . '</ul>';
						$current .= "</td>";
					}
					if ($current != "")
						$backtrace.= '<tr>' . $current . "</tr>";
					echo '<table id="traces">' . $backtrace . '</table>';
				}
				else {
					if ($item == $oopscfg["wpcf"]["slug"]["total-count"]) {
						if ($cnt = mysql_fetch_array(mysql_query("SELECT COUNT(DISTINCT sha1) FROM raw_data WHERE id IN (" . types_render_field($oopscfg["wpcf"]["slug"]["raw-list"], array("show_name" => False)) . ")")))
							$data .= " (from " . $cnt[0] . " unique sources)";
					}
					echo '<div class="item-head ' . $item . '">' . $name . ":</div>";
					if (strpos($data, "<li>") !== False) {
						if ($item == $oopscfg["wpcf"]["slug"]["disassm"])
							print_r('<div class="item-body ' . $item . '"><pre><ul>' . str_replace($datae[0] . ": ", "", $data) . "</ul></pre></div>");
						else {
							if (preg_match("/[^\/][A-Z][0-9A-Z]{1,}[:]([ ][0-9a-fA-F]{8,16}){4,}/",$data))
								print_r('<div class="item-body ' . $item . ' long"><ul>' . str_replace($datae[0] . ": ", "", $data) . "</ul></div>");
							else
								print_r('<div class="item-body ' . $item . '"><ul>' . str_replace($datae[0] . ": ", "", $data) . "</ul></div>");
						}
					}
					else
						print_r('<div class="item-body ' . $item . '">' . str_replace($datae[0] . ": ", "", $data) . "</div>");
				}
			}

			if ($item == $oopscfg["wpcf"]["slug"]["trace"]) {
				$token = hash_init("sha256");
				hash_update($token, types_render_field($oopscfg["wpcf"]["slug"]["raw-list"], array("show_name" => False)));
				$sec_token = hash_final($token);
				echo '<div id="raw">';
				echo '<a href="#" id="rawAreaControl" onclick="return showRaw(' . $post->ID . ",'" . $sec_token . "')\">Show Original Raws</a>";
				echo '</div>';
			}
			echo "</div>";
		endforeach;

        // show kernels, which contain same bug
		$filter[] = Array(
			"key" => $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["bugline"],
			"value" => types_render_field($oopscfg["wpcf"]["slug"]["bugline"], array("show_name" => False)),
			"compare" => "like",
			"type" => "string");
		$itemlist = getFilteredList($filter);
		$sql = "SELECT DISTINCT meta_value FROM wp_postmeta WHERE meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["kernel"] . "' AND ";
		$sql .= "post_id IN (" . implode(",",$itemlist) . ")";
		$subresult = $wpdb->get_results($sql);
		if (count($subresult) > 1) {
			$tc = Array();
			foreach ($subresult as $val)
				$tc[] = $val->meta_value;
			
			usort($tc, "kcmp");
			echo '<div class="item-block">';
			echo '<div class="item-head">Bug found in this kernels too: </div>';
			echo '<div class="item-body">' . implode(", ", $tc) . '</div>';
			echo '</div>';
		} ?>
		</div><br /><!-- end of .post-entry -->

		<?php responsive_entry_bottom(); ?>
		</div><!-- end of #post-<?php the_ID(); ?> -->
		<?php responsive_entry_after(); ?>
		<?php responsive_comments_before(); ?>
		<?php comments_template('', true); ?>
		<?php responsive_comments_after(); ?>

	<?php
    endwhile;
	get_template_part('loop-nav');
else:
	get_template_part('loop-no-posts');
endif;
?>
</div><!-- end of #content -->

<?php get_sidebar(); ?>
<?php get_footer(); ?>

